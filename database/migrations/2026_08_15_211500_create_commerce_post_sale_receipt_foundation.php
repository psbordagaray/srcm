<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECEIPT_INSERT =
        'commerce_post_sale_receipts_guard_insert';
    private const RECEIPT_UPDATE =
        'commerce_post_sale_receipts_guard_update';
    private const RECEIPT_DELETE =
        'commerce_post_sale_receipts_guard_delete';
    private const LINE_INSERT =
        'commerce_post_sale_receipt_lines_guard_insert';
    private const LINE_UPDATE =
        'commerce_post_sale_receipt_lines_guard_update';
    private const LINE_DELETE =
        'commerce_post_sale_receipt_lines_guard_delete';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_receipts',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'commerce_post_sale_request_id'
                )
                    ->constrained(
                        'commerce_post_sale_requests'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'inventory_movement_id'
                )
                    ->unique()
                    ->constrained(
                        'inventory_movements'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'received_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('received_at');
                $table->text('notes')->nullable();
                $table->string(
                    'idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'commerce_post_sale_receipts_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'commerce_post_sale_request_id',
                        'received_at',
                    ],
                    'commerce_post_sale_receipts_request_index'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_receipt_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_receipt_id'
                )
                    ->constrained(
                        'commerce_post_sale_receipts'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_request_line_id'
                )
                    ->constrained(
                        'commerce_post_sale_request_lines'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'inventory_movement_line_id'
                )
                    ->unique()
                    ->constrained(
                        'inventory_movement_lines'
                    )
                    ->restrictOnDelete();
                $table->decimal(
                    'quantity',
                    18,
                    6
                );
                $table->string(
                    'condition',
                    32
                );
                $table->foreignId(
                    'destination_location_id'
                )
                    ->constrained(
                        'inventory_locations'
                    )
                    ->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'commerce_post_sale_receipt_id',
                        'commerce_post_sale_request_line_id',
                    ],
                    'commerce_post_sale_receipt_lines_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'commerce_post_sale_request_line_id',
                    ],
                    'commerce_post_sale_receipt_lines_request_line_index'
                );
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'commerce_post_sale_receipt_lines'
        );
        Schema::dropIfExists(
            'commerce_post_sale_receipts'
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
            'La integridad P8.2 no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach ([
            self::LINE_DELETE,
            self::LINE_UPDATE,
            self::LINE_INSERT,
            self::RECEIPT_DELETE,
            self::RECEIPT_UPDATE,
            self::RECEIPT_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipts_guard_insert
BEFORE INSERT ON commerce_post_sale_receipts
WHEN LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.received_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_requests ps_request
        WHERE ps_request.id =
                NEW.commerce_post_sale_request_id
          AND ps_request.organization_id =
                NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.received_by_user_id
          AND membership.active = 1
    )
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_movements movement
        WHERE movement.id =
                NEW.inventory_movement_id
          AND movement.organization_id =
                NEW.organization_id
          AND movement.type = 'customer_return'
          AND movement.status = 'confirmed'
          AND movement.source_type =
                'commerce_post_sale_receipt'
          AND movement.source_id =
                NEW.public_id
          AND movement.created_by_user_id =
                NEW.received_by_user_id
          AND movement.confirmed_by_user_id =
                NEW.received_by_user_id
          AND EXISTS (
              SELECT 1
              FROM inventory_movement_lines movement_line
              WHERE movement_line.inventory_movement_id =
                    movement.id
                AND movement_line.organization_id =
                    NEW.organization_id
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La recepción física no conserva solicitud, actor o CustomerReturn confirmado válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipts_guard_update
BEFORE UPDATE ON commerce_post_sale_receipts
BEGIN
    SELECT RAISE(
        ABORT,
        'Una recepción física de posventa confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipts_guard_delete
BEFORE DELETE ON commerce_post_sale_receipts
BEGIN
    SELECT RAISE(
        ABORT,
        'Una recepción física de posventa confirmada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipt_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_receipt_lines
WHEN CAST(NEW.quantity AS NUMERIC) <= 0
    OR NEW.condition NOT IN (
        'new',
        'used',
        'refurbished',
        'damaged',
        'display'
    )
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_locations location
        WHERE location.id =
                NEW.destination_location_id
          AND location.organization_id =
                NEW.organization_id
          AND location.active = 1
    )
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_receipts receipt
        INNER JOIN commerce_post_sale_request_lines request_line
            ON request_line.id =
                NEW.commerce_post_sale_request_line_id
        INNER JOIN commerce_sale_lines sale_line
            ON sale_line.id =
                request_line.commerce_sale_line_id
        INNER JOIN inventory_movement_lines movement_line
            ON movement_line.id =
                NEW.inventory_movement_line_id
        WHERE receipt.id =
                NEW.commerce_post_sale_receipt_id
          AND receipt.organization_id =
                NEW.organization_id
          AND request_line.organization_id =
                NEW.organization_id
          AND request_line.commerce_post_sale_request_id =
                receipt.commerce_post_sale_request_id
          AND sale_line.organization_id =
                NEW.organization_id
          AND sale_line.line_type = 'product'
          AND sale_line.catalog_product_id IS NOT NULL
          AND movement_line.organization_id =
                NEW.organization_id
          AND movement_line.inventory_movement_id =
                receipt.inventory_movement_id
          AND movement_line.catalog_product_id =
                sale_line.catalog_product_id
          AND movement_line.condition =
                NEW.condition
          AND movement_line.source_location_id IS NULL
          AND movement_line.destination_location_id =
                NEW.destination_location_id
          AND CAST(movement_line.base_quantity AS NUMERIC) =
                CAST(NEW.quantity AS NUMERIC)
          AND (
              CAST(NEW.quantity AS NUMERIC)
              + COALESCE(
                  (
                      SELECT SUM(
                          CAST(previous.quantity AS NUMERIC)
                      )
                      FROM commerce_post_sale_receipt_lines previous
                      WHERE previous.organization_id =
                            NEW.organization_id
                        AND previous.commerce_post_sale_request_line_id =
                            NEW.commerce_post_sale_request_line_id
                  ),
                  0
              )
          ) <= CAST(request_line.quantity AS NUMERIC)
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea recibida no conserva solicitud, movimiento, condición, ubicación o cantidad acumulada válidas.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipt_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_receipt_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de recepción física de posventa es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipt_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_receipt_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de recepción física de posventa no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipts_guard_insert
BEFORE INSERT ON commerce_post_sale_receipts
FOR EACH ROW
BEGIN
    IF CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.received_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_requests ps_request
            WHERE ps_request.id =
                    NEW.commerce_post_sale_request_id
              AND ps_request.organization_id =
                    NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.received_by_user_id
              AND membership.active = 1
        )
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_movements movement
            WHERE movement.id =
                    NEW.inventory_movement_id
              AND movement.organization_id =
                    NEW.organization_id
              AND movement.type = 'customer_return'
              AND movement.status = 'confirmed'
              AND movement.source_type =
                    'commerce_post_sale_receipt'
              AND movement.source_id =
                    NEW.public_id
              AND movement.created_by_user_id =
                    NEW.received_by_user_id
              AND movement.confirmed_by_user_id =
                    NEW.received_by_user_id
              AND EXISTS (
                  SELECT 1
                  FROM inventory_movement_lines movement_line
                  WHERE movement_line.inventory_movement_id =
                        movement.id
                    AND movement_line.organization_id =
                        NEW.organization_id
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La recepción física no conserva solicitud, actor o CustomerReturn confirmado válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipts_guard_update
BEFORE UPDATE ON commerce_post_sale_receipts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una recepción física de posventa confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipts_guard_delete
BEFORE DELETE ON commerce_post_sale_receipts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una recepción física de posventa confirmada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipt_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_receipt_lines
FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0
        OR NEW.condition NOT IN (
            'new',
            'used',
            'refurbished',
            'damaged',
            'display'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_locations location
            WHERE location.id =
                    NEW.destination_location_id
              AND location.organization_id =
                    NEW.organization_id
              AND location.active = 1
        )
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_receipts receipt
            INNER JOIN commerce_post_sale_request_lines request_line
                ON request_line.id =
                    NEW.commerce_post_sale_request_line_id
            INNER JOIN commerce_sale_lines sale_line
                ON sale_line.id =
                    request_line.commerce_sale_line_id
            INNER JOIN inventory_movement_lines movement_line
                ON movement_line.id =
                    NEW.inventory_movement_line_id
            WHERE receipt.id =
                    NEW.commerce_post_sale_receipt_id
              AND receipt.organization_id =
                    NEW.organization_id
              AND request_line.organization_id =
                    NEW.organization_id
              AND request_line.commerce_post_sale_request_id =
                    receipt.commerce_post_sale_request_id
              AND sale_line.organization_id =
                    NEW.organization_id
              AND sale_line.line_type = 'product'
              AND sale_line.catalog_product_id IS NOT NULL
              AND movement_line.organization_id =
                    NEW.organization_id
              AND movement_line.inventory_movement_id =
                    receipt.inventory_movement_id
              AND movement_line.catalog_product_id =
                    sale_line.catalog_product_id
              AND movement_line.condition =
                    NEW.condition
              AND movement_line.source_location_id IS NULL
              AND movement_line.destination_location_id =
                    NEW.destination_location_id
              AND movement_line.base_quantity =
                    NEW.quantity
              AND (
                  NEW.quantity
                  + COALESCE(
                      (
                          SELECT SUM(previous.quantity)
                          FROM commerce_post_sale_receipt_lines previous
                          WHERE previous.organization_id =
                                NEW.organization_id
                            AND previous.commerce_post_sale_request_line_id =
                                NEW.commerce_post_sale_request_line_id
                      ),
                      0
                  )
              ) <= request_line.quantity
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La línea recibida no conserva solicitud, movimiento, condición, ubicación o cantidad acumulada válidas.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipt_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_receipt_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de recepción física de posventa es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_receipt_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_receipt_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de recepción física de posventa no puede eliminarse.';
END
SQL);
    }
};
