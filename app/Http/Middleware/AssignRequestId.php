<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $requestId = strtolower((string) Str::uuid());
        $incomingCorrelation = trim(
            (string) $request->header('X-Correlation-ID', '')
        );
        $correlationId = Str::isUuid($incomingCorrelation)
            ? strtolower($incomingCorrelation)
            : $requestId;

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);

        // Long-running runtimes must never leak context between requests.
        Log::withoutContext();
        Log::flushSharedContext();
        Log::shareContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
