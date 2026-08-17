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
            'supplier_invoices',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('purchase_order_id')
                    ->constrained('purchase_orders')
                    ->restrictOnDelete();
                $table->foreignId('supplier_id')
                    ->constrained('suppliers')
                    ->restrictOnDelete();
                $table->string('document_number', 255);
                $table->string(
                    'normalized_document_number',
                    255
                );
                $table->date('issued_on');
                $table->date('due_on')->nullable();
                $table->char('currency_code', 3);
                $table->unsignedBigInteger(
                    'merchandise_total_minor'
                );
                $table->unsignedBigInteger(
                    'logistics_amount_minor'
                )->default(0);
                $table->unsignedBigInteger('total_minor');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->text('notes')->nullable();
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'supplier_invoice_org_idem_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'supplier_id',
                        'normalized_document_number',
                    ],
                    'supplier_invoice_org_supplier_doc_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_order_id',
                        'issued_on',
                    ],
                    'supplier_invoice_order_date_index'
                );
            }
        );

        Schema::create(
            'supplier_invoice_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('supplier_invoice_id')
                    ->constrained('supplier_invoices')
                    ->restrictOnDelete();
                $table->foreignId('purchase_order_id')
                    ->constrained('purchase_orders')
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_order_line_id'
                )
                    ->nullable()
                    ->constrained('purchase_order_lines')
                    ->restrictOnDelete();
                $table->unsignedInteger('sequence');
                $table->foreignId('catalog_product_id')
                    ->nullable()
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->string(
                    'supplier_code',
                    100
                )->nullable();
                $table->string('description', 255);
                $table->decimal('quantity', 18, 6);
                $table->unsignedBigInteger(
                    'unit_cost_minor'
                );
                $table->unsignedBigInteger(
                    'subtotal_minor'
                );

                $table->unique(
                    [
                        'supplier_invoice_id',
                        'sequence',
                    ],
                    'supplier_invoice_line_sequence_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_order_line_id',
                    ],
                    'supplier_invoice_order_line_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'Supplier invoice facts are append-only and this migration is intentionally irreversible.'
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
CREATE TRIGGER supplier_invoice_guard_insert
BEFORE INSERT ON supplier_invoices
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM purchase_orders po
            WHERE po.id = NEW.purchase_order_id
              AND po.organization_id = NEW.organization_id
              AND po.supplier_id = NEW.supplier_id
              AND po.status IN ('issued', 'partially_received', 'received')
              AND po.currency_code = NEW.currency_code
        )
        THEN RAISE(ABORT, 'supplier_invoice_order_invalid')
    END;

    SELECT CASE
        WHEN NEW.total_minor <= 0
          OR NEW.total_minor != (
              NEW.merchandise_total_minor
              + NEW.logistics_amount_minor
          )
          OR length(NEW.normalized_document_number) = 0
          OR length(NEW.fingerprint) != 64
          OR length(NEW.idempotency_key) = 0
          OR (
              NEW.due_on IS NOT NULL
              AND date(NEW.due_on) < date(NEW.issued_on)
          )
        THEN RAISE(ABORT, 'supplier_invoice_money_or_identity_invalid')
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_guard_update
BEFORE UPDATE ON supplier_invoices
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'supplier_invoice_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_guard_delete
BEFORE DELETE ON supplier_invoices
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'supplier_invoice_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_line_guard_insert
BEFORE INSERT ON supplier_invoice_lines
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM supplier_invoices si
            WHERE si.id = NEW.supplier_invoice_id
              AND si.organization_id = NEW.organization_id
              AND si.purchase_order_id = NEW.purchase_order_id
        )
        THEN RAISE(ABORT, 'supplier_invoice_line_parent_invalid')
    END;

    SELECT CASE
        WHEN NEW.purchase_order_line_id IS NOT NULL
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_order_lines pol
              WHERE pol.id = NEW.purchase_order_line_id
                AND pol.organization_id = NEW.organization_id
                AND pol.purchase_order_id = NEW.purchase_order_id
                AND pol.catalog_product_id = NEW.catalog_product_id
          )
        THEN RAISE(ABORT, 'supplier_invoice_line_order_link_invalid')
    END;

    SELECT CASE
        WHEN NEW.purchase_order_line_id IS NULL
          AND NEW.catalog_product_id IS NOT NULL
        THEN RAISE(ABORT, 'supplier_invoice_line_unmatched_product_invalid')
    END;

    SELECT CASE
        WHEN CAST(NEW.quantity AS REAL) <= 0
          OR NEW.sequence <= 0
          OR NEW.subtotal_minor < 0
          OR NEW.unit_cost_minor < 0
          OR length(trim(NEW.description)) = 0
        THEN RAISE(ABORT, 'supplier_invoice_line_value_invalid')
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_line_guard_update
BEFORE UPDATE ON supplier_invoice_lines
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'supplier_invoice_line_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_line_guard_delete
BEFORE DELETE ON supplier_invoice_lines
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'supplier_invoice_line_immutable');
END;
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_guard_insert
BEFORE INSERT ON supplier_invoices
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM purchase_orders po
        WHERE po.id = NEW.purchase_order_id
          AND po.organization_id = NEW.organization_id
          AND po.supplier_id = NEW.supplier_id
          AND po.status IN ('issued', 'partially_received', 'received')
          AND po.currency_code = NEW.currency_code
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_invoice_order_invalid';
    END IF;

    IF NEW.total_minor <= 0
       OR NEW.total_minor != (
           NEW.merchandise_total_minor
           + NEW.logistics_amount_minor
       )
       OR CHAR_LENGTH(NEW.normalized_document_number) = 0
       OR CHAR_LENGTH(NEW.fingerprint) != 64
       OR CHAR_LENGTH(NEW.idempotency_key) = 0
       OR (
           NEW.due_on IS NOT NULL
           AND NEW.due_on < NEW.issued_on
       )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_invoice_money_or_identity_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_guard_update
BEFORE UPDATE ON supplier_invoices
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'supplier_invoice_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_guard_delete
BEFORE DELETE ON supplier_invoices
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'supplier_invoice_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_line_guard_insert
BEFORE INSERT ON supplier_invoice_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM supplier_invoices si
        WHERE si.id = NEW.supplier_invoice_id
          AND si.organization_id = NEW.organization_id
          AND si.purchase_order_id = NEW.purchase_order_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_invoice_line_parent_invalid';
    END IF;

    IF NEW.purchase_order_line_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1
           FROM purchase_order_lines pol
           WHERE pol.id = NEW.purchase_order_line_id
             AND pol.organization_id = NEW.organization_id
             AND pol.purchase_order_id = NEW.purchase_order_id
             AND pol.catalog_product_id = NEW.catalog_product_id
       )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_invoice_line_order_link_invalid';
    END IF;

    IF NEW.purchase_order_line_id IS NULL
       AND NEW.catalog_product_id IS NOT NULL
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_invoice_line_unmatched_product_invalid';
    END IF;

    IF NEW.quantity <= 0
       OR NEW.sequence <= 0
       OR NEW.subtotal_minor < 0
       OR NEW.unit_cost_minor < 0
       OR CHAR_LENGTH(TRIM(NEW.description)) = 0
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'supplier_invoice_line_value_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_line_guard_update
BEFORE UPDATE ON supplier_invoice_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'supplier_invoice_line_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_invoice_line_guard_delete
BEFORE DELETE ON supplier_invoice_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'supplier_invoice_line_immutable';
END
SQL);
    }
};
