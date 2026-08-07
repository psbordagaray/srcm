<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\DashboardReader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        DashboardReader $reader
    ): View {
        return view(
            'dashboard',
            $reader->read($request->user())
        );
    }
}
