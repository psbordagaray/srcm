<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EnforceProductionSecurityBaseline
{
    /**
     * Production must fail closed if deployment configuration weakens the
     * minimum session / transport baseline. No secret values are reported.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        $violations = [];

        if (config('app.debug') !== false) {
            $violations[] = 'app.debug';
        }

        $appKey = config('app.key');
        if (! is_string($appKey) || trim($appKey) === '') {
            $violations[] = 'app.key';
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        if (strtolower((string) $scheme) !== 'https') {
            $violations[] = 'app.url';
        }

        if (config('session.secure') !== true) {
            $violations[] = 'session.secure';
        }

        if (config('session.http_only') !== true) {
            $violations[] = 'session.http_only';
        }

        if (config('session.encrypt') !== true) {
            $violations[] = 'session.encrypt';
        }

        if (config('session.serialization') !== 'json') {
            $violations[] = 'session.serialization';
        }

        $sameSite = strtolower((string) config('session.same_site'));
        if (! in_array($sameSite, ['lax', 'strict'], true)) {
            $violations[] = 'session.same_site';
        }

        $passwordTimeout = (int) config('auth.password_timeout', 0);
        if ($passwordTimeout < 60 || $passwordTimeout > 1800) {
            $violations[] = 'auth.password_timeout';
        }

        if ($violations !== []) {
            throw new RuntimeException(
                'Production security baseline failed: '.implode(', ', $violations)
            );
        }

        return $next($request);
    }
}
