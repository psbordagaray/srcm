<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\SupplierManager;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\BusinessParty;
use App\Models\Supplier;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $type = (string) $request->query('type');
        $status = (string) $request->query('status');

        if (! in_array(
            $type,
            ['', BusinessParty::TYPE_PERSON, BusinessParty::TYPE_ORGANIZATION],
            true
        )) {
            $type = '';
        }

        if (! in_array($status, ['', 'active', 'inactive'], true)) {
            $status = '';
        }

        $normalizedName = BusinessParty::normalizeName($search);
        $normalizedTaxId = BusinessParty::normalizeTaxId($search);

        $suppliers = Supplier::query()
            ->with('party')
            ->when(
                $search !== '',
                function (Builder $query) use (
                    $search,
                    $normalizedName,
                    $normalizedTaxId
                ): void {
                    $query->where(function (Builder $supplierQuery) use (
                        $search,
                        $normalizedName,
                        $normalizedTaxId
                    ): void {
                        $supplierQuery
                            ->where(
                                'notes',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhereHas(
                                'party',
                                function (Builder $partyQuery) use (
                                    $search,
                                    $normalizedName,
                                    $normalizedTaxId
                                ): void {
                                    $partyQuery
                                        ->where(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'normalized_name',
                                            'like',
                                            "%{$normalizedName}%"
                                        )
                                        ->orWhere(
                                            'tax_id',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'normalized_tax_id',
                                            'like',
                                            "%{$normalizedTaxId}%"
                                        )
                                        ->orWhere(
                                            'email',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'phone',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'website',
                                            'like',
                                            "%{$search}%"
                                        );
                                }
                            );
                    });
                }
            )
            ->when(
                $type !== '',
                fn (Builder $query) => $query->whereHas(
                    'party',
                    fn (Builder $partyQuery) => $partyQuery
                        ->where('party_type', $type)
                )
            )
            ->when(
                $status === 'active',
                fn (Builder $query) => $query->where('active', true)
            )
            ->when(
                $status === 'inactive',
                fn (Builder $query) => $query->where('active', false)
            )
            ->orderByDesc('active')
            ->orderBy(
                BusinessParty::query()
                    ->select('name')
                    ->whereColumn(
                        'business_parties.id',
                        'suppliers.business_party_id'
                    )
                    ->limit(1)
            )
            ->paginate(20)
            ->withQueryString();

        return view(
            'suppliers.index',
            compact(
                'suppliers',
                'search',
                'type',
                'status'
            )
        );
    }

    public function create(): View
    {
        return view('suppliers.create');
    }

    public function store(
        StoreSupplierRequest $request,
        SupplierManager $manager
    ): RedirectResponse {
        try {
            $supplier = $manager->create($request->validated());
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'name' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with(
                'success',
                'Proveedor registrado sin duplicar su identidad comercial.'
            );
    }

    public function show(Supplier $supplier): View
    {
        $supplier->loadMissing([
            'party',
            'offers' => fn ($query) => $query
                ->with('product')
                ->orderByDesc('active')
                ->orderByDesc('checked_at')
                ->limit(10),
        ]);

        $supplier->loadCount('offers');

        return view(
            'suppliers.show',
            compact('supplier')
        );
    }

    public function edit(Supplier $supplier): View
    {
        $supplier->loadMissing('party');

        return view(
            'suppliers.edit',
            compact('supplier')
        );
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier,
        SupplierManager $manager
    ): RedirectResponse {
        try {
            $updated = $manager->update(
                $supplier,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'name' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('suppliers.show', $updated)
            ->with(
                'success',
                'Proveedor actualizado correctamente.'
            );
    }

    public function toggleActive(
        Supplier $supplier,
        SupplierManager $manager
    ): RedirectResponse {
        $updated = $manager->toggleActive($supplier);

        return redirect()
            ->route('suppliers.index')
            ->with(
                'success',
                $updated->active
                    ? 'Proveedor activado.'
                    : 'Proveedor inactivado.'
            );
    }
}
