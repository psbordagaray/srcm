<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Offline\RestrictedOfflineSignedGrantSigningKeyProvider;
use App\Domain\Device\OperationalDeviceBrowserBindingResolver;
use App\Domain\Offline\RestrictedOfflineSignedGrantContract;
use App\Domain\Offline\RestrictedOfflineSignedGrantIssuanceService;
use App\Domain\Offline\RestrictedOfflineSignedGrantSigningKeyUnavailable;
use App\Http\Requests\RestrictedOfflineSignedGrantIssueRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Support\WebAuthn;

final class RestrictedOfflineSignedGrantController extends Controller
{
    public function options(
        Request $request,
        OperationalDeviceBrowserBindingResolver $bindingResolver,
        RestrictedOfflineSignedGrantSigningKeyProvider $signingKeys,
        GenerateVerificationOptions $generateVerificationOptions,
    ): JsonResponse {
        $binding = $bindingResolver->resolve(
            $request,
            $request->user()
        );

        if (! $binding) {
            abort(403);
        }

        $user = $request->user();
        if (
            ! $user instanceof PasskeyUser
            || ! method_exists($user, 'hasPasskeysEnabled')
            || ! $user->hasPasskeysEnabled()
        ) {
            abort(403);
        }

        try {
            $signingKeys->current();
        } catch (RestrictedOfflineSignedGrantSigningKeyUnavailable) {
            abort(503, 'Restricted offline signed grant issuance is not provisioned.');
        }

        $options = $generateVerificationOptions($user);
        $request->session()->put(
            RestrictedOfflineSignedGrantIssueRequest::SESSION_KEY,
            [
                'serialized' => WebAuthn::toJson($options),
                'issued_at' => time(),
                'binding_public_id' => (string) $binding->public_id,
                'organization_id' => (int) $binding->organization_id,
                'user_id' => (string) $user->getAuthIdentifier(),
            ]
        );

        return $this->privateJson([
            'options' => WebAuthn::toBrowserArray($options),
            'max_age_seconds' =>
                RestrictedOfflineSignedGrantContract::PASSKEY_CONFIRMATION_MAX_AGE_SECONDS,
        ]);
    }

    public function issue(
        RestrictedOfflineSignedGrantIssueRequest $request,
        OperationalDeviceBrowserBindingResolver $bindingResolver,
        VerifyPasskey $verifyPasskey,
        RestrictedOfflineSignedGrantIssuanceService $issuance,
    ): JsonResponse {
        $binding = $bindingResolver->resolve(
            $request,
            $request->user()
        );

        if (! $binding) {
            abort(403);
        }

        $options = $request->verificationOptionsFor(
            (string) $binding->public_id,
            $request->user()->getAuthIdentifier(),
            (int) $binding->organization_id,
        );
        $passkey = $verifyPasskey(
            $request->credential(),
            $options,
            $request->user()
        );

        try {
            $issued = $issuance->issue(
                $request->user(),
                $binding,
                $passkey,
                $request->requestedCapabilities(),
                $request->requestedTtlSeconds(),
            );
        } catch (RestrictedOfflineSignedGrantSigningKeyUnavailable) {
            abort(503, 'Restricted offline signed grant issuance is not provisioned.');
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        return $this->privateJson([
            'grant' => $issued->grant,
            'expires_at' => $issued->expiresAt->format(DATE_ATOM),
            'kid' => $issued->kid,
            'capabilities' => $issued->capabilities,
            'policy_version' => $issued->policyVersion,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'Cookie');
    }
}
