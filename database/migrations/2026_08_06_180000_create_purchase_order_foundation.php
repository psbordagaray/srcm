<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'organization_id',
                        'supplier_id',
                        'catalog_product_id',
                        'id',
                    ],
                    'supplier_offers_purchase_identity_unique'
                );
            }
        );

        Schema::table(
            'inventory_movement_lines',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'organization_id',
                        'id',
                        'inventory_movement_id',
                        'catalog_product_id',
                    ],
                    'inv_move_lines_purchase_identity_unique'
                );
            }
        );

        Schema::create(
            'purchase_orders',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger('supplier_id');
                $table->string('status', 32)
                    ->default('draft');
                $table->char('currency_code', 3);
                $table->unsignedBigInteger(
                    'expected_logistics_cost_minor'
                )->default(0);
                $table->unsignedBigInteger(
                    'merchandise_subtotal_minor'
                )->default(0);
                $table->unsignedBigInteger(
                    'expected_total_minor'
                )->default(0);
                $table->text('notes')->nullable();
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('issued_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('issued_at')->nullable();
                $table->foreignId('cancelled_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'purchase_orders_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'purchase_orders_org_idempotency_unique'
                );
                $table->unique(
                    ['organization_id', 'id', 'supplier_id'],
                    'purchase_orders_org_supplier_unique'
                );
                $table->index(
                    ['organization_id', 'status', 'created_at'],
                    'purchase_orders_org_status_index'
                );

                $table->foreign(
                    ['organization_id', 'supplier_id'],
                    'purchase_orders_org_supplier_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('suppliers')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'purchase_order_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('purchase_order_id');
                $table->unsignedSmallInteger('sequence');
                $table->unsignedBigInteger('supplier_id');
                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'supplier_offer_id'
                )->nullable();
                $table->string('supplier_code')->nullable();
                $table->text('description');
                $table->string('base_unit_code', 16);
                $table->unsignedTinyInteger('quantity_scale');
                $table->decimal(
                    'ordered_quantity',
                    20,
                    6
                );
                $table->unsignedBigInteger('unit_cost_minor');
                $table->unsignedBigInteger('subtotal_minor');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'purchase_order_lines_org_id_unique'
                );
                $table->unique(
                    ['purchase_order_id', 'sequence'],
                    'purchase_order_lines_sequence_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'id',
                        'purchase_order_id',
                        'catalog_product_id',
                    ],
                    'purchase_order_lines_identity_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_order_id',
                        'catalog_product_id',
                    ],
                    'purchase_order_lines_order_product_index'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'purchase_order_id',
                        'supplier_id',
                    ],
                    'purchase_order_lines_order_supplier_fk'
                )
                    ->references([
                        'organization_id',
                        'id',
                        'supplier_id',
                    ])
                    ->on('purchase_orders')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'supplier_id',
                        'catalog_product_id',
                        'supplier_offer_id',
                    ],
                    'purchase_order_lines_offer_fk'
                )
                    ->references([
                        'organization_id',
                        'supplier_id',
                        'catalog_product_id',
                        'id',
                    ])
                    ->on('supplier_offers')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'purchase_receipts',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger('purchase_order_id');
                $table->unsignedBigInteger('supplier_id');
                $table->unsignedBigInteger(
                    'inventory_movement_id'
                );
                $table->string(
                    'document_reference'
                )->nullable();
                $table->string(
                    'normalized_document_reference'
                )->nullable();
                $table->timestampTz('received_at');
                $table->timestampTz('confirmed_at');
                $table->foreignId('received_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'logistics_cost_minor'
                )->default(0);
                $table->unsignedBigInteger(
                    'merchandise_total_minor'
                );
                $table->unsignedBigInteger(
                    'actual_total_minor'
                );
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'purchase_receipts_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'purchase_receipts_org_idempotency_unique'
                );
                $table->unique(
                    ['inventory_movement_id'],
                    'purchase_receipts_movement_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'supplier_id',
                        'normalized_document_reference',
                    ],
                    'purchase_receipts_document_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'id',
                        'purchase_order_id',
                        'inventory_movement_id',
                    ],
                    'purchase_receipts_order_movement_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_order_id',
                        'received_at',
                    ],
                    'purchase_receipts_order_date_index'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'purchase_order_id',
                        'supplier_id',
                    ],
                    'purchase_receipts_order_supplier_fk'
                )
                    ->references([
                        'organization_id',
                        'id',
                        'supplier_id',
                    ])
                    ->on('purchase_orders')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'inventory_movement_id',
                    ],
                    'purchase_receipts_movement_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_movements')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'purchase_receipt_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'purchase_receipt_id'
                );
                $table->unsignedBigInteger(
                    'purchase_order_id'
                );
                $table->unsignedBigInteger(
                    'purchase_order_line_id'
                );
                $table->unsignedBigInteger(
                    'inventory_movement_id'
                );
                $table->unsignedBigInteger(
                    'inventory_movement_line_id'
                );
                $table->unsignedSmallInteger('sequence');
                $table->unsignedBigInteger(
                    'catalog_product_id'
                );
                $table->unsignedBigInteger(
                    'inventory_location_id'
                );
                $table->string('condition', 32);
                $table->decimal(
                    'received_quantity',
                    20,
                    6
                );
                $table->unsignedBigInteger(
                    'actual_unit_cost_minor'
                );
                $table->unsignedBigInteger(
                    'subtotal_minor'
                );
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'purchase_receipt_lines_org_id_unique'
                );
                $table->unique(
                    [
                        'purchase_receipt_id',
                        'sequence',
                    ],
                    'purchase_receipt_lines_sequence_unique'
                );
                $table->unique(
                    ['inventory_movement_line_id'],
                    'purchase_receipt_lines_movement_line_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_order_line_id',
                    ],
                    'purchase_receipt_lines_order_line_index'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'purchase_receipt_id',
                        'purchase_order_id',
                        'inventory_movement_id',
                    ],
                    'purchase_receipt_lines_receipt_fk'
                )
                    ->references([
                        'organization_id',
                        'id',
                        'purchase_order_id',
                        'inventory_movement_id',
                    ])
                    ->on('purchase_receipts')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'purchase_order_line_id',
                        'purchase_order_id',
                        'catalog_product_id',
                    ],
                    'purchase_receipt_lines_order_line_fk'
                )
                    ->references([
                        'organization_id',
                        'id',
                        'purchase_order_id',
                        'catalog_product_id',
                    ])
                    ->on('purchase_order_lines')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'inventory_movement_line_id',
                        'inventory_movement_id',
                        'catalog_product_id',
                    ],
                    'purchase_receipt_lines_movement_line_fk'
                )
                    ->references([
                        'organization_id',
                        'id',
                        'inventory_movement_id',
                        'catalog_product_id',
                    ])
                    ->on('inventory_movement_lines')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'inventory_location_id',
                    ],
                    'purchase_receipt_lines_location_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_locations')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');

        Schema::table(
            'inventory_movement_lines',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'inv_move_lines_purchase_identity_unique'
                );
            }
        );

        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'supplier_offers_purchase_identity_unique'
                );
            }
        );
    }
};
