<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $brands = Brand::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subquery) use ($search) {
                    $subquery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('website', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('brands.index', compact('brands', 'search'));
    }

    public function create()
    {
        return view('brands.create');
    }

    public function store(StoreBrandRequest $request)
    {
        Brand::create($request->validated());

        return redirect()
            ->route('brands.index')
            ->with('success', 'Marca creada correctamente.');
    }

    public function show(Brand $brand)
    {
        return redirect()->route('brands.edit', $brand);
    }

    public function edit(Brand $brand)
    {
        return view('brands.edit', compact('brand'));
    }

    public function update(UpdateBrandRequest $request, Brand $brand)
    {
        $brand->update($request->validated());

        return redirect()
            ->route('brands.index')
            ->with('success', 'Marca actualizada correctamente.');
    }

    public function toggleActive(Brand $brand)
    {
        $brand->update([
            'active' => ! $brand->active,
        ]);

        $message = $brand->active
            ? 'Marca activada correctamente.'
            : 'Marca inactivada correctamente.';

        return redirect()
            ->route('brands.index')
            ->with('success', $message);
    }

    public function destroy(Brand $brand)
    {
        //
    }
}