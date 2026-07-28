<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductCategoryRequest;
use App\Http\Requests\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $categories = ProductCategory::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subquery) use ($search) {
                    $subquery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('product-categories.index', compact('categories', 'search'));
    }

    public function create()
    {
        return view('product-categories.create');
    }

    public function store(StoreProductCategoryRequest $request)
    {
        ProductCategory::create($request->validated());

        return redirect()
            ->route('product-categories.index')
            ->with('success', 'Categoría creada correctamente.');
    }

    public function show(ProductCategory $productCategory)
    {
        return redirect()->route('product-categories.edit', $productCategory);
    }

    public function edit(ProductCategory $productCategory)
    {
        return view('product-categories.edit', [
            'category' => $productCategory,
        ]);
    }

    public function update(
        UpdateProductCategoryRequest $request,
        ProductCategory $productCategory
    ) {
        $productCategory->update($request->validated());

        return redirect()
            ->route('product-categories.index')
            ->with('success', 'Categoría actualizada correctamente.');
    }

    public function toggleActive(ProductCategory $productCategory)
    {
        $productCategory->update([
            'active' => ! $productCategory->active,
        ]);

        $message = $productCategory->active
            ? 'Categoría activada correctamente.'
            : 'Categoría inactivada correctamente.';

        return redirect()
            ->route('product-categories.index')
            ->with('success', $message);
    }

    public function destroy(ProductCategory $productCategory)
    {
        //
    }
}