<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\EntityCompatibilityManager;
use App\Http\Requests\StoreCompatibilityRequest;
use App\Models\Compatibility;
use App\Models\Entity;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CompatibilityController extends Controller
{
    public function store(
        StoreCompatibilityRequest $request,
        Entity $entity,
        EntityCompatibilityManager $manager
    ): RedirectResponse {
        try {
            $manager->create(
                $entity,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'related_entity_uuid' =>
                        $exception->getMessage(),
                ])
                ->withInput();
        }

        return $this->redirectToEntity(
            $entity,
            'Compatibilidad guardada correctamente.'
        );
    }

    public function toggleActive(
        Entity $entity,
        Compatibility $compatibility,
        EntityCompatibilityManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->toggleActive(
                $entity,
                $compatibility
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'compatibility_action' =>
                    $exception->getMessage(),
            ]);
        }

        $message = $updated->active
            ? 'Compatibilidad activada correctamente.'
            : 'Compatibilidad inactivada correctamente.';

        return $this->redirectToEntity(
            $entity,
            $message
        );
    }

    private function redirectToEntity(
        Entity $entity,
        string $message
    ): RedirectResponse {
        return redirect()
            ->route('entities.show', [
                'entity' => $entity->uuid,
            ])
            ->with('success', $message);
    }
}
