<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTechnicalModelRequest;
use App\Http\Requests\UpdateTechnicalModelRequest;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\TechnicalModel;
use Illuminate\Http\Request;

class TechnicalModelController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $technicalModels = TechnicalModel::query()
            ->with(['brand', 'productCategory'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subquery) use ($search) {
                    $subquery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('brand', function ($brandQuery) use ($search) {
                            $brandQuery->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('productCategory', function ($categoryQuery) use ($search) {
                            $categoryQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('technical-models.index', compact('technicalModels', 'search'));
    }

    public function create()
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

    public function store(StoreTechnicalModelRequest $request)
    {
        TechnicalModel::create($request->validated());

        return redirect()
            ->route('technical-models.index')
            ->with('success', 'Modelo técnico creado correctamente.');
    }

    public function show(TechnicalModel $technicalModel)
    {
        return redirect()->route('technical-models.edit', $technicalModel);
    }

    public function edit(TechnicalModel $technicalModel)
    {
        return view('technical-models.edit', [
            'technicalModel' => $technicalModel,

            'brands' => Brand::query()
                ->where('active', true)
                ->orWhere('id', $technicalModel->brand_id)
                ->orderBy('name')
                ->get(),

            'categories' => ProductCategory::query()
                ->where('active', true)
                ->orWhere('id', $technicalModel->product_category_id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(
        UpdateTechnicalModelRequest $request,
        TechnicalModel $technicalModel
    ) {
        $technicalModel->update($request->validated());

        return redirect()
            ->route('technical-models.index')
            ->with('success', 'Modelo técnico actualizado correctamente.');
    }

    public function toggleActive(TechnicalModel $technicalModel)
    {
        $technicalModel->update([
            'active' => ! $technicalModel->active,
        ]);

        $message = $technicalModel->active
            ? 'Modelo técnico activado correctamente.'
            : 'Modelo técnico inactivado correctamente.';

        return redirect()
            ->route('technical-models.index')
            ->with('success', $message);
    }

    public function destroy(TechnicalModel $technicalModel)
    {
        abort(404);
    }
}
