<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CustomerReceivableAgingReader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerAgingController extends Controller
{
    public function index(
        Request $request,
        CustomerReceivableAgingReader $reader
    ): View {
        return view('customers.aging', [
            'report' => $reader->report(
                $request->user()
            ),
        ]);
    }
}
