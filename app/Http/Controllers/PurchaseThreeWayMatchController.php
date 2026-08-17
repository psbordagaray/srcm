<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\PurchaseThreeWayMatchReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseThreeWayMatchController extends Controller
{
    public function show(
        Request $request,
        string $purchaseOrder,
        CurrentOrganization $currentOrganization,
        PurchaseThreeWayMatchReader $reader
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $order = PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->where('public_id', $purchaseOrder)
            ->firstOrFail();

        return view(
            'purchases.three-way-match',
            [
                'order' => $order,
                'match' => $reader->read($order),
            ]
        );
    }
}
