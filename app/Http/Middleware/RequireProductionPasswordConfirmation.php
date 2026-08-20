<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireProductionPasswordConfirmation
{
    /**
     * Require recent password confirmation only in production. Mutating
     * requests are never replayed after confirmation; the operator must
     * deliberately submit the operation again.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        $confirmedAt = (int) $request->session()->get(
            'auth.password_confirmed_at',
            0
        );
        $timeout = max(1, (int) config('auth.password_timeout', 900));

        if (
            $confirmedAt > 0
            && (time() - $confirmedAt) <= $timeout
        ) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => 'Password confirmation required.',
                'code' => 'step_up_required',
                'confirm_url' => route(
                    'password.confirm',
                    absolute: false
                ),
            ], 423);
        }

        if ($request->isMethodSafe()) {
            return redirect()->guest(
                route('password.confirm', absolute: false)
            );
        }

        // A stale POST/PATCH/PUT/DELETE must not become an automatic replay.
        $request->session()->forget('url.intended');

        return new RedirectResponse(
            route('password.confirm', absolute: false),
            302,
            ['X-SRCM-Step-Up' => 'required']
        );
    }
}
