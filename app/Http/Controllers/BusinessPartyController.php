<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\BusinessPartyIdentityManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\StoreBusinessPartyRequest;
use App\Http\Requests\UpdateBusinessPartyRequest;
use App\Models\BusinessParty;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessPartyController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );
        $search = trim((string) $request->query('search'));
        $type = (string) $request->query('type');
        $role = (string) $request->query('role');

        if (! in_array(
            $type,
            [
                '',
                BusinessParty::TYPE_PERSON,
                BusinessParty::TYPE_ORGANIZATION,
            ],
            true
        )) {
            $type = '';
        }

        if (! in_array(
            $role,
            ['', 'customer', 'supplier', 'both', 'unassigned'],
            true
        )) {
            $role = '';
        }

        $normalizedName = BusinessParty::normalizeName($search);
        $normalizedTaxId = BusinessParty::normalizeTaxId($search);

        $parties = BusinessParty::query()
            ->forOrganization($organizationId)
            ->with(['customer', 'supplier'])
            ->when(
                $search !== '',
                function (Builder $query) use (
                    $search,
                    $normalizedName,
                    $normalizedTaxId
                ): void {
                    $query->where(
                        function (Builder $identity) use (
                            $search,
                            $normalizedName,
                            $normalizedTaxId
                        ): void {
                            $identity
                                ->where('name', 'like', "%{$search}%")
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
                                );
                        }
                    );
                }
            )
            ->when(
                $type !== '',
                fn (Builder $query) => $query->where(
                    'party_type',
                    $type
                )
            )
            ->when(
                $role === 'customer',
                fn (Builder $query) => $query
                    ->whereHas('customer')
            )
            ->when(
                $role === 'supplier',
                fn (Builder $query) => $query
                    ->whereHas('supplier')
            )
            ->when(
                $role === 'both',
                fn (Builder $query) => $query
                    ->whereHas('customer')
                    ->whereHas('supplier')
            )
            ->when(
                $role === 'unassigned',
                fn (Builder $query) => $query
                    ->whereDoesntHave('customer')
                    ->whereDoesntHave('supplier')
            )
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view(
            'business-parties.index',
            compact('parties', 'search', 'type', 'role')
        );
    }

    public function create(): View
    {
        return view('business-parties.create');
    }

    public function store(
        StoreBusinessPartyRequest $request,
        BusinessPartyIdentityManager $manager
    ): RedirectResponse {
        try {
            $party = $manager->create($request->validated());
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'name' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('business-parties.show', $party)
            ->with(
                'success',
                'Identidad comercial registrada en la organización activa.'
            );
    }

    public function show(
        int $businessParty,
        CurrentOrganization $currentOrganization
    ): View {
        $party = $this->party(
            $businessParty,
            $currentOrganization->id()
        );

        $party->loadMissing(['customer', 'supplier']);

        $salesQuery = $party->commerceSales();
        $customerOrdersQuery = $party->serviceOrdersAsCustomer();
        $ownerOrdersQuery = $party->serviceOrdersAsOwner();

        return view('business-parties.show', [
            'party' => $party,
            'sales' => (clone $salesQuery)
                ->latest('sold_at')
                ->latest('id')
                ->limit(10)
                ->get(),
            'customerOrders' => (clone $customerOrdersQuery)
                ->latest('received_at')
                ->latest('id')
                ->limit(10)
                ->get(),
            'ownerOrders' => (clone $ownerOrdersQuery)
                ->latest('received_at')
                ->latest('id')
                ->limit(10)
                ->get(),
            'salesCount' => $salesQuery->count(),
            'customerOrderCount' => $customerOrdersQuery->count(),
            'ownerOrderCount' => $ownerOrdersQuery->count(),
            'providerWorkCount' => $party
                ->serviceWorkItemsAsProvider()
                ->count(),
            'deliveryRecipientCount' => $party
                ->receivedServiceDeliveries()
                ->count(),
            'cancellationRequesterCount' => $party
                ->requestedServiceCancellations()
                ->count(),
            'cancellationRecipientCount' => $party
                ->receivedServiceCancellationReturns()
                ->count(),
        ]);
    }

    public function edit(
        int $businessParty,
        CurrentOrganization $currentOrganization
    ): View {
        $party = $this->party(
            $businessParty,
            $currentOrganization->id()
        );

        return view(
            'business-parties.edit',
            compact('party')
        );
    }

    public function update(
        UpdateBusinessPartyRequest $request,
        int $businessParty,
        BusinessPartyIdentityManager $manager,
        CurrentOrganization $currentOrganization
    ): RedirectResponse {
        $party = $this->party(
            $businessParty,
            $currentOrganization->id()
        );

        try {
            $updated = $manager->update(
                $party,
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
            ->route('business-parties.show', $updated)
            ->with(
                'success',
                'Identidad comercial actualizada correctamente.'
            );
    }

    private function party(
        int $partyId,
        int $organizationId
    ): BusinessParty {
        return BusinessParty::query()
            ->forOrganization($organizationId)
            ->whereKey($partyId)
            ->firstOrFail();
    }
}
