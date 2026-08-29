<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use App\Contracts\Offline\RestrictedOfflineSignedGrantCredentialMaterialExtractor;
use App\Contracts\Offline\RestrictedOfflineSignedGrantSigningKeyProvider;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\OperationalDeviceCapability;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Laravel\Passkeys\Passkey;
use Illuminate\Support\Str;

final class RestrictedOfflineSignedGrantIssuanceService
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly RestrictedOfflineSignedGrantSigningKeyProvider $signingKeys,
        private readonly RestrictedOfflineSignedGrantCredentialMaterialExtractor $credentialMaterial,
        private readonly AuditRecorder $auditRecorder,
    ) {
    }

    /** @param list<string> $requestedCapabilities */
    public function issue(
        User $actor,
        OperationalDeviceBrowserBinding $binding,
        Passkey $passkey,
        array $requestedCapabilities,
        ?int $requestedTtlSeconds = null,
    ): RestrictedOfflineSignedGrantIssue {
        $organization = $this->currentOrganization->get($actor);
        $membership = $this->currentOrganization->membershipFor(
            $actor,
            $organization
        );

        if (
            ! $membership
            || ! $membership->role->canRecordCommerceSale()
        ) {
            throw new DomainException(
                'The current membership cannot receive restricted offline authority.'
            );
        }

        if (
            (int) $binding->organization_id !== (int) $organization->getKey()
            || ! $binding->isUsable()
        ) {
            throw new DomainException(
                'The operational browser binding is not usable in the current organization.'
            );
        }

        $binding->loadMissing('device.capabilityGrants');
        $device = $binding->device;

        if (
            ! $device
            || ! $device->active
            || (int) $device->organization_id !== (int) $organization->getKey()
        ) {
            throw new DomainException(
                'The operational device is not active in the current organization.'
            );
        }

        if ((string) $passkey->user_id !== (string) $actor->getKey()) {
            throw new DomainException(
                'The verified passkey does not belong to the authenticated user.'
            );
        }

        $capabilities = RestrictedOfflineSignedGrantContract::normalizeCapabilities(
            $requestedCapabilities
        );
        $allowed = [
            OperationalDeviceCapability::RestrictedOfflineReadModel->value,
        ];

        foreach ($capabilities as $capability) {
            if (! in_array($capability, $allowed, true)) {
                throw new DomainException(
                    'The requested restricted offline capability is not enabled by the issuance policy.'
                );
            }

            $deviceHasCapability = $device->capabilityGrants->contains(
                fn ($grant): bool => $grant->capability->value === $capability
            );

            if (! $deviceHasCapability) {
                throw new DomainException(
                    'The operational device does not hold the requested restricted offline capability.'
                );
            }
        }

        $material = $this->credentialMaterial->extract($passkey);
        $expectedUserHandle = $actor->getPasskeyUserHandle();

        if (! hash_equals($expectedUserHandle, $material->userHandle)) {
            throw new DomainException(
                'The verified passkey user handle does not match the authenticated user.'
            );
        }

        $ttlSeconds = $requestedTtlSeconds
            ?? RestrictedOfflineSignedGrantContract::DEFAULT_TTL_SECONDS;

        if (
            $ttlSeconds < 1
            || $ttlSeconds > RestrictedOfflineSignedGrantContract::HARD_MAX_TTL_SECONDS
        ) {
            throw new DomainException(
                'The requested restricted offline grant TTL is outside policy.'
            );
        }

        $issuedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $bindingExpiresAt = CarbonImmutable::instance(
            $binding->expires_at
        )->utc()->startOfSecond();
        $remainingBindingSeconds =
            $bindingExpiresAt->getTimestamp() - $issuedAt->getTimestamp();

        if ($remainingBindingSeconds < 1) {
            throw new DomainException(
                'The operational browser binding expires too soon for grant issuance.'
            );
        }

        $effectiveTtl = min($ttlSeconds, $remainingBindingSeconds);
        $expiresAt = $issuedAt->addSeconds($effectiveTtl);
        $policyVersion = (string) config(
            'offline.restricted_signed_grant.policy_version',
            'restricted-read-model-v1'
        );
        $key = $this->signingKeys->current();
        $jti = Str::uuid()->toString();

        $claims = new RestrictedOfflineSignedGrantClaims(
            subject: RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                $expectedUserHandle
            ),
            jti: $jti,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            membershipId: (int) $membership->getKey(),
            organizationId: (int) $organization->getKey(),
            devicePublicId: (string) $device->public_id,
            bindingPublicId: (string) $binding->public_id,
            bindingExpiresAt: $bindingExpiresAt,
            capabilities: $capabilities,
            policyVersion: $policyVersion,
            credentialId: $material->credentialId,
            credentialFingerprint: $material->credentialFingerprint,
            confirmationJwk: $material->confirmationJwk,
        );

        $grant = (new RestrictedOfflineSignedGrantIssuer(
            $key->kid,
            $key->secretKey
        ))->issue($claims);

        $this->auditRecorder->record(
            $binding,
            'restricted_offline_signed_grant_issued',
            null,
            [
                'jti' => $jti,
                'kid' => $key->kid,
                'binding_public_id' => (string) $binding->public_id,
                'device_public_id' => (string) $device->public_id,
                'credential_fingerprint' => $material->credentialFingerprint,
                'capabilities' => $capabilities,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
                'policy_version' => $policyVersion,
            ]
        );

        return new RestrictedOfflineSignedGrantIssue(
            grant: $grant,
            expiresAt: $expiresAt,
            kid: $key->kid,
            capabilities: $capabilities,
            policyVersion: $policyVersion,
        );
    }
}
