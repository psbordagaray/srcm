<?php

namespace App\Domain\Fiscal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FiscalAuthorizationOutcome;
use App\Models\FiscalAuthorizationAttempt;
use App\Models\FiscalAuthorizationResponse;
use App\Models\FiscalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalAuthorizationFactManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization, private readonly AuditRecorder $audit) {}
    public function record(FiscalAuthorizationFactData $data, User $actor): FiscalAuthorizationAttempt
    {
        $organizationId = $this->organizationId($actor);
        if (blank($data->idempotencyKey)) { throw new DomainException('La clave de idempotencia es obligatoria.'); }
        return DB::transaction(function () use ($data, $actor, $organizationId): FiscalAuthorizationAttempt {
            $document = FiscalDocument::query()->forOrganization($organizationId)->lockForUpdate()->find($data->fiscalDocumentId);
            if (! $document) { throw new DomainException('El documento fiscal no pertenece a la organización activa.'); }
            $fingerprint = hash('sha256', json_encode(['document'=>$document->public_id,'outcome'=>$data->outcome->value,'result_code'=>$data->resultCode], JSON_THROW_ON_ERROR));
            $existing = FiscalAuthorizationAttempt::query()->forOrganization($organizationId)->where('idempotency_key',$data->idempotencyKey)->lockForUpdate()->first();
            if ($existing) { if ($existing->fingerprint !== $fingerprint) { throw new DomainException('La clave de idempotencia ya fue usada con otra evidencia fiscal.'); } return $existing->load('response'); }
            if ($data->outcome === FiscalAuthorizationOutcome::Authorized && $document->authorizationAttempts()->whereHas('response', fn ($query) => $query->where('outcome', FiscalAuthorizationOutcome::Authorized->value))->exists()) { throw new DomainException('El documento ya posee una autorización registrada.'); }
            $number = (int) $document->authorizationAttempts()->lockForUpdate()->max('attempt_number') + 1;
            $attempt = FiscalAuthorizationAttempt::query()->create(['organization_id'=>$organizationId,'fiscal_document_id'=>$document->id,'attempt_number'=>$number,'requested_at'=>CarbonImmutable::now(),'recorded_by_user_id'=>$actor->id,'idempotency_key'=>$data->idempotencyKey,'fingerprint'=>$fingerprint]);
            FiscalAuthorizationResponse::query()->create(['organization_id'=>$organizationId,'fiscal_authorization_attempt_id'=>$attempt->id,'outcome'=>$data->outcome,'result_code'=>$data->resultCode,'received_at'=>CarbonImmutable::now(),'recorded_by_user_id'=>$actor->id]);
            $this->audit->record($attempt,'fiscal_authorization_fact_recorded',null,['fiscal_document_id'=>$document->id,'attempt_number'=>$number,'outcome'=>$data->outcome->value,'result_code'=>$data->resultCode]);
            return $attempt->refresh()->load('response');
        },3);
    }
    private function organizationId(User $actor): int { $id=$this->currentOrganization->id($actor); if (!($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) { throw new DomainException('Sólo un administrador puede registrar evidencia de autorización fiscal.'); } return $id; }
}
