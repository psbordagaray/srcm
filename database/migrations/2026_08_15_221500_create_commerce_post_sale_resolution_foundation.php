<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESOLUTION_INSERT =
        'commerce_post_sale_resolutions_guard_insert';
    private const RESOLUTION_UPDATE =
        'commerce_post_sale_resolutions_guard_update';
    private const RESOLUTION_DELETE =
        'commerce_post_sale_resolutions_guard_delete';
    private const LINE_INSERT =
        'commerce_post_sale_resolution_lines_guard_insert';
    private const LINE_UPDATE =
        'commerce_post_sale_resolution_lines_guard_update';
    private const LINE_DELETE =
        'commerce_post_sale_resolution_lines_guard_delete';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_resolutions',
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
                $table->string('outcome', 32);
                $table->char('currency_code', 3);
                $table->foreignId(
                    'preferred_original_payment_id'
                )
                    ->nullable()
                    ->constrained('commerce_payments')
                    ->restrictOnDelete();
                $table->string('reason', 1000);
                $table->text('notes')->nullable();
                $table->foreignId(
                    'resolved_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('resolved_at');
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'commerce_post_sale_resolutions_org_idem_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'commerce_post_sale_request_id',
                        'resolved_at',
                    ],
                    'commerce_post_sale_resolutions_request_index'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_resolution_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_resolution_id'
                )
                    ->constrained(
                        'commerce_post_sale_resolutions'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_receipt_line_id'
                )
                    ->constrained(
                        'commerce_post_sale_receipt_lines'
                    )
                    ->restrictOnDelete();
                $table->decimal('quantity', 18, 6);
                $table->unsignedBigInteger(
                    'baseline_amount_minor'
                );
                $table->unsignedBigInteger(
                    'recognized_amount_minor'
                );
                $table->string(
                    'adjustment_reason',
                    1000
                )->nullable();
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'commerce_post_sale_resolution_id',
                        'commerce_post_sale_receipt_line_id',
                    ],
                    'commerce_post_sale_resolution_lines_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'commerce_post_sale_receipt_line_id',
                    ],
                    'commerce_post_sale_resolution_lines_receipt_index'
                );
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'commerce_post_sale_resolution_lines'
        );
        Schema::dropIfExists(
            'commerce_post_sale_resolutions'
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
            'La integridad P8.3 no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach ([
            self::LINE_DELETE,
            self::LINE_UPDATE,
            self::LINE_INSERT,
            self::RESOLUTION_DELETE,
            self::RESOLUTION_UPDATE,
            self::RESOLUTION_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolutions_guard_insert
BEFORE INSERT ON commerce_post_sale_resolutions
WHEN NEW.outcome NOT IN (
        'refund',
        'customer_credit',
        'exchange'
    )
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code) <> NEW.currency_code
    OR LENGTH(TRIM(NEW.reason)) < 10
    OR LENGTH(NEW.reason) > 1000
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.resolved_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_requests ps_request
        INNER JOIN commerce_sales sale
            ON sale.id = ps_request.commerce_sale_id
        WHERE ps_request.id =
                NEW.commerce_post_sale_request_id
          AND ps_request.organization_id =
                NEW.organization_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.status = 'confirmed'
          AND sale.currency_code =
                NEW.currency_code
          AND (
              NEW.outcome <> 'customer_credit'
              OR sale.customer_business_party_id
                    IS NOT NULL
          )
          AND (
              NEW.preferred_original_payment_id
                    IS NULL
              OR (
                  NEW.outcome = 'refund'
                  AND EXISTS (
                      SELECT 1
                      FROM commerce_payments payment
                      WHERE payment.id =
                            NEW.preferred_original_payment_id
                        AND payment.organization_id =
                            NEW.organization_id
                        AND payment.commerce_sale_id =
                            sale.id
                  )
              )
          )
          AND (
              NEW.outcome = 'refund'
              OR NEW.preferred_original_payment_id
                    IS NULL
          )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.resolved_by_user_id
          AND membership.active = 1
          AND membership.role = 'admin'
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La resolución de posventa no conserva venta, cliente, moneda, autoridad o medio original válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolutions_guard_update
BEFORE UPDATE ON commerce_post_sale_resolutions
BEGIN
    SELECT RAISE(
        ABORT,
        'Una resolución de posventa confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolutions_guard_delete
BEFORE DELETE ON commerce_post_sale_resolutions
BEGIN
    SELECT RAISE(
        ABORT,
        'Una resolución de posventa confirmada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolution_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_resolution_lines
WHEN CAST(NEW.quantity AS NUMERIC) <= 0
    OR NEW.baseline_amount_minor < 0
    OR NEW.recognized_amount_minor < 0
    OR NEW.recognized_amount_minor >
        NEW.baseline_amount_minor
    OR (
        NEW.recognized_amount_minor <
            NEW.baseline_amount_minor
        AND (
            NEW.adjustment_reason IS NULL
            OR LENGTH(TRIM(NEW.adjustment_reason)) < 10
            OR LENGTH(NEW.adjustment_reason) > 1000
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_resolutions resolution
        INNER JOIN commerce_post_sale_requests ps_request
            ON ps_request.id =
                resolution.commerce_post_sale_request_id
        INNER JOIN commerce_post_sale_receipt_lines receipt_line
            ON receipt_line.id =
                NEW.commerce_post_sale_receipt_line_id
        INNER JOIN commerce_post_sale_receipts receipt
            ON receipt.id =
                receipt_line.commerce_post_sale_receipt_id
        INNER JOIN commerce_post_sale_request_lines request_line
            ON request_line.id =
                receipt_line.commerce_post_sale_request_line_id
        INNER JOIN commerce_sale_lines sale_line
            ON sale_line.id =
                request_line.commerce_sale_line_id
        WHERE resolution.id =
                NEW.commerce_post_sale_resolution_id
          AND resolution.organization_id =
                NEW.organization_id
          AND ps_request.organization_id =
                NEW.organization_id
          AND receipt_line.organization_id =
                NEW.organization_id
          AND receipt.organization_id =
                NEW.organization_id
          AND request_line.organization_id =
                NEW.organization_id
          AND sale_line.organization_id =
                NEW.organization_id
          AND receipt.commerce_post_sale_request_id =
                ps_request.id
          AND request_line.commerce_post_sale_request_id =
                ps_request.id
          AND sale_line.line_type = 'product'
          AND sale_line.catalog_product_id IS NOT NULL
          AND CAST(NEW.baseline_amount_minor AS NUMERIC) =
              CAST(sale_line.unit_price_minor AS NUMERIC)
              * CAST(NEW.quantity AS NUMERIC)
          AND (
              CAST(NEW.quantity AS NUMERIC)
              + COALESCE(
                  (
                      SELECT SUM(
                          CAST(previous.quantity AS NUMERIC)
                      )
                      FROM commerce_post_sale_resolution_lines previous
                      WHERE previous.organization_id =
                            NEW.organization_id
                        AND previous.commerce_post_sale_receipt_line_id =
                            NEW.commerce_post_sale_receipt_line_id
                  ),
                  0
              )
          ) <= CAST(receipt_line.quantity AS NUMERIC)
          AND (
              resolution.preferred_original_payment_id
                    IS NULL
              OR (
                  NEW.recognized_amount_minor
                  + COALESCE(
                      (
                          SELECT SUM(
                              previous_value.recognized_amount_minor
                          )
                          FROM commerce_post_sale_resolution_lines previous_value
                          WHERE previous_value.commerce_post_sale_resolution_id =
                                resolution.id
                      ),
                      0
                  )
              ) <= (
                  SELECT payment.amount_minor
                  FROM commerce_payments payment
                  WHERE payment.id =
                        resolution.preferred_original_payment_id
              )
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea resuelta no conserva recepción, valor original, ajuste o límite acumulado válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolution_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_resolution_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de resolución de posventa es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolution_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_resolution_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de resolución de posventa no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolutions_guard_insert
BEFORE INSERT ON commerce_post_sale_resolutions
FOR EACH ROW
BEGIN
    IF NEW.outcome NOT IN (
            'refund',
            'customer_credit',
            'exchange'
        )
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR UPPER(NEW.currency_code) <> NEW.currency_code
        OR CHAR_LENGTH(TRIM(NEW.reason)) < 10
        OR CHAR_LENGTH(NEW.reason) > 1000
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.resolved_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_requests ps_request
            INNER JOIN commerce_sales sale
                ON sale.id = ps_request.commerce_sale_id
            WHERE ps_request.id =
                    NEW.commerce_post_sale_request_id
              AND ps_request.organization_id =
                    NEW.organization_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.status = 'confirmed'
              AND sale.currency_code =
                    NEW.currency_code
              AND (
                  NEW.outcome <> 'customer_credit'
                  OR sale.customer_business_party_id
                        IS NOT NULL
              )
              AND (
                  NEW.preferred_original_payment_id
                        IS NULL
                  OR (
                      NEW.outcome = 'refund'
                      AND EXISTS (
                          SELECT 1
                          FROM commerce_payments payment
                          WHERE payment.id =
                                NEW.preferred_original_payment_id
                            AND payment.organization_id =
                                NEW.organization_id
                            AND payment.commerce_sale_id =
                                sale.id
                      )
                  )
              )
              AND (
                  NEW.outcome = 'refund'
                  OR NEW.preferred_original_payment_id
                        IS NULL
              )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.resolved_by_user_id
              AND membership.active = 1
              AND membership.role = 'admin'
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La resolución de posventa no conserva venta, cliente, moneda, autoridad o medio original válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolutions_guard_update
BEFORE UPDATE ON commerce_post_sale_resolutions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una resolución de posventa confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolutions_guard_delete
BEFORE DELETE ON commerce_post_sale_resolutions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una resolución de posventa confirmada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolution_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_resolution_lines
FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0
        OR NEW.baseline_amount_minor < 0
        OR NEW.recognized_amount_minor < 0
        OR NEW.recognized_amount_minor >
            NEW.baseline_amount_minor
        OR (
            NEW.recognized_amount_minor <
                NEW.baseline_amount_minor
            AND (
                NEW.adjustment_reason IS NULL
                OR CHAR_LENGTH(TRIM(NEW.adjustment_reason)) < 10
                OR CHAR_LENGTH(NEW.adjustment_reason) > 1000
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_resolutions resolution
            INNER JOIN commerce_post_sale_requests ps_request
                ON ps_request.id =
                    resolution.commerce_post_sale_request_id
            INNER JOIN commerce_post_sale_receipt_lines receipt_line
                ON receipt_line.id =
                    NEW.commerce_post_sale_receipt_line_id
            INNER JOIN commerce_post_sale_receipts receipt
                ON receipt.id =
                    receipt_line.commerce_post_sale_receipt_id
            INNER JOIN commerce_post_sale_request_lines request_line
                ON request_line.id =
                    receipt_line.commerce_post_sale_request_line_id
            INNER JOIN commerce_sale_lines sale_line
                ON sale_line.id =
                    request_line.commerce_sale_line_id
            WHERE resolution.id =
                    NEW.commerce_post_sale_resolution_id
              AND resolution.organization_id =
                    NEW.organization_id
              AND ps_request.organization_id =
                    NEW.organization_id
              AND receipt_line.organization_id =
                    NEW.organization_id
              AND receipt.organization_id =
                    NEW.organization_id
              AND request_line.organization_id =
                    NEW.organization_id
              AND sale_line.organization_id =
                    NEW.organization_id
              AND receipt.commerce_post_sale_request_id =
                    ps_request.id
              AND request_line.commerce_post_sale_request_id =
                    ps_request.id
              AND sale_line.line_type = 'product'
              AND sale_line.catalog_product_id IS NOT NULL
              AND NEW.baseline_amount_minor =
                    sale_line.unit_price_minor
                    * NEW.quantity
              AND (
                  NEW.quantity
                  + COALESCE(
                      (
                          SELECT SUM(previous.quantity)
                          FROM commerce_post_sale_resolution_lines previous
                          WHERE previous.organization_id =
                                NEW.organization_id
                            AND previous.commerce_post_sale_receipt_line_id =
                                NEW.commerce_post_sale_receipt_line_id
                      ),
                      0
                  )
              ) <= receipt_line.quantity
              AND (
                  resolution.preferred_original_payment_id
                        IS NULL
                  OR (
                      NEW.recognized_amount_minor
                      + COALESCE(
                          (
                              SELECT SUM(
                                  previous_value.recognized_amount_minor
                              )
                              FROM commerce_post_sale_resolution_lines previous_value
                              WHERE previous_value.commerce_post_sale_resolution_id =
                                    resolution.id
                          ),
                          0
                      )
                  ) <= (
                      SELECT payment.amount_minor
                      FROM commerce_payments payment
                      WHERE payment.id =
                            resolution.preferred_original_payment_id
                  )
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La línea resuelta no conserva recepción, valor original, ajuste o límite acumulado válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolution_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_resolution_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de resolución de posventa es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_resolution_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_resolution_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de resolución de posventa no puede eliminarse.';
END
SQL);
    }
};
