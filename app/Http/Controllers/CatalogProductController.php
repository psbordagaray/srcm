<?php

namespace App\Http\Controllers;

use App\Enums\InventoryBaseUnit;
use App\Domain\Knowledge\CatalogProductKnowledgeManager;
use App\Http\Requests\StoreCatalogProductRequest;
use App\Http\Requests\UpdateCatalogProductRequest;
use App\Models\Brand;
use App\Models\CatalogProduct;
use App\Models\Manufacturer;
use App\Models\ProductCategory;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $products = CatalogProduct::query()
            ->with([
                'productCategory',
                'brand',
                'manufacturer',
                'knowledgeEntity.entityType',
                'knowledgeIdentifier.identifierType',
            ])
            ->when(
                $search !== '',
                function (Builder $query) use ($search): void {
                    $normalized = CatalogProduct::normalizeIdentity($search);

                    $query->where(function (Builder $subquery) use (
                        $search,
                        $normalized
                    ): void {
                        $subquery
                            ->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('normalized_sku', 'like', "%{$normalized}%")
                            ->orWhere('normalized_name', 'like', "%{$normalized}%")
                            ->orWhereHas(
                                'brand',
                                fn (Builder $brandQuery) => $brandQuery
                                    ->where('name', 'like', "%{$search}%")
                            )
                            ->orWhereHas(
                                'manufacturer',
                                fn (Builder $manufacturerQuery) =>
                                    $manufacturerQuery->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                            )
                            ->orWhereHas(
                                'productCategory',
                                fn (Builder $categoryQuery) => $categoryQuery
                                    ->where('name', 'like', "%{$search}%")
                            );
                    });
                }
            )
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('products.index', compact('products', 'search'));
    }

    public function create(): View
    {
        return view('products.create', $this->formOptions());
    }

    public function store(
        StoreCatalogProductRequest $request,
        CatalogProductKnowledgeManager $manager
    ): RedirectResponse {
        try {
            $product = $manager->create($request->validated());
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['sku' => $exception->getMessage()]);
        }

        return redirect()
            ->route(
                'entities.show',
                $product->knowledgeEntity->uuid
            )
            ->with(
                'success',
                'Producto creado y vinculado a su ficha de conocimiento.'
            );
    }

    public function show(CatalogProduct $product): RedirectResponse
    {
        $product->loadMissing('knowledgeEntity');

        if (! $product->knowledgeEntity) {
            return redirect()
                ->route('products.index')
                ->with(
                    'error',
                    'El producto no posee una ficha de conocimiento completa.'
                );
        }

        return redirect()->route(
            'entities.show',
            $product->knowledgeEntity->uuid
        );
    }

    public function edit(CatalogProduct $product): View
    {
        return view('products.edit', [
            'product' => $product,
            ...$this->formOptions($product),
        ]);
    }

    public function update(
        UpdateCatalogProductRequest $request,
        CatalogProduct $product,
        CatalogProductKnowledgeManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->update(
                $product,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['sku' => $exception->getMessage()]);
        }

        return redirect()
            ->route(
                'entities.show',
                $updated->knowledgeEntity->uuid
            )
            ->with(
                'success',
                'Producto y ficha de conocimiento actualizados.'
            );
    }

    public function toggleActive(
        CatalogProduct $product,
        CatalogProductKnowledgeManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->toggleActive($product);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('products.index')
            ->with(
                'success',
                $updated->active
                    ? 'Producto activado.'
                    : 'Producto inactivado.'
            );
    }

    /**
     * @return array{
     *     categories: \Illuminate\Database\Eloquent\Collection,
     *     brands: \Illuminate\Database\Eloquent\Collection,
     *     manufacturers: \Illuminate\Database\Eloquent\Collection
     *     baseUnits: list<InventoryBaseUnit>
     * }
     */
    private function formOptions(?CatalogProduct $product = null): array
    {
        return [
            'baseUnits' => InventoryBaseUnit::cases(),
            'categories' => ProductCategory::query()
                ->where(function (Builder $query) use ($product): void {
                    $query->where('active', true);

                    if ($product) {
                        $query->orWhereKey($product->product_category_id);
                    }
                })
                ->orderBy('name')
                ->get(),
            'brands' => Brand::query()
                ->where(function (Builder $query) use ($product): void {
                    $query->where('active', true);

                    if ($product?->brand_id) {
                        $query->orWhereKey($product->brand_id);
                    }
                })
                ->orderBy('name')
                ->get(),
            'manufacturers' => Manufacturer::query()
                ->where(function (Builder $query) use ($product): void {
                    $query->where('active', true);

                    if ($product?->manufacturer_id) {
                        $query->orWhereKey($product->manufacturer_id);
                    }
                })
                ->orderBy('name')
                ->get(),
        ];
    }
}
