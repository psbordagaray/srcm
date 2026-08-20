<?php

use App\Http\Middleware\AddProductionSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceProductionSecurityBaseline;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->append(EnforceProductionSecurityBaseline::class);
        $middleware->append(AddProductionSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(function (): array {
            if (! app()->bound('request')) {
                return [];
            }

            $request = app('request');
            if (! $request instanceof Request) {
                return [];
            }

            $context = [];
            foreach (['request_id', 'correlation_id'] as $key) {
                $value = $request->attributes->get($key);
                if (is_string($value) && $value !== '') {
                    $context[$key] = $value;
                }
            }

            return $context;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
