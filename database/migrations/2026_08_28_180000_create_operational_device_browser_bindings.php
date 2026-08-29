<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'operational_device_browser_bindings',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'operational_device_id'
                );
                $table->uuid('public_id')->unique();
                $table->char('token_hash', 64)->unique();
                $table->foreignId('issued_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('issued_at');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign(
                    [
                        'operational_device_id',
                        'organization_id',
                    ],
                    'device_browser_binding_device_org_fk'
                )
                    ->references(['id', 'organization_id'])
                    ->on('operational_devices')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'organization_id',
                        'operational_device_id',
                        'revoked_at',
                        'expires_at',
                    ],
                    'device_browser_binding_runtime_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'operational_device_browser_bindings'
        );
    }
};
