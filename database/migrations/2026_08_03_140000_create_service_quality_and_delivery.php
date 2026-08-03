<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_work_reports', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'id'],
                'srv_work_reports_org_id_unique'
            );
        });

        Schema::create(
            'service_quality_inspections',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->unsignedInteger('revision');
                $table->string('outcome', 30);
                $table->unsignedInteger('check_count');
                $table->unsignedInteger('failed_check_count');
                $table->json('checks');
                $table->text('condition_notes');
                $table->text('accessories_snapshot');
                $table->text('rework_reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('inspected_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('inspected_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_quality_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'service_order_id', 'revision'],
                    'srv_quality_order_revision_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_quality_org_idem_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_quality_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_deliveries',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->unsignedBigInteger('service_quality_inspection_id');
                $table->unsignedBigInteger('service_custody_event_id');
                $table->unsignedBigInteger('recipient_business_party_id')
                    ->nullable();
                $table->string('recipient_name');
                $table->string('recipient_document')->nullable();
                $table->boolean('customer_conformity');
                $table->text('condition_notes');
                $table->text('accessories_snapshot');
                $table->text('notes')->nullable();
                $table->foreignId('delivered_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('delivered_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_deliveries_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'service_order_id'],
                    'srv_deliveries_order_unique'
                );
                $table->unique(
                    ['organization_id', 'service_quality_inspection_id'],
                    'srv_deliveries_quality_unique'
                );
                $table->unique(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_deliveries_custody_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_deliveries_org_idem_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_deliveries_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_quality_inspection_id'],
                    'srv_deliveries_quality_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_quality_inspections')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_deliveries_custody_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_custody_events')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'recipient_business_party_id'],
                    'srv_deliveries_recipient_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('business_parties')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_warranty_grants',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_delivery_id');
                $table->unsignedBigInteger('service_work_report_id');
                $table->unsignedInteger('warranty_days');
                $table->text('coverage_terms');
                $table->timestampTz('starts_at');
                $table->timestampTz('expires_at');
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'service_work_report_id'],
                    'srv_warranties_report_unique'
                );
                $table->index(
                    ['organization_id', 'expires_at'],
                    'srv_warranties_expiry_index'
                );
                $table->foreign(
                    ['organization_id', 'service_delivery_id'],
                    'srv_warranties_delivery_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_deliveries')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_work_report_id'],
                    'srv_warranties_report_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_work_reports')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_warranty_grants');
        Schema::dropIfExists('service_deliveries');
        Schema::dropIfExists('service_quality_inspections');

        Schema::table('service_work_reports', function (Blueprint $table): void {
            $table->dropUnique('srv_work_reports_org_id_unique');
        });
    }
};
