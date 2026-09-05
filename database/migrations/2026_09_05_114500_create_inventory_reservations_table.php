<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('catalog_product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('condition', 32);
            $table->decimal('quantity', 18, 6);
            $table->string('base_unit_code', 32);
            $table->string('status', 32);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 90);
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key'], 'inventory_reservations_org_idem_unique');
            $table->index(['organization_id','catalog_product_id','inventory_location_id','condition','status','expires_at'], 'inventory_reservations_availability_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
    }
};