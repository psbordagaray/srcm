<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_evidences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('service_order_id');
            $table->uuid('public_id');
            $table->string('context', 40);

            $table->unsignedBigInteger('service_order_intake_id')->nullable();
            $table->unsignedBigInteger('service_diagnostic_id')->nullable();
            $table->unsignedBigInteger('service_work_item_id')->nullable();
            $table->unsignedBigInteger('service_part_requirement_id')->nullable();
            $table->unsignedBigInteger('service_custody_event_id')->nullable();
            $table->unsignedBigInteger('service_quality_inspection_id')->nullable();
            $table->unsignedBigInteger('service_delivery_id')->nullable();
            $table->unsignedBigInteger('service_cancellation_request_id')->nullable();
            $table->unsignedBigInteger('service_cancellation_resolution_id')->nullable();
            $table->unsignedBigInteger('service_cancellation_return_id')->nullable();
            $table->unsignedBigInteger('service_warranty_claim_id')->nullable();
            $table->unsignedBigInteger('service_warranty_claim_resolution_id')->nullable();
            $table->unsignedBigInteger('service_warranty_claim_return_id')->nullable();

            $table->string('original_filename');
            $table->string('stored_filename', 100);
            $table->string('disk', 32);
            $table->string('path');
            $table->char('path_hash', 64);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('description')->nullable();
            $table->timestampTz('captured_at');
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->string('idempotency_key', 191);
            $table->char('fingerprint', 64);
            $table->timestampsTz();

            $table->unique('public_id', 'svc_ev_public_uid');
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'svc_ev_org_idem_uid'
            );
            $table->unique(
                ['disk', 'path_hash'],
                'svc_ev_disk_path_uid'
            );
            $table->index(
                ['organization_id', 'service_order_id', 'captured_at'],
                'svc_ev_order_capture_idx'
            );
            $table->index(
                ['organization_id', 'context'],
                'svc_ev_context_idx'
            );
            $table->index(
                ['organization_id', 'service_order_id', 'sha256'],
                'svc_ev_hash_idx'
            );

            $table->foreign('organization_id', 'svc_ev_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
            $table->foreign('service_order_id', 'svc_ev_order_fk')
                ->references('id')
                ->on('service_orders')
                ->restrictOnDelete();
            $table->foreign('service_order_intake_id', 'svc_ev_intake_fk')
                ->references('id')
                ->on('service_order_intakes')
                ->restrictOnDelete();
            $table->foreign('service_diagnostic_id', 'svc_ev_diag_fk')
                ->references('id')
                ->on('service_diagnostics')
                ->restrictOnDelete();
            $table->foreign('service_work_item_id', 'svc_ev_work_fk')
                ->references('id')
                ->on('service_work_items')
                ->restrictOnDelete();
            $table->foreign('service_part_requirement_id', 'svc_ev_part_fk')
                ->references('id')
                ->on('service_part_requirements')
                ->restrictOnDelete();
            $table->foreign('service_custody_event_id', 'svc_ev_custody_fk')
                ->references('id')
                ->on('service_custody_events')
                ->restrictOnDelete();
            $table->foreign('service_quality_inspection_id', 'svc_ev_quality_fk')
                ->references('id')
                ->on('service_quality_inspections')
                ->restrictOnDelete();
            $table->foreign('service_delivery_id', 'svc_ev_delivery_fk')
                ->references('id')
                ->on('service_deliveries')
                ->restrictOnDelete();
            $table->foreign('service_cancellation_request_id', 'svc_ev_cancel_req_fk')
                ->references('id')
                ->on('service_cancellation_requests')
                ->restrictOnDelete();
            $table->foreign('service_cancellation_resolution_id', 'svc_ev_cancel_res_fk')
                ->references('id')
                ->on('service_cancellation_resolutions')
                ->restrictOnDelete();
            $table->foreign('service_cancellation_return_id', 'svc_ev_cancel_ret_fk')
                ->references('id')
                ->on('service_cancellation_returns')
                ->restrictOnDelete();
            $table->foreign('service_warranty_claim_id', 'svc_ev_warranty_claim_fk')
                ->references('id')
                ->on('service_warranty_claims')
                ->restrictOnDelete();
            $table->foreign('service_warranty_claim_resolution_id', 'svc_ev_warranty_res_fk')
                ->references('id')
                ->on('service_warranty_claim_resolutions')
                ->restrictOnDelete();
            $table->foreign('service_warranty_claim_return_id', 'svc_ev_warranty_ret_fk')
                ->references('id')
                ->on('service_warranty_claim_returns')
                ->restrictOnDelete();
            $table->foreign('uploaded_by_user_id', 'svc_ev_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_evidences');
    }
};
