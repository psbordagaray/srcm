<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\AddProductionSecurityHeaders;
use App\Http\Middleware\EnforceProductionSecurityBaseline;
use App\Http\Middleware\RequireProductionPasswordConfirmation;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionSecurityBaselineTest extends TestCase
{
    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = $this->app->environment();
    }

    protected function tearDown(): void
    {
        $this->useEnvironment($this->originalEnvironment);
        parent::tearDown();
    }

    public function test_global_headers_are_conservative_and_hsts_is_production_only(): void
    {
        $middleware = new AddProductionSecurityHeaders();

        $this->useEnvironment('local');
        $local = $middleware->handle(
            Request::create('http://srcm.test/dashboard', 'GET'),
            fn () => new Response('ok')
        );

        $this->assertSame('nosniff', $local->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $local->headers->get('X-Frame-Options'));
        $this->assertSame(
            'strict-origin-when-cross-origin',
            $local->headers->get('Referrer-Policy')
        );
        $this->assertFalse($local->headers->has('Strict-Transport-Security'));

        $this->useEnvironment('production');
        config(['app.url' => 'https://srcm.test']);
        $production = $middleware->handle(
            Request::create('https://srcm.test/dashboard', 'GET'),
            fn () => new Response('ok')
        );

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $production->headers->get('Strict-Transport-Security')
        );
    }

    public function test_production_configuration_passes_only_with_secure_baseline(): void
    {
        $this->configureSecureProduction();

        $response = (new EnforceProductionSecurityBaseline())->handle(
            Request::create('https://srcm.test/up', 'GET'),
            fn () => new Response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_each_unsafe_production_setting_fails_closed_without_secret_values(): void
    {
        $cases = [
            ['app.debug', true, 'app.debug'],
            ['app.key', '', 'app.key'],
            ['app.url', 'http://srcm.test', 'app.url'],
            ['session.secure', false, 'session.secure'],
            ['session.http_only', false, 'session.http_only'],
            ['session.encrypt', false, 'session.encrypt'],
            ['session.serialization', 'php', 'session.serialization'],
            ['session.same_site', 'none', 'session.same_site'],
            ['auth.password_timeout', 3600, 'auth.password_timeout'],
        ];

        foreach ($cases as [$key, $value, $expectedKey]) {
            $this->configureSecureProduction();
            config([$key => $value]);

            try {
                (new EnforceProductionSecurityBaseline())->handle(
                    Request::create('https://srcm.test/up', 'GET'),
                    fn () => new Response('should-not-run')
                );
                $this->fail('Unsafe production config was accepted: '.$key);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    $expectedKey,
                    $exception->getMessage()
                );
                $this->assertStringNotContainsString(
                    'base64:unit-test-key',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_non_production_configuration_is_not_blocked(): void
    {
        $this->useEnvironment('local');
        config([
            'app.debug' => true,
            'session.secure' => false,
        ]);

        $response = (new EnforceProductionSecurityBaseline())->handle(
            Request::create('http://srcm.test', 'GET'),
            fn () => new Response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_step_up_is_production_only_and_requires_recent_confirmation(): void
    {
        $middleware = new RequireProductionPasswordConfirmation();

        $this->useEnvironment('local');
        $local = $this->requestWithSession('/danger', 'POST');
        $this->assertSame(
            200,
            $middleware->handle($local, fn () => new Response('ok'))->getStatusCode()
        );

        $this->useEnvironment('production');
        config(['auth.password_timeout' => 900]);

        $stale = $this->requestWithSession('/danger', 'POST');
        $blocked = $middleware->handle(
            $stale,
            fn () => new Response('must-not-run')
        );
        $this->assertSame(302, $blocked->getStatusCode());
        $this->assertSame('required', $blocked->headers->get('X-SRCM-Step-Up'));
        $this->assertStringContainsString(
            '/confirm-password',
            (string) $blocked->headers->get('Location')
        );
        $this->assertNull($stale->session()->get('url.intended'));

        $fresh = $this->requestWithSession('/danger', 'POST');
        $fresh->session()->put('auth.password_confirmed_at', time());
        $passed = $middleware->handle(
            $fresh,
            fn () => new Response('ok')
        );
        $this->assertSame(200, $passed->getStatusCode());
    }

    public function test_step_up_json_response_is_machine_actionable(): void
    {
        $this->useEnvironment('production');
        config(['auth.password_timeout' => 900]);

        $request = $this->requestWithSession('/danger', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = (new RequireProductionPasswordConfirmation())->handle(
            $request,
            fn () => new Response('must-not-run')
        );

        $this->assertSame(423, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame('step_up_required', $payload['code'] ?? null);
        $this->assertSame('/confirm-password', $payload['confirm_url'] ?? null);
    }

    public function test_high_impact_routes_are_bound_to_production_step_up(): void
    {
        $routeNames = [
            'organization.update',
            'organization-members.update-role',
            'financial-accounts.store',
            'cash-registers.security-drop-requests.approve',
            'purchase-payment-requests.approve',
            'purchase-payment-requests.execute',
            'commerce-post-sale.cash-refunds.store',
            'commerce-post-sale.external-refunds.store',
            'commerce-post-sale.external-refunds.submit',
        ];

        foreach ($routeNames as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, 'Missing route '.$routeName);
            $this->assertContains(
                RequireProductionPasswordConfirmation::class,
                $route->gatherMiddleware(),
                'Missing production step-up on '.$routeName
            );
        }
    }

    public function test_direct_env_usage_is_frozen_to_explicit_secret_boundaries(): void
    {
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                app_path(),
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (preg_match('/\\b(?:env|getenv)\\s*\\(/', (string) $contents) !== 1) {
                continue;
            }

            $relative = str_replace('\\', '/', substr(
                $file->getPathname(),
                strlen(base_path()) + 1
            ));
            $actual[] = $relative;
        }

        sort($actual);
        $expected = [
            'app/Adapters/Offline/EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider.php',
            'app/Adapters/Finance/MercadoPago/EnvironmentMercadoPagoConnectionSecretStore.php',
            'app/Adapters/Fiscal/Arca/EnvironmentFiscalAuthorizationRuntimeScopeStore.php',
            'app/Adapters/Fiscal/Arca/EnvironmentWsaaCredentialMaterialProvider.php',
            'app/Adapters/Fiscal/Arca/EnvironmentWsaaCredentialMaterialReferenceStore.php',
            'app/Adapters/Fiscal/Arca/OpenSslCliWsaaCmsSigner.php',
            'app/Adapters/Resilience/EnvironmentBackupEncryptionKeyResolver.php',
        ];
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function test_sensitive_environment_or_key_material_is_not_versioned(): void
    {
        $process = new Process(['git', 'ls-files'], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $tracked = preg_split('/\\R/', trim($process->getOutput())) ?: [];
        $forbidden = [];

        foreach ($tracked as $path) {
            $normalized = str_replace('\\', '/', $path);
            $lower = strtolower($normalized);

            if ($normalized === '.env.example') {
                continue;
            }

            if (
                $normalized === '.env'
                || str_starts_with($normalized, '.env.')
                || preg_match('/\\.(pem|key|p12|pfx)$/i', $normalized) === 1
            ) {
                $forbidden[] = $normalized;
            }
        }

        $this->assertSame([], $forbidden);

        $example = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=false', $example);
        $this->assertStringContainsString('SESSION_HTTP_ONLY=true', $example);
        $this->assertStringContainsString('SESSION_SAME_SITE=lax', $example);
        $this->assertStringContainsString('AUTH_PASSWORD_TIMEOUT=900', $example);
    }

    private function configureSecureProduction(): void
    {
        $this->useEnvironment('production');
        config([
            'app.debug' => false,
            'app.key' => 'base64:unit-test-key',
            'app.url' => 'https://srcm.test',
            'session.secure' => true,
            'session.http_only' => true,
            'session.encrypt' => true,
            'session.serialization' => 'json',
            'session.same_site' => 'lax',
            'auth.password_timeout' => 900,
        ]);
    }

    private function useEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['app.env' => $environment]);
    }

    private function requestWithSession(string $uri, string $method): Request
    {
        $request = Request::create($uri, $method);
        $request->setLaravelSession(
            new Store('production-security-test', new ArraySessionHandler(120))
        );

        return $request;
    }
}
