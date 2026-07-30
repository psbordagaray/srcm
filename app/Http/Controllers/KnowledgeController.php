<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\KnowledgeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeController extends Controller
{
    public function explorer(Request $request): View
    {
        $initialQuery = mb_substr(
            trim((string) $request->query('query')),
            0,
            255
        );

        return view(
            'knowledge.explorer',
            compact('initialQuery')
        );
    }

    public function show(
        string $query,
        KnowledgeEngine $knowledgeEngine
    ): JsonResponse {
        return response()->json(
            $knowledgeEngine->resolve($query)
        );
    }
}
