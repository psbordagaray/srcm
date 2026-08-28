<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('label', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(
                ['id', 'organization_id'],
                'operational_devices_id_org_unique'
            );
            $table->index(
                ['organization_id', 'active'],
                'operational_devices_org_active_index'
            );
        });

        Schema::create(
            'operational_device_capabilities',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->unsignedBigInteger('operational_device_id');
                $table->string('capability', 64);
                $table->timestamps();

                $table->unique(
                    ['operational_device_id', 'capability'],
                    'operational_device_capability_unique'
                );

                $table->foreign(
                    ['operational_device_id', 'organization_id'],
                    'operational_device_capability_device_org_fk'
                )
                    ->references(['id', 'organization_id'])
                    ->on('operational_devices')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'operational_device_operation_claims',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->unsignedBigInteger('operational_device_id');
                $table->uuid('client_operation_id');
                $table->string('capability', 64);
                $table->string('operation_type', 100);
                $table->char('request_fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    [
                        'operational_device_id',
                        'client_operation_id',
                    ],
                    'operational_device_operation_idempotency_unique'
                );

                $table->foreign(
                    ['operational_device_id', 'organization_id'],
                    'operational_device_operation_device_org_fk'
                )
                    ->references(['id', 'organization_id'])
                    ->on('operational_devices')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'operational_device_operation_claims'
        );
        Schema::dropIfExists(
            'operational_device_capabilities'
        );
        Schema::dropIfExists('operational_devices');
    }
};
