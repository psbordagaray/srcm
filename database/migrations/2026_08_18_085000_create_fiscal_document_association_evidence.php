<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'fiscal_document_association_evidence';

    private const TRIGGERS = [
        'fiscal_document_association_tenant_insert',
        'fiscal_document_association_adjustment_insert',
        'fiscal_document_association_auth_order_insert',
        'fiscal_document_association_shape_insert',
        'fiscal_document_association_immutable_update',
        'fiscal_document_association_immutable_delete',
        'fiscal_adjustment_authorization_association_gate_insert',
    ];

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->string('mode', 16);
            $table->json('associated_vouchers')->nullable();
            $table->unsignedInteger('associated_voucher_count')->default(0);
            $table->date('period_from_date')->nullable();
            $table->date('period_to_date')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('fiscal_document_id');
            $table->unique(
                ['organization_id', 'fiscal_document_id'],
                'fiscal_document_association_org_document_unique'
            );
        });

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS fiscal_adjustment_authorization_block_insert');

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_association_tenant_insert "
            . "BEFORE INSERT ON fiscal_document_association_evidence "
            . "WHEN NOT EXISTS ("
            . "SELECT 1 FROM fiscal_documents "
            . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
            . "AND fiscal_documents.organization_id = NEW.organization_id"
            . ") "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal association tenant mismatch'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_association_adjustment_insert "
            . "BEFORE INSERT ON fiscal_document_association_evidence "
            . "WHEN NOT EXISTS ("
            . "SELECT 1 FROM fiscal_documents "
            . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
            . "AND fiscal_documents.organization_id = NEW.organization_id "
            . "AND fiscal_documents.document_type IN ('credit_note','debit_note')"
            . ") "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal association requires credit/debit note'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_association_auth_order_insert "
            . "BEFORE INSERT ON fiscal_document_association_evidence "
            . "WHEN EXISTS ("
            . "SELECT 1 FROM fiscal_authorization_attempts "
            . "WHERE fiscal_authorization_attempts.fiscal_document_id = NEW.fiscal_document_id"
            . ") "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal association must precede authorization'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_association_shape_insert "
            . "BEFORE INSERT ON fiscal_document_association_evidence "
            . "WHEN "
            . "NEW.mode NOT IN ('VOUCHERS','PERIOD') "
            . "OR (NEW.mode = 'VOUCHERS' AND ("
            . "NEW.associated_vouchers IS NULL "
            . "OR json_valid(NEW.associated_vouchers) <> 1 "
            . "OR json_type(NEW.associated_vouchers) <> 'array' "
            . "OR json_array_length(NEW.associated_vouchers) < 1 "
            . "OR NEW.associated_voucher_count <> json_array_length(NEW.associated_vouchers) "
            . "OR NEW.period_from_date IS NOT NULL "
            . "OR NEW.period_to_date IS NOT NULL "
            . "OR EXISTS ("
            . "SELECT 1 FROM json_each(NEW.associated_vouchers) AS v "
            . "WHERE COALESCE(json_type(v.value, '$.voucher_type_code'), '') <> 'integer' "
            . "OR CAST(json_extract(v.value, '$.voucher_type_code') AS INTEGER) <= 0 "
            . "OR CAST(json_extract(v.value, '$.voucher_type_code') AS INTEGER) >= 1000 "
            . "OR COALESCE(json_type(v.value, '$.point_of_sale_number'), '') <> 'integer' "
            . "OR CAST(json_extract(v.value, '$.point_of_sale_number') AS INTEGER) <= 0 "
            . "OR CAST(json_extract(v.value, '$.point_of_sale_number') AS INTEGER) >= 99999 "
            . "OR COALESCE(json_type(v.value, '$.voucher_number'), '') <> 'integer' "
            . "OR CAST(json_extract(v.value, '$.voucher_number') AS INTEGER) <= 0 "
            . "OR CAST(json_extract(v.value, '$.voucher_number') AS INTEGER) >= 99999999 "
            . "OR (COALESCE(json_type(v.value, '$.issuer_cuit'), 'null') NOT IN ('null','text')) "
            . "OR (json_type(v.value, '$.issuer_cuit') = 'text' AND ("
            . "length(json_extract(v.value, '$.issuer_cuit')) <> 11 "
            . "OR json_extract(v.value, '$.issuer_cuit') GLOB '*[^0-9]*'"
            . ")) "
            . "OR (COALESCE(json_type(v.value, '$.voucher_date'), 'null') NOT IN ('null','text'))"
            . ") "
            . "OR EXISTS ("
            . "SELECT 1 "
            . "FROM json_each(NEW.associated_vouchers) AS a "
            . "JOIN json_each(NEW.associated_vouchers) AS b "
            . "ON CAST(a.key AS INTEGER) < CAST(b.key AS INTEGER) "
            . "AND json_extract(a.value, '$.voucher_type_code') = json_extract(b.value, '$.voucher_type_code') "
            . "AND json_extract(a.value, '$.point_of_sale_number') = json_extract(b.value, '$.point_of_sale_number') "
            . "AND json_extract(a.value, '$.voucher_number') = json_extract(b.value, '$.voucher_number')"
            . ")"
            . ")) "
            . "OR (NEW.mode = 'PERIOD' AND ("
            . "NEW.associated_vouchers IS NOT NULL "
            . "OR NEW.associated_voucher_count <> 0 "
            . "OR NEW.period_from_date IS NULL "
            . "OR NEW.period_to_date IS NULL "
            . "OR NEW.period_from_date <= '2006-01-01' "
            . "OR NEW.period_to_date < NEW.period_from_date "
            . "OR NOT EXISTS ("
            . "SELECT 1 FROM fiscal_document_issue_dates "
            . "WHERE fiscal_document_issue_dates.fiscal_document_id = NEW.fiscal_document_id "
            . "AND fiscal_document_issue_dates.organization_id = NEW.organization_id "
            . "AND fiscal_document_issue_dates.issue_date >= NEW.period_to_date"
            . ")"
            . ")) "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal association evidence shape invalid'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_association_immutable_update "
            . "BEFORE UPDATE ON fiscal_document_association_evidence "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal association evidence is immutable'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_association_immutable_delete "
            . "BEFORE DELETE ON fiscal_document_association_evidence "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal association evidence cannot be deleted'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_adjustment_authorization_association_gate_insert "
            . "BEFORE INSERT ON fiscal_authorization_attempts "
            . "WHEN EXISTS ("
            . "SELECT 1 FROM fiscal_documents "
            . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
            . "AND fiscal_documents.document_type IN ('credit_note','debit_note')"
            . ") AND ("
            . "EXISTS ("
            . "SELECT 1 FROM fiscal_document_classifications "
            . "WHERE fiscal_document_classifications.fiscal_document_id = NEW.fiscal_document_id "
            . "AND fiscal_document_classifications.voucher_code IN (202,203,207,208,212,213)"
            . ") "
            . "OR NOT EXISTS ("
            . "SELECT 1 FROM fiscal_document_association_evidence AS evidence "
            . "WHERE evidence.fiscal_document_id = NEW.fiscal_document_id "
            . "AND evidence.organization_id = NEW.organization_id "
            . "AND ("
            . "(evidence.mode = 'VOUCHERS' "
            . "AND evidence.associated_vouchers IS NOT NULL "
            . "AND json_valid(evidence.associated_vouchers) = 1 "
            . "AND json_type(evidence.associated_vouchers) = 'array' "
            . "AND json_array_length(evidence.associated_vouchers) > 0 "
            . "AND evidence.associated_voucher_count = json_array_length(evidence.associated_vouchers) "
            . "AND evidence.period_from_date IS NULL "
            . "AND evidence.period_to_date IS NULL) "
            . "OR (evidence.mode = 'PERIOD' "
            . "AND evidence.associated_vouchers IS NULL "
            . "AND evidence.associated_voucher_count = 0 "
            . "AND evidence.period_from_date > '2006-01-01' "
            . "AND evidence.period_to_date >= evidence.period_from_date "
            . "AND EXISTS ("
            . "SELECT 1 FROM fiscal_document_issue_dates "
            . "WHERE fiscal_document_issue_dates.fiscal_document_id = NEW.fiscal_document_id "
            . "AND fiscal_document_issue_dates.organization_id = NEW.organization_id "
            . "AND fiscal_document_issue_dates.issue_date >= evidence.period_to_date"
            . "))"
            . ")"
            . ")"
            . ") "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal adjustment association evidence missing or unsupported'); "
            . "END"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (self::TRIGGERS as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS "' . $trigger . '"');
            }
        }

        Schema::dropIfExists(self::TABLE);

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(
                "CREATE TRIGGER fiscal_adjustment_authorization_block_insert "
                . "BEFORE INSERT ON fiscal_authorization_attempts "
                . "WHEN EXISTS ("
                . "SELECT 1 FROM fiscal_documents "
                . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
                . "AND fiscal_documents.document_type IN ('credit_note','debit_note')"
                . ") "
                . "BEGIN "
                . "SELECT RAISE(ABORT, 'Fiscal adjustment association evidence required before authorization'); "
                . "END"
            );
        }
    }
};
