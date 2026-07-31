<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\SupplierOfferManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\StoreSupplierOfferRequest;
use App\Http\Requests\UpdateSupplierOfferRequest;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierOfferController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id();
        $search = trim((string) $request->query('search'));
        $availability = (string) $request->query('availability');
        $status = (string) $request->query('status');

        if (
            $availability !== ''
            && ! array_key_exists(
                $availability,
                SupplierOffer::availabilityOptions()
            )
        ) {
            $availability = '';
        }

        if (! in_array($status, ['', 'active', 'inactive'], true)) {
            $status = '';
        }

        $normalizedCode = SupplierOffer::normalizeCode($search);
        $normalizedProduct = CatalogProduct::normalizeIdentity($search);
        $normalizedParty = BusinessParty::normalizeName($search);

        $offers = SupplierOffer::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier.party',
                'product.productCategory',
                'product.brand',
            ])
            ->when(
                $search !== '',
                function (Builder $query) use (
                    $search,
                    $normalizedCode,
                    $normalizedProduct,
                    $normalizedParty
                ): void {
                    $query->where(function (Builder $offerQuery) use (
                        $search,
                        $normalizedCode,
                        $normalizedProduct,
                        $normalizedParty
                    ): void {
                        $offerQuery
                            ->where(
                                'supplier_code',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'normalized_supplier_code',
                                'like',
                                "%{$normalizedCode}%"
                            )
                            ->orWhere(
                                'published_description',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'commercial_terms',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'source_url',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhereHas(
                                'supplier.party',
                                function (Builder $partyQuery) use (
                                    $search,
                                    $normalizedParty
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
                                            "%{$normalizedParty}%"
                                        );
                                }
                            )
                            ->orWhereHas(
                                'product',
                                function (Builder $productQuery) use (
                                    $search,
                                    $normalizedProduct
                                ): void {
                                    $productQuery
                                        ->where(
                                            'sku',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'normalized_sku',
                                            'like',
                                            "%{$normalizedProduct}%"
                                        )
                                        ->orWhere(
                                            'normalized_name',
                                            'like',
                                            "%{$normalizedProduct}%"
                                        );
                                }
                            );
                    });
                }
            )
            ->when(
                $availability !== '',
                fn (Builder $query) => $query->where(
                    'availability_status',
                    $availability
                )
            )
            ->when(
                $status === 'active',
                fn (Builder $query) => $query
                    ->where('active', true)
            )
            ->when(
                $status === 'inactive',
                fn (Builder $query) => $query
                    ->where('active', false)
            )
            ->orderByDesc('active')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('supplier-offers.index', [
            'offers' => $offers,
            'search' => $search,
            'availability' => $availability,
            'status' => $status,
            'availabilityOptions' =>
                SupplierOffer::availabilityOptions(),
        ]);
    }

    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        return view(
            'supplier-offers.create',
            $this->formOptions(
                $currentOrganization->id(),
                supplierId: $request->integer('supplier')
                    ?: null,
                productId: $request->integer('product')
                    ?: null
            )
        );
    }

    public function store(
        StoreSupplierOfferRequest $request,
        SupplierOfferManager $manager
    ): RedirectResponse {
        try {
            $offer = $manager->create(
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'supplier_code' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('supplier-offers.show', $offer)
            ->with(
                'success',
                'Oferta registrada dentro de la organización activa.'
            );
    }

    public function show(
        SupplierOffer $supplierOffer,
        CurrentOrganization $currentOrganization
    ): View {
        $this->assertCurrentOrganization(
            $supplierOffer,
            $currentOrganization
        );

        $supplierOffer->loadMissing([
            'supplier.party',
            'product.productCategory',
            'product.brand',
            'product.manufacturer',
        ]);

        return view(
            'supplier-offers.show',
            ['offer' => $supplierOffer]
        );
    }

    public function edit(
        SupplierOffer $supplierOffer,
        CurrentOrganization $currentOrganization
    ): View {
        $this->assertCurrentOrganization(
            $supplierOffer,
            $currentOrganization
        );

        $supplierOffer->loadMissing([
            'supplier.party',
            'product',
        ]);

        return view('supplier-offers.edit', [
            'offer' => $supplierOffer,
            ...$this->formOptions(
                $currentOrganization->id(),
                $supplierOffer,
                $supplierOffer->supplier_id,
                $supplierOffer->catalog_product_id
            ),
        ]);
    }

    public function update(
        UpdateSupplierOfferRequest $request,
        SupplierOffer $supplierOffer,
        SupplierOfferManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $this->assertCurrentOrganization(
            $supplierOffer,
            $currentOrganization
        );

        try {
            $updated = $manager->update(
                $supplierOffer,
                $request->validated()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'supplier_code' =>
                        $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('supplier-offers.show', $updated)
            ->with(
                'success',
                'Oferta de proveedor actualizada.'
            );
    }

    public function toggleActive(
        SupplierOffer $supplierOffer,
        SupplierOfferManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $this->assertCurrentOrganization(
            $supplierOffer,
            $currentOrganization
        );

        try {
            $updated = $manager->toggleActive(
                $supplierOffer
            );
        } catch (DomainException $exception) {
            return back()->with(
                'error',
                $exception->getMessage()
            );
        }

        return back()->with(
            'success',
            $updated->active
                ? 'Oferta activada.'
                : 'Oferta inactivada.'
        );
    }

    private function formOptions(
        int $organizationId,
        ?SupplierOffer $offer = null,
        ?int $supplierId = null,
        ?int $productId = null
    ): array {
        $suppliers = Supplier::query()
            ->forOrganization($organizationId)
            ->with('party')
            ->where(function (Builder $query) use (
                $offer
            ): void {
                $query->where('active', true);

                if ($offer) {
                    $query->orWhereKey(
                        $offer->supplier_id
                    );
                }
            })
            ->orderBy(
                BusinessParty::query()
                    ->select('name')
                    ->whereColumn(
                        'business_parties.id',
                        'suppliers.business_party_id'
                    )
                    ->limit(1)
            )
            ->get();

        $products = CatalogProduct::query()
            ->with(['brand', 'productCategory'])
            ->where(function (Builder $query) use (
                $offer
            ): void {
                $query->where('active', true);

                if ($offer) {
                    $query->orWhereKey(
                        $offer->catalog_product_id
                    );
                }
            })
            ->orderBy('name')
            ->get();

        return [
            'suppliers' => $suppliers,
            'products' => $products,
            'availabilityOptions' =>
                SupplierOffer::availabilityOptions(),
            'selectedSupplierId' =>
                $supplierId ?? $offer?->supplier_id,
            'selectedProductId' =>
                $productId
                    ?? $offer?->catalog_product_id,
        ];
    }

    private function assertCurrentOrganization(
        SupplierOffer $offer,
        CurrentOrganization $currentOrganization
    ): void {
        abort_unless(
            (int) $offer->organization_id
                === $currentOrganization->id(),
            404
        );
    }
}
