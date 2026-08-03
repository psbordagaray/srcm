<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_quote_lines', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id'],
                'srv_quote_lines_org_id_unique'
            );
        });

        Schema::table(
            'inventory_movement_lines',
            function (Blueprint $table): void {
                $table->unique(
                    ['organization_id', 'id'],
                    'inv_movement_lines_org_id_unique'
                );
            }
        );

        Schema::create(
            'service_part_requirements',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->unsignedBigInteger('service_work_item_id');
                $table->unsignedBigInteger('service_quote_line_id');
                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->string('condition', 32);
                $table->string('source', 30);
                $table->decimal('required_quantity', 20, 6);
                $table->string('base_unit_code', 16);
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('planned_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_part_req_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'service_quote_line_id'],
                    'srv_part_req_quote_line_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_part_req_org_idem_unique'
                );
                $table->index(
                    ['organization_id', 'service_order_id', 'source'],
                    'srv_part_req_order_source_index'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_part_req_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_work_item_id'],
                    'srv_part_req_work_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_work_items')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_quote_line_id'],
                    'srv_part_req_quote_line_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_quote_lines')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_part_purchases',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->unsignedBigInteger('supplier_id');
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('parts_total_minor');
                $table->unsignedBigInteger('logistics_cost_minor')
                    ->default(0);
                $table->unsignedBigInteger('grand_total_minor');
                $table->string('document_reference')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('purchased_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('purchased_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_part_purchases_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_part_purchases_org_idem_unique'
                );
                $table->index(
                    ['organization_id', 'service_order_id', 'purchased_at'],
                    'srv_part_purchases_order_date_index'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_part_purchases_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'supplier_id'],
                    'srv_part_purchases_supplier_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('suppliers')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_part_purchase_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_part_purchase_id');
                $table->unsignedBigInteger('service_part_requirement_id');
                $table->unsignedInteger('sequence');
                $table->decimal('quantity', 20, 6);
                $table->unsignedBigInteger('unit_cost_minor');
                $table->unsignedBigInteger('line_total_minor');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_part_purchase_lines_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'service_part_purchase_id',
                        'sequence',
                    ],
                    'srv_part_purchase_lines_sequence_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'service_part_purchase_id',
                        'service_part_requirement_id',
                    ],
                    'srv_part_purchase_lines_req_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_part_purchase_id'],
                    'srv_part_purchase_lines_purchase_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_part_purchases')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_part_requirement_id'],
                    'srv_part_purchase_lines_req_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_part_requirements')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_part_consumptions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_part_requirement_id');
                $table->unsignedBigInteger('service_part_purchase_line_id')
                    ->nullable();
                $table->unsignedBigInteger('inventory_movement_line_id')
                    ->nullable();
                $table->decimal('quantity', 20, 6);
                $table->foreignId('consumed_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('consumed_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_part_consumptions_org_idem_unique'
                );
                $table->unique(
                    ['organization_id', 'inventory_movement_line_id'],
                    'srv_part_consumptions_move_line_unique'
                );
                $table->index(
                    ['organization_id', 'service_part_requirement_id'],
                    'srv_part_consumptions_req_index'
                );
                $table->foreign(
                    ['organization_id', 'service_part_requirement_id'],
                    'srv_part_consumptions_req_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_part_requirements')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_part_purchase_line_id'],
                    'srv_part_consumptions_purchase_line_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_part_purchase_lines')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_movement_line_id'],
                    'srv_part_consumptions_move_line_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_movement_lines')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_part_consumptions');
        Schema::dropIfExists('service_part_purchase_lines');
        Schema::dropIfExists('service_part_purchases');
        Schema::dropIfExists('service_part_requirements');

        Schema::table(
            'inventory_movement_lines',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'inv_movement_lines_org_id_unique'
                );
            }
        );

        Schema::table('service_quote_lines', function (Blueprint $table): void {
            $table->dropUnique('srv_quote_lines_org_id_unique');
        });
    }
};
