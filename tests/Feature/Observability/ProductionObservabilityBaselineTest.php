<?php

namespace Tests\Feature\Observability;

use App\Http\Middleware\AssignRequestId;
use App\Jobs\ProcessMercadoPagoPointWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ProductionObservabilityBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Log::withoutContext();
        Log::flushSharedContext();
        parent::tearDown();
    }

    public function test_request_and_correlation_ids_are_uuid_scoped_and_shared(): void
    {
        $incoming = strtolower((string) Str::uuid());
        $request = Request::create('/probe', 'GET');
        $request->headers->set('X-Correlation-ID', $incoming);

        $response = (new AssignRequestId())->handle(
            $request,
            fn () => new Response('ok')
        );

        $requestId = $request->attributes->get('request_id');
        $correlationId = $request->attributes->get('correlation_id');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($incoming, $correlationId);
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
        $this->assertSame($incoming, $response->headers->get('X-Correlation-ID'));
        $this->assertSame($requestId, Log::sharedContext()['request_id'] ?? null);
        $this->assertSame($incoming, Log::sharedContext()['correlation_id'] ?? null);
    }

    public function test_invalid_inbound_correlation_never_becomes_log_context(): void
    {
        $request = Request::create('/probe', 'GET');
        $request->headers->set(
            'X-Correlation-ID',
            "attacker-controlled\r\nvalue"
        );

        $response = (new AssignRequestId())->handle(
            $request,
            fn () => new Response('ok')
        );

        $requestId = $request->attributes->get('request_id');
        $this->assertTrue(is_string($requestId) && Str::isUuid($requestId));
        $this->assertSame(
            $requestId,
            $request->attributes->get('correlation_id')
        );
        $this->assertSame(
            $requestId,
            $response->headers->get('X-Correlation-ID')
        );
    }

    public function test_request_id_middleware_is_global_and_exception_context_is_registered(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString(
            '$middleware->append(AssignRequestId::class);',
            $bootstrap
        );
        $this->assertStringNotContainsString(
            "'web',\n            AssignRequestId::class",
            $bootstrap
        );
        $this->assertStringContainsString(
            '$exceptions->context(function (): array {',
            $bootstrap
        );
        $this->assertStringContainsString("'correlation_id'", $bootstrap);
    }

    public function test_json_stderr_channel_and_production_guidance_are_explicit(): void
    {
        $channel = config('logging.channels.stderr_json');

        $this->assertSame('monolog', $channel['driver'] ?? null);
        $this->assertSame(StreamHandler::class, $channel['handler'] ?? null);
        $this->assertSame(JsonFormatter::class, $channel['formatter'] ?? null);
        $this->assertSame(
            'php://stderr',
            $channel['handler_with']['stream'] ?? null
        );

        $example = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString(
            'Production: LOG_CHANNEL=stderr_json and LOG_LEVEL=info.',
            $example
        );
    }

    public function test_readiness_is_api_visible_correlated_and_operational_on_database_queue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.failed.driver' => 'database-uuids',
        ]);

        $correlation = strtolower((string) Str::uuid());
        $response = $this
            ->withHeader('X-Correlation-ID', $correlation)
            ->getJson('/api/health/ready');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ready',
            'checks' => [
                'database' => 'ok',
                'queue' => 'ok',
                'failed_jobs' => 'ok',
                'structured_logging' => 'ok',
            ],
        ]);
        $response->assertHeader('X-Correlation-ID', $correlation);
        $this->assertTrue(Str::isUuid(
            (string) $response->headers->get('X-Request-ID')
        ));
    }

    public function test_readiness_fails_closed_when_queue_is_sync(): void
    {
        config(['queue.default' => 'sync']);

        $response = $this->getJson('/api/health/ready');
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'not_ready');
        $response->assertJsonPath('checks.queue', 'fail');
    }

    public function test_queue_failure_signals_are_safe_and_context_is_reset(): void
    {
        $provider = file_get_contents(app_path('Providers/ObservabilityServiceProvider.php'));

        $providers = file_get_contents(base_path('bootstrap/providers.php'));
        $this->assertStringContainsString('ObservabilityServiceProvider::class', $providers);

        $this->assertStringContainsString('Queue::before(', $provider);
        $this->assertStringContainsString('Queue::after(', $provider);
        $this->assertStringContainsString('Queue::exceptionOccurred(', $provider);
        $this->assertStringContainsString('Queue::failing(', $provider);
        $this->assertStringContainsString("'queue.job_exception'", $provider);
        $this->assertStringContainsString("'queue.job_failed'", $provider);
        $this->assertStringContainsString("'exception_class'", $provider);
        $this->assertStringContainsString('Log::flushSharedContext();', $provider);
        $this->assertStringNotContainsString('->getMessage()', $provider);
    }

    public function test_webhook_job_carries_only_valid_optional_correlation_context(): void
    {
        $connection = strtolower((string) Str::uuid());
        $correlation = strtolower((string) Str::uuid());

        $job = new ProcessMercadoPagoPointWebhook(
            $connection,
            'ORD01OBSERVABILITY001',
            '987654321',
            $correlation
        );

        $this->assertSame($correlation, $job->correlationId);

        $invalid = new ProcessMercadoPagoPointWebhook(
            $connection,
            'ORD01OBSERVABILITY002',
            null,
            'not-a-uuid'
        );
        $this->assertNull($invalid->correlationId);

        $serialized = serialize($job);
        $this->assertStringContainsString($correlation, $serialized);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('webhook_secret', $serialized);
    }

    public function test_webhook_integration_signals_do_not_log_raw_provider_payload_fields(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/MercadoPagoWebhookController.php')
        );
        $job = file_get_contents(
            app_path('Jobs/ProcessMercadoPagoPointWebhook.php')
        );

        $this->assertStringContainsString(
            "'integration.webhook_rejected'",
            $controller
        );
        $this->assertStringContainsString(
            "'integration.webhook_queued'",
            $controller
        );
        $this->assertStringContainsString(
            "'integration.job_started'",
            $job
        );
        $this->assertStringContainsString(
            "'integration.job_succeeded'",
            $job
        );
        $this->assertStringNotContainsString("'resource_id' =>", $controller);
        $this->assertStringNotContainsString("'resource_id' =>", $job);
        $this->assertStringNotContainsString("'body' =>", $controller);
    }

    public function test_first_cut_does_not_add_deferred_observability_packages(): void
    {
        $composer = json_decode(
            file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $packages = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? [])
        );
        $joined = strtolower(implode('|', $packages));

        $this->assertStringNotContainsString('opentelemetry', $joined);
        $this->assertStringNotContainsString('prometheus', $joined);
        $this->assertStringNotContainsString('horizon', $joined);
        $this->assertStringNotContainsString('telescope', $joined);
    }
}
