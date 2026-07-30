<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\CreateEntityWithInitialIdentifier;
use App\Http\Requests\StoreEntityRequest;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\IdentifierType;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EntityController extends Controller
{
    public function create(): View
    {
        return view('entities.create', [
            'entityTypes' => EntityType::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),

            'identifierTypes' => IdentifierType::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(
        StoreEntityRequest $request,
        CreateEntityWithInitialIdentifier $creator
    ): RedirectResponse {
        try {
            $entity = $creator->execute($request->validated());
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'identifier_value' => $exception->getMessage(),
                ])
                ->withInput();
        }

        $initialIdentifier = $entity->identifiers->first();

        return redirect()
            ->route('knowledge.explorer', [
                'query' => $initialIdentifier?->value,
            ])
            ->with(
                'success',
                'Entidad creada correctamente y disponible en el Explorador.'
            );
    }

    public function show(Entity $entity): View
    {
        $entity->load([
            'entityType',
            'identifiers' => function ($query): void {
                $query
                    ->with('identifierType')
                    ->orderByDesc('active')
                    ->orderByDesc('is_primary')
                    ->orderBy('id');
            },
            'outgoingCompatibilities' => function ($query): void {
                $query
                    ->where('active', true)
                    ->with('rightEntity.entityType')
                    ->orderBy('id');
            },
            'incomingCompatibilities' => function ($query): void {
                $query
                    ->where('active', true)
                    ->with('leftEntity.entityType')
                    ->orderBy('id');
            },
        ]);

        return view('entities.show', [
            'entity' => $entity,
            'identifierTypes' => IdentifierType::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
