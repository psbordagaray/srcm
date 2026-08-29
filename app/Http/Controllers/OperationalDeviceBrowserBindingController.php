<?php

namespace App\Http\Controllers;

use App\Domain\Device\OperationalDeviceBrowserBindingManager;
use App\Domain\Device\OperationalDeviceBrowserBindingResolver;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\OperationalDevice;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class OperationalDeviceBrowserBindingController extends Controller
{
    public function show(
        Request $request,
        OperationalDeviceBrowserBindingResolver $resolver
    ): JsonResponse {
        $binding = $resolver->resolve(
            $request,
            $request->user()
        );

        $payload = [
            'runtime_version' => 1,
            'bound' => $binding !== null,
            'device' => null,
            'binding_expires_at' => null,
            'policy' => [
                'offline_final_sale_allowed' => false,
                'offline_payment_finalization_allowed' => false,
                'offline_fiscal_authorization_allowed' => false,
                'silent_price_or_stock_conflict_merge_allowed' => false,
            ],
        ];

        if ($binding) {
            $device = $binding->device;

            $payload['device'] = [
                'public_id' => $device->public_id,
                'label' => $device->label,
                'capabilities' => $device->capabilityGrants
                    ->map(
                        fn ($grant): string =>
                            $grant->capability->value
                    )
                    ->sort()
                    ->values()
                    ->all(),
            ];
            $payload['binding_expires_at'] =
                $binding->expires_at->toAtomString();
        }

        return response()
            ->json($payload)
            ->header(
                'Cache-Control',
                'no-store, private'
            )
            ->header('Vary', 'Cookie');
    }

    public function store(
        Request $request,
        OperationalDevice $operationalDevice,
        CurrentOrganization $currentOrganization,
        OperationalDeviceBrowserBindingManager $manager
    ): RedirectResponse {
        $organization = $currentOrganization->get(
            $request->user()
        );

        abort_unless(
            (int) $operationalDevice->organization_id
                === (int) $organization->getKey(),
            404
        );

        try {
            $issue = $manager->issue(
                $request->user(),
                $operationalDevice
            );
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        $minutes = max(
            1,
            (int) now()->diffInMinutes(
                $issue->binding->expires_at,
                false
            )
        );

        $secure = (bool) config('session.secure')
            || app()->environment('production')
            || $request->isSecure();

        $cookie = Cookie::make(
            OperationalDeviceBrowserBindingManager::COOKIE_NAME,
            $issue->token,
            $minutes,
            '/',
            null,
            $secure,
            true,
            false,
            'strict'
        );

        return redirect()
            ->back()
            ->with(
                'success',
                'Este navegador quedó vinculado al dispositivo operativo.'
            )
            ->withCookie($cookie);
    }

    public function destroy(
        Request $request,
        OperationalDeviceBrowserBindingManager $manager
    ): RedirectResponse {
        try {
            $manager->revokeByToken(
                $request->user(),
                $request->cookie(
                    OperationalDeviceBrowserBindingManager::COOKIE_NAME
                )
            );
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        return redirect()
            ->back()
            ->with(
                'success',
                'El binding de este navegador quedó revocado.'
            )
            ->withCookie(
                Cookie::forget(
                    OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                    '/'
                )
            );
    }
}
