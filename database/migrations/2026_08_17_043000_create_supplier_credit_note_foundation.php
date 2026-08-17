<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'supplier_credit_notes',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'supplier_invoice_id'
                )
                    ->constrained('supplier_invoices')
                    ->restrictOnDelete();
                $table->foreignId('purchase_order_id')
                    ->constrained('purchase_orders')
                    ->restrictOnDelete();
                $table->foreignId('supplier_id')
                    ->constrained('suppliers')
                    ->restrictOnDelete();
                $table->string(
                    'document_number',
                    255
                );
                $table->string(
                    'normalized_document_number',
                    255
                );
                $table->date('issued_on');
                $table->char('currency_code', 3);
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->text('reason');
                $table->text('notes')->nullable();
                $table->string(
                    'idempotency_key',
                    100
                );
                $table->char('fingerprint', 64);
                $table->foreignId(
                    'recorded_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'supplier_credit_note_org_idem_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'supplier_id',
                        'normalized_document_number',
                    ],
                    'supplier_credit_note_supplier_doc_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'supplier_invoice_id',
                        'issued_on',
                    ],
                    'supplier_credit_note_invoice_date_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'Supplier credit note facts are append-only and this migration is intentionally irreversible.'
        );
    }

    private function createGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteGuards();

            return;
        }

        if (in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            $this->createMysqlGuards();
        }
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_note_guard_insert
BEFORE INSERT ON supplier_credit_notes
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM supplier_invoices si
            WHERE si.id = NEW.supplier_invoice_id
              AND si.organization_id = NEW.organization_id
              AND si.purchase_order_id = NEW.purchase_order_id
              AND si.supplier_id = NEW.supplier_id
              AND si.currency_code = NEW.currency_code
              AND date(NEW.issued_on) >= date(si.issued_on)
        )
        THEN RAISE(ABORT, 'supplier_credit_note_invoice_invalid')
    END;

    SELECT CASE
        WHEN NEW.amount_minor <= 0
          OR length(trim(NEW.reason)) = 0
          OR length(NEW.normalized_document_number) = 0
          OR length(NEW.idempotency_key) = 0
          OR length(NEW.fingerprint) != 64
          OR (
              COALESCE(
                  (
                      SELECT SUM(existing.amount_minor)
                      FROM supplier_credit_notes existing
                      WHERE existing.organization_id = NEW.organization_id
                        AND existing.supplier_invoice_id = NEW.supplier_invoice_id
                  ),
                  0
              ) + NEW.amount_minor
          ) > (
              SELECT si.total_minor
              FROM supplier_invoices si
              WHERE si.id = NEW.supplier_invoice_id
          )
        THEN RAISE(ABORT, 'supplier_credit_note_value_invalid')
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_note_guard_update
BEFORE UPDATE ON supplier_credit_notes
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'supplier_credit_note_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_note_guard_delete
BEFORE DELETE ON supplier_credit_notes
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'supplier_credit_note_immutable');
END;
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_note_guard_insert
BEFORE INSERT ON supplier_credit_notes
FOR EACH ROW
BEGIN
    DECLARE invoice_total BIGINT DEFAULT NULL;

    SELECT si.total_minor
      INTO invoice_total
      FROM supplier_invoices si
     WHERE si.id = NEW.supplier_invoice_id
       AND si.organization_id = NEW.organization_id
       AND si.purchase_order_id = NEW.purchase_order_id
       AND si.supplier_id = NEW.supplier_id
       AND si.currency_code = NEW.currency_code
       AND NEW.issued_on >= si.issued_on
     LIMIT 1;

    IF invoice_total IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_credit_note_invoice_invalid';
    END IF;

    IF NEW.amount_minor <= 0
       OR CHAR_LENGTH(TRIM(NEW.reason)) = 0
       OR CHAR_LENGTH(NEW.normalized_document_number) = 0
       OR CHAR_LENGTH(NEW.idempotency_key) = 0
       OR CHAR_LENGTH(NEW.fingerprint) != 64
       OR (
           COALESCE(
               (
                   SELECT SUM(existing.amount_minor)
                   FROM supplier_credit_notes existing
                   WHERE existing.organization_id = NEW.organization_id
                     AND existing.supplier_invoice_id = NEW.supplier_invoice_id
               ),
               0
           ) + NEW.amount_minor
       ) > invoice_total
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_credit_note_value_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_note_guard_update
BEFORE UPDATE ON supplier_credit_notes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'supplier_credit_note_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_note_guard_delete
BEFORE DELETE ON supplier_credit_notes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'supplier_credit_note_immutable';
END
SQL);
    }
};
