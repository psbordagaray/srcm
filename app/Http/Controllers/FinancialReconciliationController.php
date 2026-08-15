<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialReconciliationCenterReader;
use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialReconciliationController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        FinancialReconciliationCenterReader $reader
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        return view('financial-reconciliation.index', [
            'items' => $reader->read($organizationId),
        ]);
    }
}
