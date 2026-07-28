<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\KnowledgeEngine;
use Illuminate\Http\JsonResponse;

class KnowledgeController extends Controller
{
    public function show(
        string $query,
        KnowledgeEngine $knowledgeEngine
    ): JsonResponse {
        return response()->json(
            $knowledgeEngine->resolve($query)
        );
    }
}