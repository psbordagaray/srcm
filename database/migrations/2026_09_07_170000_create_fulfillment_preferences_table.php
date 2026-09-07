<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('inventory_reservation_id')
                ->constrained('inventory_reservations')
                ->restrictOnDelete();
            $table->boolean('allow_another_brand')->default(false);
            $table->boolean('allow_equivalent_product')->default(false);
            $table->boolean('require_exact_product')->default(false);
            $table->boolean('consultation_required')->default(false);
            $table->string('consultation_channel', 32)->nullable();
            $table->string('unavailable_line_fallback', 32);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 90);
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'fulfillment_preferences_org_idem_unique'
            );
            $table->index(
                ['organization_id', 'inventory_reservation_id'],
                'fulfillment_preferences_reservation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_preferences');
    }
};
