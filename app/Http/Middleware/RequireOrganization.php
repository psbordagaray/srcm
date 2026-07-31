<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        abort_unless(
            $request->user()
                && $this->currentOrganization->getOrNull(
                    $request->user()
                ),
            403,
            'No posee una organización activa en SRCM.'
        );

        return $next($request);
    }
}
