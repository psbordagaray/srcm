<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddProductionSecurityHeaders
{
    /**
     * Apply conservative headers that are safe for the current HTML/API
     * surfaces without introducing a CSP policy before its own inventory.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (
            app()->environment('production')
            && (
                $request->isSecure()
                || str_starts_with(
                    strtolower((string) config('app.url')),
                    'https://'
                )
            )
            && ! $response->headers->has('Strict-Transport-Security')
        ) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
