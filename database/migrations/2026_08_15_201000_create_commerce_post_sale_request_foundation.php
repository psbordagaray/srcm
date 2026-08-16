<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUEST_INSERT =
        'commerce_post_sale_requests_guard_insert';
    private const REQUEST_UPDATE =
        'commerce_post_sale_requests_guard_update';
    private const REQUEST_DELETE =
        'commerce_post_sale_requests_guard_delete';
    private const LINE_INSERT =
        'commerce_post_sale_request_lines_guard_insert';
    private const LINE_UPDATE =
        'commerce_post_sale_request_lines_guard_update';
    private const LINE_DELETE =
        'commerce_post_sale_request_lines_guard_delete';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_requests',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('commerce_sale_id')
                    ->constrained('commerce_sales')
                    ->restrictOnDelete();
                $table->string('intent', 24);
                $table->string('reason', 500);
                $table->text('notes')->nullable();
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('requested_at');
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'commerce_post_sale_requests_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'commerce_sale_id',
                        'requested_at',
                    ],
                    'commerce_post_sale_requests_sale_index'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_request_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_request_id'
                )
                    ->constrained('commerce_post_sale_requests')
                    ->restrictOnDelete();
                $table->foreignId('commerce_sale_line_id')
                    ->constrained('commerce_sale_lines')
                    ->restrictOnDelete();
                $table->decimal('quantity', 18, 6);
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'commerce_post_sale_request_id',
                        'commerce_sale_line_id',
                    ],
                    'commerce_post_sale_request_lines_unique'
                );
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'commerce_post_sale_request_lines'
        );
        Schema::dropIfExists(
            'commerce_post_sale_requests'
        );
    }

    private function createTriggers(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTriggers();
            return;
        }

        if (
            in_array(
                DB::getDriverName(),
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlTriggers();
            return;
        }

        throw new LogicException(
            'La integridad P8.1 no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach ([
            self::LINE_DELETE,
            self::LINE_UPDATE,
            self::LINE_INSERT,
            self::REQUEST_DELETE,
            self::REQUEST_UPDATE,
            self::REQUEST_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_requests_guard_insert
BEFORE INSERT ON commerce_post_sale_requests
WHEN NEW.intent NOT IN ('return', 'exchange')
    OR LENGTH(TRIM(NEW.reason)) < 10
    OR LENGTH(NEW.reason) > 500
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.requested_by_user_id IS NULL
    OR NEW.requested_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        WHERE sale.id = NEW.commerce_sale_id
          AND sale.organization_id = NEW.organization_id
          AND sale.status = 'confirmed'
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
            NEW.organization_id
          AND membership.user_id =
            NEW.requested_by_user_id
          AND membership.active = 1
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La solicitud de posventa no conserva venta, autoridad o contenido válido.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_requests_guard_update
BEFORE UPDATE ON commerce_post_sale_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'Una solicitud de posventa confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_requests_guard_delete
BEFORE DELETE ON commerce_post_sale_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'Una solicitud de posventa confirmada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_request_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_request_lines
WHEN CAST(NEW.quantity AS NUMERIC) <= 0
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_requests request
        INNER JOIN commerce_sale_lines sale_line
            ON sale_line.id = NEW.commerce_sale_line_id
        WHERE request.id =
                NEW.commerce_post_sale_request_id
          AND request.organization_id =
                NEW.organization_id
          AND sale_line.organization_id =
                NEW.organization_id
          AND sale_line.commerce_sale_id =
                request.commerce_sale_id
          AND sale_line.line_type = 'product'
          AND sale_line.catalog_product_id IS NOT NULL
          AND CAST(NEW.quantity AS NUMERIC)
                <= CAST(sale_line.quantity AS NUMERIC)
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea de posventa no conserva producto, venta o cantidad válida.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_request_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_request_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de solicitud de posventa es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_request_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_request_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de solicitud de posventa no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_requests_guard_insert
BEFORE INSERT ON commerce_post_sale_requests
FOR EACH ROW
BEGIN
    IF NEW.intent NOT IN ('return', 'exchange')
        OR CHAR_LENGTH(TRIM(NEW.reason)) < 10
        OR CHAR_LENGTH(NEW.reason) > 500
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.requested_by_user_id IS NULL
        OR NEW.requested_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            WHERE sale.id = NEW.commerce_sale_id
              AND sale.organization_id =
                  NEW.organization_id
              AND sale.status = 'confirmed'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                NEW.organization_id
              AND membership.user_id =
                NEW.requested_by_user_id
              AND membership.active = 1
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La solicitud de posventa no conserva venta, autoridad o contenido válido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_requests_guard_update
BEFORE UPDATE ON commerce_post_sale_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una solicitud de posventa confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_requests_guard_delete
BEFORE DELETE ON commerce_post_sale_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una solicitud de posventa confirmada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_request_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_request_lines
FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_requests request
            INNER JOIN commerce_sale_lines sale_line
                ON sale_line.id =
                    NEW.commerce_sale_line_id
            WHERE request.id =
                    NEW.commerce_post_sale_request_id
              AND request.organization_id =
                    NEW.organization_id
              AND sale_line.organization_id =
                    NEW.organization_id
              AND sale_line.commerce_sale_id =
                    request.commerce_sale_id
              AND sale_line.line_type = 'product'
              AND sale_line.catalog_product_id IS NOT NULL
              AND NEW.quantity <= sale_line.quantity
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La línea de posventa no conserva producto, venta o cantidad válida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_request_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_request_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de solicitud de posventa es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_request_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_request_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de solicitud de posventa no puede eliminarse.';
END
SQL);
    }
};
