<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_document_payment_due_dates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->date('payment_due_date');
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('fiscal_document_id');
            $table->unique(
                ['organization_id', 'fiscal_document_id'],
                'fiscal_document_payment_due_dates_org_document_unique'
            );
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER fiscal_document_payment_due_dates_tenant_insert BEFORE INSERT ON fiscal_document_payment_due_dates WHEN NOT EXISTS (SELECT 1 FROM fiscal_documents WHERE fiscal_documents.id = NEW.fiscal_document_id AND fiscal_documents.organization_id = NEW.organization_id) BEGIN SELECT RAISE(ABORT, 'Fiscal payment due date tenant mismatch'); END");

            DB::unprepared("CREATE TRIGGER fiscal_document_payment_due_dates_concept_insert BEFORE INSERT ON fiscal_document_payment_due_dates WHEN NOT EXISTS (SELECT 1 FROM fiscal_document_concepts WHERE fiscal_document_concepts.fiscal_document_id = NEW.fiscal_document_id AND fiscal_document_concepts.organization_id = NEW.organization_id AND fiscal_document_concepts.concept IN ('services', 'products_and_services') AND fiscal_document_concepts.service_period_from IS NOT NULL AND fiscal_document_concepts.service_period_to IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'Fiscal payment due date requires service concept and period'); END");

            DB::unprepared("CREATE TRIGGER fiscal_document_payment_due_dates_issue_insert BEFORE INSERT ON fiscal_document_payment_due_dates WHEN NOT EXISTS (SELECT 1 FROM fiscal_document_issue_dates WHERE fiscal_document_issue_dates.fiscal_document_id = NEW.fiscal_document_id AND fiscal_document_issue_dates.organization_id = NEW.organization_id) BEGIN SELECT RAISE(ABORT, 'Fiscal payment due date requires issue date'); END");

            DB::unprepared("CREATE TRIGGER fiscal_document_payment_due_dates_order_insert BEFORE INSERT ON fiscal_document_payment_due_dates WHEN NEW.payment_due_date < (SELECT fiscal_document_issue_dates.issue_date FROM fiscal_document_issue_dates WHERE fiscal_document_issue_dates.fiscal_document_id = NEW.fiscal_document_id AND fiscal_document_issue_dates.organization_id = NEW.organization_id LIMIT 1) BEGIN SELECT RAISE(ABORT, 'Fiscal payment due date precedes issue date'); END");

            DB::unprepared("CREATE TRIGGER fiscal_document_payment_due_dates_immutable_update BEFORE UPDATE ON fiscal_document_payment_due_dates BEGIN SELECT RAISE(ABORT, 'Fiscal payment due date is immutable'); END");

            DB::unprepared("CREATE TRIGGER fiscal_document_payment_due_dates_immutable_delete BEFORE DELETE ON fiscal_document_payment_due_dates BEGIN SELECT RAISE(ABORT, 'Fiscal payment due date cannot be deleted'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_payment_due_dates_tenant_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_payment_due_dates_concept_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_payment_due_dates_issue_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_payment_due_dates_order_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_payment_due_dates_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_payment_due_dates_immutable_delete');
        }

        Schema::dropIfExists('fiscal_document_payment_due_dates');
    }
};
