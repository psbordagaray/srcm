<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_document_recipient_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->string('document_type_code', 3);
            $table->string('document_number', 20);
            $table->string('vat_condition_code', 10);
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('fiscal_document_id');
            $table->unique(
                ['organization_id', 'fiscal_document_id'],
                'fiscal_document_recipient_evidence_org_document_unique'
            );
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER fiscal_document_recipient_evidence_tenant_insert BEFORE INSERT ON fiscal_document_recipient_evidence WHEN NOT EXISTS (SELECT 1 FROM fiscal_documents WHERE fiscal_documents.id = NEW.fiscal_document_id AND fiscal_documents.organization_id = NEW.organization_id) BEGIN SELECT RAISE(ABORT, 'Fiscal recipient evidence tenant mismatch'); END");
            DB::unprepared("CREATE TRIGGER fiscal_document_recipient_evidence_immutable_update BEFORE UPDATE ON fiscal_document_recipient_evidence BEGIN SELECT RAISE(ABORT, 'Fiscal recipient evidence is immutable'); END");
            DB::unprepared("CREATE TRIGGER fiscal_document_recipient_evidence_immutable_delete BEFORE DELETE ON fiscal_document_recipient_evidence BEGIN SELECT RAISE(ABORT, 'Fiscal recipient evidence cannot be deleted'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_recipient_evidence_tenant_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_recipient_evidence_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS fiscal_document_recipient_evidence_immutable_delete');
        }

        Schema::dropIfExists('fiscal_document_recipient_evidence');
    }
};
