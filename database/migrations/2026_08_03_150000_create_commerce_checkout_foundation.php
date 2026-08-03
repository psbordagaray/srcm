<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'service_quote_decisions',
            function (Blueprint $table): void {
                $table->unique(
                    ['organization_id', 'id'],
                    'srv_quote_decisions_org_id_unique'
                );
            }
        );

        Schema::create('commerce_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('sale_number');
            $table->string('status', 20)->default('building');
            $table->unsignedBigInteger('service_order_id')->nullable();
            $table->unsignedBigInteger('service_delivery_id')->nullable();
            $table->unsignedBigInteger('service_quote_decision_id')->nullable();
            $table->unsignedBigInteger('service_quote_option_id')->nullable();
            $table->unsignedBigInteger('customer_business_party_id')->nullable();
            $table->string('customer_name_snapshot');
            $table->string('customer_document_snapshot')->nullable();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('service_subtotal_minor');
            $table->unsignedBigInteger('product_subtotal_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('inventory_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('sold_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('idempotency_key', 90);
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'commerce_sales_org_id_unique'
            );
            $table->unique(
                ['organization_id', 'sale_number'],
                'commerce_sales_org_number_unique'
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'commerce_sales_org_idem_unique'
            );
            $table->unique(
                ['organization_id', 'service_order_id'],
                'commerce_sales_service_order_unique'
            );
            $table->unique(
                ['organization_id', 'service_delivery_id'],
                'commerce_sales_delivery_unique'
            );
            $table->unique(
                ['organization_id', 'service_quote_decision_id'],
                'commerce_sales_decision_unique'
            );
            $table->unique(
                ['organization_id', 'inventory_movement_id'],
                'commerce_sales_movement_unique'
            );
            $table->index(
                ['organization_id', 'sold_at'],
                'commerce_sales_org_sold_index'
            );
            $table->foreign(
                ['organization_id', 'service_order_id'],
                'commerce_sales_order_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_orders')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'service_delivery_id'],
                'commerce_sales_delivery_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_deliveries')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'service_quote_decision_id'],
                'commerce_sales_decision_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_quote_decisions')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'service_quote_option_id'],
                'commerce_sales_option_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_quote_options')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'customer_business_party_id'],
                'commerce_sales_customer_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('business_parties')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'inventory_movement_id'],
                'commerce_sales_movement_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('inventory_movements')
                ->restrictOnDelete();
        });

        Schema::create(
            'commerce_sale_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('commerce_sale_id');
                $table->unsignedInteger('position');
                $table->string('line_type', 20);
                $table->text('description');
                $table->decimal('quantity', 18, 6);
                $table->unsignedBigInteger('unit_price_minor');
                $table->unsignedBigInteger('line_total_minor');
                $table->unsignedBigInteger('service_quote_line_id')->nullable();
                $table->foreignId('catalog_product_id')
                    ->nullable()
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('inventory_movement_line_id')
                    ->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'commerce_sale_id', 'position'],
                    'commerce_sale_lines_position_unique'
                );
                $table->unique(
                    ['organization_id', 'service_quote_line_id'],
                    'commerce_sale_lines_quote_line_unique'
                );
                $table->unique(
                    ['organization_id', 'inventory_movement_line_id'],
                    'commerce_sale_lines_movement_line_unique'
                );
                $table->foreign(
                    ['organization_id', 'commerce_sale_id'],
                    'commerce_sale_lines_sale_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('commerce_sales')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_quote_line_id'],
                    'commerce_sale_lines_quote_line_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_quote_lines')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_movement_line_id'],
                    'commerce_sale_lines_movement_line_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_movement_lines')
                    ->restrictOnDelete();
            }
        );

        Schema::create('commerce_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->unsignedBigInteger('commerce_sale_id');
            $table->unsignedInteger('position');
            $table->string('method', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('paid_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'commerce_sale_id', 'position'],
                'commerce_payments_position_unique'
            );
            $table->index(
                ['organization_id', 'paid_at'],
                'commerce_payments_org_paid_index'
            );
            $table->foreign(
                ['organization_id', 'commerce_sale_id'],
                'commerce_payments_sale_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('commerce_sales')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payments');
        Schema::dropIfExists('commerce_sale_lines');
        Schema::dropIfExists('commerce_sales');

        Schema::table(
            'service_quote_decisions',
            function (Blueprint $table): void {
                $table->dropUnique('srv_quote_decisions_org_id_unique');
            }
        );
    }
};
