<?php

namespace App\Http\Controllers;

use App\Domain\Device\OperationalDeviceBrowserBindingResolver;
use App\Domain\Device\OperationalDeviceReadModelSnapshotBuilder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalDeviceReadModelSnapshotController extends Controller
{
    public function show(
        Request $request,
        OperationalDeviceBrowserBindingResolver $resolver,
        OperationalDeviceReadModelSnapshotBuilder $builder
    ): JsonResponse {
        $binding = $resolver->resolve(
            $request,
            $request->user()
        );

        if (! $binding) {
            abort(403);
        }

        try {
            $payload = $builder->build(
                $request->user(),
                $binding
            );
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        return response()
            ->json($payload)
            ->header(
                'Cache-Control',
                'no-store, private'
            )
            ->header('Vary', 'Cookie');
    }
}
