<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\KnowledgeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class KnowledgeController extends Controller
{
    public function explorer(): View
    {
        return view('knowledge.explorer');
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