<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Offline\RestrictedOfflineTrustedPublicKeyringProvider;
use App\Domain\Device\OperationalDeviceBrowserBindingResolver;
use App\Domain\Offline\RestrictedOfflineSignedGrantContract;
use App\Domain\Offline\RestrictedOfflineTrustedPublicKeyringUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RestrictedOfflineTrustedPublicKeyringController extends Controller
{
    public const BUNDLE_VERSION = 1;
    public const MAX_VALIDITY_SECONDS =
        RestrictedOfflineSignedGrantContract::HARD_MAX_TTL_SECONDS
        + RestrictedOfflineSignedGrantContract::CLOCK_SKEW_SECONDS;

    public function show(
        Request $request,
        OperationalDeviceBrowserBindingResolver $bindingResolver,
        RestrictedOfflineTrustedPublicKeyringProvider $keyrings,
    ): JsonResponse {
        $binding = $bindingResolver->resolve(
            $request,
            $request->user()
        );

        if (! $binding) {
            abort(403);
        }

        try {
            $keyring = $keyrings->current();
        } catch (RestrictedOfflineTrustedPublicKeyringUnavailable) {
            abort(503, 'Restricted offline trusted public keyring is not provisioned.');
        }

        $binding->loadMissing('device');
        $device = $binding->device;

        if (! $device || ! $device->active) {
            abort(403);
        }

        $issuedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $bindingExpiresAt = CarbonImmutable::instance(
            $binding->expires_at
        )->utc()->startOfSecond();
        $maximumExpiresAt = $issuedAt->addSeconds(
            self::MAX_VALIDITY_SECONDS
        );
        $expiresAt = $maximumExpiresAt->lessThan($bindingExpiresAt)
            ? $maximumExpiresAt
            : $bindingExpiresAt;

        if ($expiresAt->lessThanOrEqualTo($issuedAt)) {
            abort(403);
        }

        return response()
            ->json([
                'bundle_version' => self::BUNDLE_VERSION,
                'scope' => [
                    'binding_public_id' => (string) $binding->public_id,
                    'device_public_id' => (string) $device->public_id,
                ],
                'server_issued_at' => $issuedAt->format(DATE_ATOM),
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'keyring_version' => $keyring->version,
                'keyring_fingerprint' => $keyring->fingerprint,
                'keys' => $keyring->keys,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'Cookie');
    }
}
