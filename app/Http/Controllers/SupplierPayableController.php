<?php

namespace App\Http\Controllers;

use App\Domain\Purchase\SupplierPayableAgingReader;
use App\Domain\Purchase\SupplierPayableStatementReader;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierPayableController extends Controller
{
    public function index(
        Request $request,
        SupplierPayableAgingReader $reader
    ): View {
        return view('suppliers.payables-aging', [
            'report' => $reader->report(
                $request->user()
            ),
        ]);
    }

    public function show(
        Request $request,
        Supplier $supplier,
        CurrentOrganization $currentOrganization,
        SupplierPayableStatementReader $reader
    ): View {
        abort_unless(
            (int) $supplier->organization_id
                === $currentOrganization->id(
                    $request->user()
                ),
            404
        );

        $supplier->loadMissing('party');

        return view('suppliers.account', [
            'supplier' => $supplier,
            'statement' => $reader->read(
                $supplier,
                $request->user()
            ),
        ]);
    }
}
