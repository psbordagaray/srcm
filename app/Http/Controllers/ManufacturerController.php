<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManufacturerRequest;
use App\Http\Requests\UpdateManufacturerRequest;
use App\Models\Manufacturer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManufacturerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim(
            (string) $request->query('search')
        );

        $manufacturers = Manufacturer::query()
            ->when(
                $search !== '',
                function ($query) use ($search): void {
                    $query->where(
                        function ($subquery) use ($search): void {
                            $subquery
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'website',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'description',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view(
            'manufacturers.index',
            compact('manufacturers', 'search')
        );
    }

    public function create(): View
    {
        return view('manufacturers.create');
    }

    public function store(
        StoreManufacturerRequest $request
    ): RedirectResponse {
        Manufacturer::query()->create(
            $request->validated()
        );

        return redirect()
            ->route('manufacturers.index')
            ->with(
                'success',
                'Fabricante creado correctamente.'
            );
    }

    public function show(
        Manufacturer $manufacturer
    ): RedirectResponse {
        return redirect()->route(
            'manufacturers.edit',
            $manufacturer
        );
    }

    public function edit(
        Manufacturer $manufacturer
    ): View {
        return view(
            'manufacturers.edit',
            compact('manufacturer')
        );
    }

    public function update(
        UpdateManufacturerRequest $request,
        Manufacturer $manufacturer
    ): RedirectResponse {
        $manufacturer->update(
            $request->validated()
        );

        return redirect()
            ->route('manufacturers.index')
            ->with(
                'success',
                'Fabricante actualizado correctamente.'
            );
    }

    public function toggleActive(
        Manufacturer $manufacturer
    ): RedirectResponse {
        $manufacturer->update([
            'active' => ! $manufacturer->active,
        ]);

        $message = $manufacturer->active
            ? 'Fabricante activado correctamente.'
            : 'Fabricante inactivado correctamente.';

        return redirect()
            ->route('manufacturers.index')
            ->with('success', $message);
    }

    public function destroy(
        Manufacturer $manufacturer
    ): never {
        abort(404);
    }
}
