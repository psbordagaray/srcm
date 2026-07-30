<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\TechnicalModelKnowledgeManager;
use App\Http\Requests\StoreTechnicalModelRequest;
use App\Http\Requests\UpdateTechnicalModelRequest;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\TechnicalModel;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechnicalModelController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim(
            (string) $request->query('search')
        );

        $technicalModels = TechnicalModel::query()
            ->with([
                'brand',
                'productCategory',
                'knowledgeEntity.entityType',
                'knowledgeIdentifier.identifierType',
            ])
            ->when(
                $search !== '',
                function ($query) use ($search): void {
                    $query->where(
                        function ($subquery) use ($search): void {
                            $subquery
                                ->where(
                                    'code',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'description',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'brand',
                                    fn ($brandQuery) =>
                                        $brandQuery->where(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        )
                                )
                                ->orWhereHas(
                                    'productCategory',
                                    fn ($categoryQuery) =>
                                        $categoryQuery->where(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        )
                                );
                        }
                    );
                }
            )
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view(
            'technical-models.index',
            compact('technicalModels', 'search')
        );
    }

    public function create(): View
    {
        return view('technical-models.create', [
            'brands' => Brand::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),

            'categories' => ProductCategory::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(
        StoreTechnicalModelRequest $request,
        TechnicalModelKnowledgeManager $manager
    ): RedirectResponse {
        try {
            $technicalModel = $manager->create(
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'code' => $exception->getMessage(),
                ])
                ->withInput();
        }

        return redirect()
            ->route('entities.show', [
                'entity' =>
                    $technicalModel->knowledgeEntity->uuid,
            ])
            ->with(
                'success',
                'Modelo técnico creado y ficha de conocimiento preparada. Ya podés registrar compatibilidades.'
            );
    }

    public function show(
        TechnicalModel $technicalModel
    ): RedirectResponse {
        $technicalModel->load('knowledgeEntity');

        if (! $technicalModel->knowledgeEntity) {
            return redirect()
                ->route('technical-models.index')
                ->with(
                    'error',
                    'El modelo técnico todavía no posee una ficha de conocimiento vinculada.'
                );
        }

        return redirect()->route('entities.show', [
            'entity' =>
                $technicalModel->knowledgeEntity->uuid,
        ]);
    }

    public function edit(
        TechnicalModel $technicalModel
    ): View {
        $technicalModel->load([
            'knowledgeEntity',
            'knowledgeIdentifier',
        ]);

        return view('technical-models.edit', [
            'technicalModel' => $technicalModel,

            'brands' => Brand::query()
                ->where('active', true)
                ->orWhere(
                    'id',
                    $technicalModel->brand_id
                )
                ->orderBy('name')
                ->get(),

            'categories' => ProductCategory::query()
                ->where('active', true)
                ->orWhere(
                    'id',
                    $technicalModel->product_category_id
                )
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(
        UpdateTechnicalModelRequest $request,
        TechnicalModel $technicalModel,
        TechnicalModelKnowledgeManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->update(
                $technicalModel,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withErrors([
                    'code' => $exception->getMessage(),
                ])
                ->withInput();
        }

        return redirect()
            ->route('entities.show', [
                'entity' => $updated->knowledgeEntity->uuid,
            ])
            ->with(
                'success',
                'Modelo técnico y ficha de conocimiento actualizados correctamente.'
            );
    }

    public function toggleActive(
        TechnicalModel $technicalModel,
        TechnicalModelKnowledgeManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->toggleActive(
                $technicalModel
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'technical_model_action' =>
                    $exception->getMessage(),
            ]);
        }

        $message = $updated->active
            ? 'Modelo técnico y ficha activados correctamente.'
            : 'Modelo técnico y ficha inactivados correctamente.';

        return redirect()
            ->route('technical-models.index')
            ->with('success', $message);
    }

    public function destroy(
        TechnicalModel $technicalModel
    ): never {
        abort(404);
    }
}
