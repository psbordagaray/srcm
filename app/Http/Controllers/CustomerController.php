<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\BusinessParty;
use App\Models\CommerceSale;
use App\Models\Customer;
use App\Models\ServiceOrder;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, CurrentOrganization $currentOrganization): View
    {
        $organizationId = $currentOrganization->id($request->user());
        $search = trim((string) $request->query('search'));
        $type = (string) $request->query('type');
        $status = (string) $request->query('status');

        if (! in_array($type, ['', BusinessParty::TYPE_PERSON, BusinessParty::TYPE_ORGANIZATION], true)) $type = '';
        if (! in_array($status, ['', 'active', 'inactive'], true)) $status = '';

        $normalizedName = BusinessParty::normalizeName($search);
        $normalizedTaxId = BusinessParty::normalizeTaxId($search);

        $customers = Customer::query()->forOrganization($organizationId)->with('party')
            ->when($search !== '', function (Builder $query) use ($search, $normalizedName, $normalizedTaxId): void {
                $query->where(function (Builder $customerQuery) use ($search, $normalizedName, $normalizedTaxId): void {
                    $customerQuery->where('notes','like',"%{$search}%")
                        ->orWhereHas('party', function (Builder $party) use ($search,$normalizedName,$normalizedTaxId): void {
                            $party->where('name','like',"%{$search}%")
                                ->orWhere('normalized_name','like',"%{$normalizedName}%")
                                ->orWhere('tax_id','like',"%{$search}%")
                                ->orWhere('normalized_tax_id','like',"%{$normalizedTaxId}%")
                                ->orWhere('email','like',"%{$search}%")
                                ->orWhere('phone','like',"%{$search}%");
                        });
                });
            })
            ->when($type !== '', fn (Builder $q) => $q->whereHas('party', fn (Builder $p) => $p->where('party_type',$type)))
            ->when($status === 'active', fn (Builder $q) => $q->where('active',true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('active',false))
            ->orderByDesc('active')
            ->orderBy(BusinessParty::query()->select('name')->whereColumn('business_parties.id','customers.business_party_id')->limit(1))
            ->paginate(20)->withQueryString();

        return view('customers.index', compact('customers','search','type','status'));
    }

    public function create(): View { return view('customers.create'); }

    public function store(StoreCustomerRequest $request, CustomerManager $manager): RedirectResponse
    {
        try { $customer = $manager->create($request->validated()); }
        catch (DomainException $e) { return back()->withInput()->withErrors(['name'=>$e->getMessage()]); }

        return redirect()->route('customers.show',$customer)
            ->with('success','Cliente registrado dentro de la organización activa.');
    }

    public function show(Customer $customer, CurrentOrganization $currentOrganization): View
    {
        $organizationId = $currentOrganization->id();
        $this->assertCurrentOrganization($customer,$organizationId);
        $customer->loadMissing('party.supplier');
        $partyId = (int) $customer->business_party_id;

        $salesQuery = CommerceSale::query()->forOrganization($organizationId)
            ->where('customer_business_party_id',$partyId);
        $serviceQuery = ServiceOrder::query()->forOrganization($organizationId)
            ->where('customer_business_party_id',$partyId);
        $ownerQuery = ServiceOrder::query()->forOrganization($organizationId)
            ->where('owner_business_party_id',$partyId);

        return view('customers.show', [
            'customer'=>$customer,
            'sales'=>(clone $salesQuery)->latest('sold_at')->latest('id')->limit(10)->get(),
            'serviceOrders'=>(clone $serviceQuery)->latest('received_at')->latest('id')->limit(10)->get(),
            'ownerOrders'=>(clone $ownerQuery)->latest('received_at')->latest('id')->limit(10)->get(),
            'salesCount'=>$salesQuery->count(),
            'serviceCount'=>$serviceQuery->count(),
            'ownerCount'=>$ownerQuery->count(),
        ]);
    }

    public function edit(Customer $customer, CurrentOrganization $currentOrganization): View
    {
        $this->assertCurrentOrganization($customer,$currentOrganization->id());
        $customer->loadMissing('party');
        return view('customers.edit',compact('customer'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, CustomerManager $manager, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $this->assertCurrentOrganization($customer,$currentOrganization->id());
        try { $updated=$manager->update($customer,$request->validated()); }
        catch (DomainException $e) { return back()->withInput()->withErrors(['name'=>$e->getMessage()]); }
        return redirect()->route('customers.show',$updated)->with('success','Cliente actualizado correctamente.');
    }

    public function toggleActive(Customer $customer, CustomerManager $manager, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $this->assertCurrentOrganization($customer,$currentOrganization->id());
        try { $updated=$manager->toggleActive($customer); }
        catch (DomainException $e) { return back()->with('error',$e->getMessage()); }
        return redirect()->route('customers.index')->with('success',$updated->active?'Cliente activado.':'Cliente inactivado.');
    }

    private function assertCurrentOrganization(Customer $customer, int $organizationId): void
    {
        abort_unless((int) $customer->organization_id === $organizationId,404);
    }
}
