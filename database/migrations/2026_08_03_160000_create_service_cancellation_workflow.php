<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'service_cancellation_requests',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_order_id');
                $table->string('reason', 50);
                $table->unsignedBigInteger('requester_business_party_id')
                    ->nullable();
                $table->string('requester_name');
                $table->string('customer_reference')->nullable();
                $table->string('channel', 80);
                $table->text('details')->nullable();
                $table->string('order_status_snapshot', 40);
                $table->boolean('has_started_work');
                $table->boolean('has_part_purchases');
                $table->boolean('has_part_consumptions');
                $table->boolean('has_external_custody');
                $table->boolean('has_registered_payments');
                $table->json('exposure_snapshot');
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('requested_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_cancel_req_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'service_order_id'],
                    'srv_cancel_req_order_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_cancel_req_org_idem_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_cancel_req_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'requester_business_party_id'],
                    'srv_cancel_req_party_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('business_parties')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_cancellation_resolutions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'service_cancellation_request_id'
                );
                $table->string('financial_outcome', 40);
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('customer_charge_minor')
                    ->default(0);
                $table->string('customer_acceptance_reference')->nullable();
                $table->text('work_disposition');
                $table->text('parts_disposition');
                $table->text('financial_disposition');
                $table->text('return_condition_notes');
                $table->text('accessories_snapshot');
                $table->text('notes')->nullable();
                $table->foreignId('resolved_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('resolved_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_cancel_res_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'service_cancellation_request_id',
                    ],
                    'srv_cancel_res_request_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_cancel_res_org_idem_unique'
                );
                $table->foreign(
                    [
                        'organization_id',
                        'service_cancellation_request_id',
                    ],
                    'srv_cancel_res_request_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_cancellation_requests')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_cancellation_returns',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'service_cancellation_resolution_id'
                );
                $table->unsignedBigInteger('service_order_id');
                $table->unsignedBigInteger('service_custody_event_id');
                $table->unsignedBigInteger('recipient_business_party_id')
                    ->nullable();
                $table->string('recipient_name');
                $table->string('recipient_document')->nullable();
                $table->text('condition_notes');
                $table->text('accessories_snapshot');
                $table->text('notes')->nullable();
                $table->foreignId('returned_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('returned_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'service_order_id'],
                    'srv_cancel_return_order_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'service_cancellation_resolution_id',
                    ],
                    'srv_cancel_return_resolution_unique'
                );
                $table->unique(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_cancel_return_custody_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_cancel_return_org_idem_unique'
                );
                $table->foreign(
                    [
                        'organization_id',
                        'service_cancellation_resolution_id',
                    ],
                    'srv_cancel_return_resolution_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_cancellation_resolutions')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_order_id'],
                    'srv_cancel_return_order_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_cancel_return_custody_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_custody_events')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'recipient_business_party_id'],
                    'srv_cancel_return_party_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('business_parties')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_cancellation_returns');
        Schema::dropIfExists('service_cancellation_resolutions');
        Schema::dropIfExists('service_cancellation_requests');
    }
};
