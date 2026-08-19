<?php

namespace App\Domain\Fiscal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FiscalAuthorizationOutcome;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalAuthorizationResponse;
use App\Models\FiscalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalAuthorizationFactManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit,
        private readonly FiscalDocumentAssociationManager $associations,
    ) {}

    public function record(
        FiscalAuthorizationFactData $data,
        User $actor
    ): FiscalAuthorizationAttempt {
        $organizationId = $this->organizationId(
            $actor
        );

        if (blank($data->idempotencyKey)) {
            throw new DomainException(
                'La clave de idempotencia es obligatoria.'
            );
        }

        return DB::transaction(
            function () use (
                $data,
                $actor,
                $organizationId
            ): FiscalAuthorizationAttempt {
                $document = FiscalDocument::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->lockForUpdate()
                    ->find(
                        $data->fiscalDocumentId
                    );

                if (! $document) {
                    throw new DomainException(
                        'El documento fiscal no pertenece a la organización activa.'
                    );
                }

                if (
                    in_array(
                        $document->document_type,
                        [
                            FiscalDocumentType::CreditNote,
                            FiscalDocumentType::DebitNote,
                        ],
                        true
                    )
                ) {
                    $this->associations
                        ->assertCompleteForAuthorization(
                            $document,
                            $organizationId
                        );
                }

                $evidence = $this
                    ->canonicalizeEvidence(
                        $data->providerEvidence
                    );

                $evidenceJson = json_encode(
                    $evidence,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                );

                $evidenceSha = hash(
                    'sha256',
                    $evidenceJson
                );

                $fingerprint = hash(
                    'sha256',
                    json_encode(
                        [
                            'document' =>
                                $document->public_id,
                            'outcome' =>
                                $data->outcome->value,
                            'result_code' =>
                                $data->resultCode,
                            'authorization_code' =>
                                $data->authorizationCode,
                            'authorization_code_expires_on' =>
                                $data->authorizationCodeExpiresOn,
                            'provider_evidence_sha256' =>
                                $evidenceSha,
                        ],
                        JSON_THROW_ON_ERROR
                    )
                );

                $existing =
                    FiscalAuthorizationAttempt::query()
                        ->forOrganization(
                            $organizationId
                        )
                        ->where(
                            'idempotency_key',
                            $data->idempotencyKey
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing) {
                    if (
                        $existing->fingerprint
                        !== $fingerprint
                    ) {
                        throw new DomainException(
                            'La clave de idempotencia ya fue usada con otra evidencia fiscal.'
                        );
                    }

                    return $existing->load(
                        'response'
                    );
                }

                if (
                    $data->outcome
                        === FiscalAuthorizationOutcome::Authorized
                    && $document
                        ->authorizationAttempts()
                        ->whereHas(
                            'response',
                            fn ($query) =>
                                $query->where(
                                    'outcome',
                                    FiscalAuthorizationOutcome::Authorized
                                        ->value
                                )
                        )
                        ->exists()
                ) {
                    throw new DomainException(
                        'El documento ya posee una autorización registrada.'
                    );
                }

                $number = (
                    (int) $document
                        ->authorizationAttempts()
                        ->lockForUpdate()
                        ->max('attempt_number')
                ) + 1;

                $attempt =
                    FiscalAuthorizationAttempt::query()
                        ->create([
                            'organization_id' =>
                                $organizationId,
                            'fiscal_document_id' =>
                                $document->id,
                            'attempt_number' =>
                                $number,
                            'requested_at' =>
                                CarbonImmutable::now(),
                            'recorded_by_user_id' =>
                                $actor->id,
                            'idempotency_key' =>
                                $data->idempotencyKey,
                            'fingerprint' =>
                                $fingerprint,
                        ]);

                FiscalAuthorizationResponse::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'fiscal_authorization_attempt_id' =>
                            $attempt->id,
                        'outcome' =>
                            $data->outcome,
                        'result_code' =>
                            $data->resultCode,
                        'authorization_code' =>
                            $data->authorizationCode,
                        'authorization_code_expires_on' =>
                            $data->authorizationCodeExpiresOn,
                        'provider_evidence' =>
                            $data->providerEvidence === []
                                ? null
                                : $data->providerEvidence,
                        'received_at' =>
                            CarbonImmutable::now(),
                        'recorded_by_user_id' =>
                            $actor->id,
                    ]);

                $this->audit->record(
                    $attempt,
                    'fiscal_authorization_fact_recorded',
                    null,
                    [
                        'fiscal_document_id' =>
                            $document->id,
                        'attempt_number' =>
                            $number,
                        'outcome' =>
                            $data->outcome->value,
                        'result_code' =>
                            $data->resultCode,
                        'authorization_code' =>
                            $data->authorizationCode,
                        'authorization_code_expires_on' =>
                            $data->authorizationCodeExpiresOn,
                        'provider_evidence_sha256' =>
                            $evidenceSha,
                    ]
                );

                return $attempt
                    ->refresh()
                    ->load('response');
            },
            3
        );
    }

    /**
     * @return mixed
     */
    private function canonicalizeEvidence(
        mixed $value
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn ($item) =>
                    $this->canonicalizeEvidence(
                        $item
                    ),
                $value
            );
        }

        ksort($value);

        foreach (
            $value
            as $key => $item
        ) {
            $value[$key] =
                $this->canonicalizeEvidence(
                    $item
                );
        }

        return $value;
    }

    private function organizationId(
        User $actor
    ): int {
        $id = $this->currentOrganization
            ->id($actor);

        if (
            ! (
                $this->currentOrganization
                    ->roleFor($actor)
                    ?->canManageOrganization()
                ?? false
            )
        ) {
            throw new DomainException(
                'Sólo un administrador puede registrar evidencia de autorización fiscal.'
            );
        }

        return $id;
    }
}
