<?php

namespace App\Http\Controllers;

use App\Domain\Search\GlobalSearchReader;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    public function index(
        Request $request,
        GlobalSearchReader $reader
    ): View {
        return view(
            'search.index',
            $reader->read(
                $request->user(),
                $request->query('q')
            )
        );
    }
}
