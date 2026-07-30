<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\EntityIdentifierManager;
use App\Http\Requests\StoreIdentifierRequest;
use App\Models\Entity;
use App\Models\Identifier;
use DomainException;
use Illuminate\Http\RedirectResponse;

class IdentifierController extends Controller
{
    public function store(
        StoreIdentifierRequest $request,
        Entity $entity,
        EntityIdentifierManager $manager
    ): RedirectResponse {
        try {
            $manager->add($entity, $request->validated());
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'identifier_value' => $exception->getMessage(),
                ])
                ->withInput();
        }

        return $this->redirectToEntity(
            $entity,
            'Identificador agregado correctamente.'
        );
    }

    public function makePrimary(
        Entity $entity,
        Identifier $identifier,
        EntityIdentifierManager $manager
    ): RedirectResponse {
        try {
            $manager->makePrimary($entity, $identifier);
        } catch (DomainException $exception) {
            return back()->withErrors([
                'identifier_action' => $exception->getMessage(),
            ]);
        }

        return $this->redirectToEntity(
            $entity,
            'Identificador principal actualizado correctamente.'
        );
    }

    public function toggleActive(
        Entity $entity,
        Identifier $identifier,
        EntityIdentifierManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->toggleActive(
                $entity,
                $identifier
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'identifier_action' => $exception->getMessage(),
            ]);
        }

        $message = $updated->active
            ? 'Identificador activado correctamente.'
            : 'Identificador inactivado correctamente.';

        return $this->redirectToEntity($entity, $message);
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
