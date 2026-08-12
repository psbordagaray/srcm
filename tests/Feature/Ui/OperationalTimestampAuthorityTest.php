<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

class OperationalTimestampAuthorityTest extends TestCase
{
    public function test_web_pos_does_not_expose_operator_editable_event_times(): void
    {
        $blade = file_get_contents(
            resource_path('views/commerce-sales/create.blade.php')
        );

        $this->assertIsString($blade);
        $this->assertStringNotContainsString(
            'name="sold_at"',
            $blade
        );
        $this->assertStringNotContainsString(
            '[paid_at]',
            $blade
        );
        $this->assertStringContainsString(
            'Momento de venta',
            $blade
        );
        $this->assertStringContainsString(
            'Se registra al confirmar',
            $blade
        );
        $this->assertStringContainsString(
            'Hora efectiva del cobro',
            $blade
        );
    }

    public function test_web_request_and_controller_reject_client_time_authority(): void
    {
        $request = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );

        $this->assertIsString($request);
        $this->assertIsString($controller);

        $this->assertStringContainsString(
            "'sold_at' => ['prohibited']",
            $request
        );
        $this->assertStringContainsString(
            "'payments.*.paid_at' => ['prohibited']",
            $request
        );
        $this->assertStringContainsString(
            '$soldAt = CarbonImmutable::now();',
            $controller
        );
        $this->assertStringContainsString(
            'paidAt: null,',
            $controller
        );
    }

    public function test_operational_views_use_display_timezone_not_storage_timezone(): void
    {
        foreach ([
            resource_path('views/commerce-sales/index.blade.php'),
            resource_path('views/commerce-sales/show.blade.php'),
            resource_path('views/cash-registers/index.blade.php'),
        ] as $path) {
            $blade = file_get_contents($path);

            $this->assertIsString($blade);
            $this->assertStringContainsString(
                "config('app.display_timezone')",
                $blade
            );
        }

        $show = file_get_contents(
            resource_path('views/commerce-sales/show.blade.php')
        );

        $this->assertIsString($show);
        $this->assertStringNotContainsString(
            "timezone(config('app.timezone'))",
            $show
        );
    }
}
