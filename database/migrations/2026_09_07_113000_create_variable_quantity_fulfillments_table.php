<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variable_quantity_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('inventory_reservation_id')
                ->nullable()
                ->constrained('inventory_reservations')
                ->restrictOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained('catalog_products')
                ->restrictOnDelete();
            $table->foreignId('inventory_location_id')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->string('condition', 32);
            $table->decimal('requested_quantity', 18, 6);
            $table->decimal('measured_quantity', 18, 6);
            $table->decimal('accepted_quantity', 18, 6);
            $table->string('base_unit_code', 32);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 90);
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'variable_quantity_fulfillments_org_idem_unique'
            );
            $table->index(
                ['organization_id', 'inventory_reservation_id'],
                'variable_quantity_fulfillments_reservation_idx'
            );
            $table->index(
                ['organization_id','catalog_product_id','inventory_location_id','condition'],
                'variable_quantity_fulfillments_position_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variable_quantity_fulfillments');
    }
};
