<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'commerce_sales_guard_insert',
        'commerce_sales_guard_update',
        'commerce_sales_guard_delete',
        'commerce_sale_lines_guard_insert',
        'commerce_sale_lines_guard_update',
        'commerce_sale_lines_guard_delete',
        'commerce_payments_guard_insert',
        'commerce_payments_guard_update',
        'commerce_payments_guard_delete',
    ];

    public function up(): void
    {
        $this->dropTriggers();
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            "La integridad comercial no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropTriggers();
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sales_guard_insert
BEFORE INSERT ON commerce_sales
WHEN NEW.status <> 'building'
    OR NEW.confirmed_at IS NOT NULL
    OR NEW.total_minor <> NEW.service_subtotal_minor + NEW.product_subtotal_minor
    OR NEW.total_minor < 1
    OR NOT (
        (
            NEW.service_order_id IS NULL
            AND NEW.service_delivery_id IS NULL
            AND NEW.service_quote_decision_id IS NULL
            AND NEW.service_quote_option_id IS NULL
            AND NEW.service_subtotal_minor = 0
        )
        OR EXISTS (
            SELECT 1
            FROM service_orders orders
            INNER JOIN service_deliveries delivery
                ON delivery.id = NEW.service_delivery_id
                AND delivery.organization_id = NEW.organization_id
                AND delivery.service_order_id = orders.id
            INNER JOIN service_quote_decisions decision
                ON decision.id = NEW.service_quote_decision_id
                AND decision.organization_id = NEW.organization_id
                AND decision.decision = 'approved'
            INNER JOIN service_quotes quote
                ON quote.id = decision.service_quote_id
                AND quote.organization_id = decision.organization_id
                AND quote.service_order_id = orders.id
                AND quote.currency_code = NEW.currency_code
            INNER JOIN service_quote_options quote_option
                ON quote_option.id = NEW.service_quote_option_id
                AND quote_option.organization_id = decision.organization_id
                AND quote_option.id = decision.service_quote_option_id
                AND quote_option.service_quote_id = quote.id
                AND quote_option.total_minor = NEW.service_subtotal_minor
            WHERE orders.id = NEW.service_order_id
                AND orders.organization_id = NEW.organization_id
                AND orders.status = 'delivered'
                AND (
                    orders.customer_business_party_id IS NULL
                    OR orders.customer_business_party_id = NEW.customer_business_party_id
                )
                AND quote.revision = (
                    SELECT MAX(latest.revision)
                    FROM service_quotes latest
                    WHERE latest.organization_id = NEW.organization_id
                        AND latest.service_order_id = orders.id
                )
        )
    )
    OR NOT (
        (
            NEW.inventory_movement_id IS NULL
            AND NEW.product_subtotal_minor = 0
        )
        OR EXISTS (
            SELECT 1
            FROM inventory_movements movement
            WHERE movement.id = NEW.inventory_movement_id
                AND movement.organization_id = NEW.organization_id
                AND movement.type = 'issue'
                AND movement.status = 'confirmed'
                AND movement.source_type = 'commerce_sale'
                AND movement.source_id = NEW.public_id
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La cabecera comercial carece de evidencia válida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sale_lines_guard_insert
BEFORE INSERT ON commerce_sale_lines
WHEN NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        WHERE sale.id = NEW.commerce_sale_id
            AND sale.organization_id = NEW.organization_id
            AND sale.status = 'building'
    )
    OR NEW.quantity <= 0
    OR NEW.line_total_minor <> NEW.quantity * NEW.unit_price_minor
    OR NOT (
        (
            NEW.line_type = 'service'
            AND NEW.catalog_product_id IS NULL
            AND NEW.inventory_movement_line_id IS NULL
            AND EXISTS (
                SELECT 1
                FROM commerce_sales sale
                INNER JOIN service_quote_lines quote_line
                    ON quote_line.id = NEW.service_quote_line_id
                    AND quote_line.organization_id = NEW.organization_id
                    AND quote_line.service_quote_option_id = sale.service_quote_option_id
                    AND quote_line.description = NEW.description
                    AND quote_line.quantity = NEW.quantity
                    AND quote_line.unit_price_minor = NEW.unit_price_minor
                    AND quote_line.line_total_minor = NEW.line_total_minor
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id = NEW.organization_id
            )
        )
        OR (
            NEW.line_type = 'product'
            AND NEW.service_quote_line_id IS NULL
            AND NEW.catalog_product_id IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM commerce_sales sale
                INNER JOIN inventory_movement_lines movement_line
                    ON movement_line.id = NEW.inventory_movement_line_id
                    AND movement_line.organization_id = NEW.organization_id
                    AND movement_line.inventory_movement_id = sale.inventory_movement_id
                    AND movement_line.catalog_product_id = NEW.catalog_product_id
                    AND movement_line.entered_quantity = NEW.quantity
                    AND movement_line.conversion_factor = 1
                INNER JOIN catalog_products product
                    ON product.id = NEW.catalog_product_id
                    AND product.name = NEW.description
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id = NEW.organization_id
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La línea comercial no coincide con su evidencia.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
WHEN NEW.amount_minor < 1
    OR NEW.method NOT IN (
        'cash', 'debit_card', 'credit_card', 'bank_transfer',
        'digital_wallet', 'account_credit', 'other'
    )
    OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        WHERE sale.id = NEW.commerce_sale_id
            AND sale.organization_id = NEW.organization_id
            AND sale.status = 'building'
            AND NEW.paid_at <= sale.sold_at
    )
BEGIN
    SELECT RAISE(ABORT, 'El pago comercial no es válido.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sales_guard_update
BEFORE UPDATE ON commerce_sales
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
    OR OLD.sale_number <> NEW.sale_number
    OR OLD.service_order_id IS NOT NEW.service_order_id
    OR OLD.service_delivery_id IS NOT NEW.service_delivery_id
    OR OLD.service_quote_decision_id IS NOT NEW.service_quote_decision_id
    OR OLD.service_quote_option_id IS NOT NEW.service_quote_option_id
    OR OLD.customer_business_party_id IS NOT NEW.customer_business_party_id
    OR OLD.customer_name_snapshot <> NEW.customer_name_snapshot
    OR OLD.customer_document_snapshot IS NOT NEW.customer_document_snapshot
    OR OLD.currency_code <> NEW.currency_code
    OR OLD.service_subtotal_minor <> NEW.service_subtotal_minor
    OR OLD.product_subtotal_minor <> NEW.product_subtotal_minor
    OR OLD.total_minor <> NEW.total_minor
    OR OLD.inventory_movement_id IS NOT NEW.inventory_movement_id
    OR OLD.notes IS NOT NEW.notes
    OR OLD.recorded_by_user_id <> NEW.recorded_by_user_id
    OR OLD.sold_at <> NEW.sold_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.fingerprint <> NEW.fingerprint
    OR OLD.status <> 'building'
    OR NEW.status <> 'confirmed'
    OR OLD.confirmed_at IS NOT NULL
    OR NEW.confirmed_at IS NULL
    OR NOT EXISTS (
        SELECT 1 FROM commerce_sale_lines line
        WHERE line.organization_id = NEW.organization_id
            AND line.commerce_sale_id = NEW.id
    )
    OR NEW.service_subtotal_minor <> COALESCE((
        SELECT SUM(line.line_total_minor)
        FROM commerce_sale_lines line
        WHERE line.organization_id = NEW.organization_id
            AND line.commerce_sale_id = NEW.id
            AND line.line_type = 'service'
    ), 0)
    OR NEW.product_subtotal_minor <> COALESCE((
        SELECT SUM(line.line_total_minor)
        FROM commerce_sale_lines line
        WHERE line.organization_id = NEW.organization_id
            AND line.commerce_sale_id = NEW.id
            AND line.line_type = 'product'
    ), 0)
    OR NEW.total_minor <> COALESCE((
        SELECT SUM(payment.amount_minor)
        FROM commerce_payments payment
        WHERE payment.organization_id = NEW.organization_id
            AND payment.commerce_sale_id = NEW.id
    ), 0)
    OR (
        NEW.service_quote_option_id IS NOT NULL
        AND (
            SELECT COUNT(*)
            FROM commerce_sale_lines line
            WHERE line.organization_id = NEW.organization_id
                AND line.commerce_sale_id = NEW.id
                AND line.line_type = 'service'
        ) <> (
            SELECT COUNT(*)
            FROM service_quote_lines quote_line
            WHERE quote_line.organization_id = NEW.organization_id
                AND quote_line.service_quote_option_id = NEW.service_quote_option_id
        )
    )
    OR (
        NEW.inventory_movement_id IS NOT NULL
        AND (
            SELECT COUNT(*)
            FROM commerce_sale_lines line
            WHERE line.organization_id = NEW.organization_id
                AND line.commerce_sale_id = NEW.id
                AND line.line_type = 'product'
        ) <> (
            SELECT COUNT(*)
            FROM inventory_movement_lines movement_line
            WHERE movement_line.organization_id = NEW.organization_id
                AND movement_line.inventory_movement_id = NEW.inventory_movement_id
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La venta no puede confirmarse sin integridad total.');
END
SQL);

        $this->createSqliteDeleteTrigger(
            'commerce_sales_guard_delete',
            'commerce_sales'
        );
        $this->createSqliteImmutablePair(
            'commerce_sale_lines',
            'commerce_sale_lines'
        );
        $this->createSqliteImmutablePair(
            'commerce_payments',
            'commerce_payments'
        );
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sales_guard_insert
BEFORE INSERT ON commerce_sales
FOR EACH ROW
BEGIN
    IF NEW.status <> 'building'
        OR NEW.confirmed_at IS NOT NULL
        OR NEW.total_minor <> NEW.service_subtotal_minor + NEW.product_subtotal_minor
        OR NEW.total_minor < 1
        OR NOT (
            (
                NEW.service_order_id IS NULL
                AND NEW.service_delivery_id IS NULL
                AND NEW.service_quote_decision_id IS NULL
                AND NEW.service_quote_option_id IS NULL
                AND NEW.service_subtotal_minor = 0
            )
            OR EXISTS (
                SELECT 1
                FROM service_orders orders
                INNER JOIN service_deliveries delivery
                    ON delivery.id = NEW.service_delivery_id
                    AND delivery.organization_id = NEW.organization_id
                    AND delivery.service_order_id = orders.id
                INNER JOIN service_quote_decisions decision
                    ON decision.id = NEW.service_quote_decision_id
                    AND decision.organization_id = NEW.organization_id
                    AND decision.decision = 'approved'
                INNER JOIN service_quotes quote
                    ON quote.id = decision.service_quote_id
                    AND quote.organization_id = decision.organization_id
                    AND quote.service_order_id = orders.id
                    AND quote.currency_code = NEW.currency_code
                INNER JOIN service_quote_options quote_option
                    ON quote_option.id = NEW.service_quote_option_id
                    AND quote_option.organization_id = decision.organization_id
                    AND quote_option.id = decision.service_quote_option_id
                    AND quote_option.service_quote_id = quote.id
                    AND quote_option.total_minor = NEW.service_subtotal_minor
                WHERE orders.id = NEW.service_order_id
                    AND orders.organization_id = NEW.organization_id
                    AND orders.status = 'delivered'
                    AND (
                        orders.customer_business_party_id IS NULL
                        OR orders.customer_business_party_id = NEW.customer_business_party_id
                    )
                    AND quote.revision = (
                        SELECT MAX(latest.revision)
                        FROM service_quotes latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_order_id = orders.id
                    )
            )
        )
        OR NOT (
            (
                NEW.inventory_movement_id IS NULL
                AND NEW.product_subtotal_minor = 0
            )
            OR EXISTS (
                SELECT 1
                FROM inventory_movements movement
                WHERE movement.id = NEW.inventory_movement_id
                    AND movement.organization_id = NEW.organization_id
                    AND movement.type = 'issue'
                    AND movement.status = 'confirmed'
                    AND movement.source_type = 'commerce_sale'
                    AND movement.source_id = NEW.public_id
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La cabecera comercial carece de evidencia valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sale_lines_guard_insert
BEFORE INSERT ON commerce_sale_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            WHERE sale.id = NEW.commerce_sale_id
                AND sale.organization_id = NEW.organization_id
                AND sale.status = 'building'
        )
        OR NEW.quantity <= 0
        OR NEW.line_total_minor <> NEW.quantity * NEW.unit_price_minor
        OR NOT (
            (
                NEW.line_type = 'service'
                AND NEW.catalog_product_id IS NULL
                AND NEW.inventory_movement_line_id IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM commerce_sales sale
                    INNER JOIN service_quote_lines quote_line
                        ON quote_line.id = NEW.service_quote_line_id
                        AND quote_line.organization_id = NEW.organization_id
                        AND quote_line.service_quote_option_id = sale.service_quote_option_id
                        AND quote_line.description = NEW.description
                        AND quote_line.quantity = NEW.quantity
                        AND quote_line.unit_price_minor = NEW.unit_price_minor
                        AND quote_line.line_total_minor = NEW.line_total_minor
                    WHERE sale.id = NEW.commerce_sale_id
                        AND sale.organization_id = NEW.organization_id
                )
            )
            OR (
                NEW.line_type = 'product'
                AND NEW.service_quote_line_id IS NULL
                AND NEW.catalog_product_id IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM commerce_sales sale
                    INNER JOIN inventory_movement_lines movement_line
                        ON movement_line.id = NEW.inventory_movement_line_id
                        AND movement_line.organization_id = NEW.organization_id
                        AND movement_line.inventory_movement_id = sale.inventory_movement_id
                        AND movement_line.catalog_product_id = NEW.catalog_product_id
                        AND movement_line.entered_quantity = NEW.quantity
                        AND movement_line.conversion_factor = 1
                    INNER JOIN catalog_products product
                        ON product.id = NEW.catalog_product_id
                        AND product.name = NEW.description
                    WHERE sale.id = NEW.commerce_sale_id
                        AND sale.organization_id = NEW.organization_id
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La linea comercial no coincide con su evidencia.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.method NOT IN (
            'cash', 'debit_card', 'credit_card', 'bank_transfer',
            'digital_wallet', 'account_credit', 'other'
        )
        OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            WHERE sale.id = NEW.commerce_sale_id
                AND sale.organization_id = NEW.organization_id
                AND sale.status = 'building'
                AND NEW.paid_at <= sale.sold_at
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El pago comercial no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sales_guard_update
BEFORE UPDATE ON commerce_sales
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.public_id <> NEW.public_id
        OR OLD.sale_number <> NEW.sale_number
        OR NOT (OLD.service_order_id <=> NEW.service_order_id)
        OR NOT (OLD.service_delivery_id <=> NEW.service_delivery_id)
        OR NOT (OLD.service_quote_decision_id <=> NEW.service_quote_decision_id)
        OR NOT (OLD.service_quote_option_id <=> NEW.service_quote_option_id)
        OR NOT (OLD.customer_business_party_id <=> NEW.customer_business_party_id)
        OR OLD.customer_name_snapshot <> NEW.customer_name_snapshot
        OR NOT (OLD.customer_document_snapshot <=> NEW.customer_document_snapshot)
        OR OLD.currency_code <> NEW.currency_code
        OR OLD.service_subtotal_minor <> NEW.service_subtotal_minor
        OR OLD.product_subtotal_minor <> NEW.product_subtotal_minor
        OR OLD.total_minor <> NEW.total_minor
        OR NOT (OLD.inventory_movement_id <=> NEW.inventory_movement_id)
        OR NOT (OLD.notes <=> NEW.notes)
        OR OLD.recorded_by_user_id <> NEW.recorded_by_user_id
        OR OLD.sold_at <> NEW.sold_at
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR OLD.fingerprint <> NEW.fingerprint
        OR OLD.status <> 'building'
        OR NEW.status <> 'confirmed'
        OR OLD.confirmed_at IS NOT NULL
        OR NEW.confirmed_at IS NULL
        OR NOT EXISTS (
            SELECT 1 FROM commerce_sale_lines line
            WHERE line.organization_id = NEW.organization_id
                AND line.commerce_sale_id = NEW.id
        )
        OR NEW.service_subtotal_minor <> COALESCE((
            SELECT SUM(line.line_total_minor)
            FROM commerce_sale_lines line
            WHERE line.organization_id = NEW.organization_id
                AND line.commerce_sale_id = NEW.id
                AND line.line_type = 'service'
        ), 0)
        OR NEW.product_subtotal_minor <> COALESCE((
            SELECT SUM(line.line_total_minor)
            FROM commerce_sale_lines line
            WHERE line.organization_id = NEW.organization_id
                AND line.commerce_sale_id = NEW.id
                AND line.line_type = 'product'
        ), 0)
        OR NEW.total_minor <> COALESCE((
            SELECT SUM(payment.amount_minor)
            FROM commerce_payments payment
            WHERE payment.organization_id = NEW.organization_id
                AND payment.commerce_sale_id = NEW.id
        ), 0)
        OR (
            NEW.service_quote_option_id IS NOT NULL
            AND (
                SELECT COUNT(*)
                FROM commerce_sale_lines line
                WHERE line.organization_id = NEW.organization_id
                    AND line.commerce_sale_id = NEW.id
                    AND line.line_type = 'service'
            ) <> (
                SELECT COUNT(*)
                FROM service_quote_lines quote_line
                WHERE quote_line.organization_id = NEW.organization_id
                    AND quote_line.service_quote_option_id = NEW.service_quote_option_id
            )
        )
        OR (
            NEW.inventory_movement_id IS NOT NULL
            AND (
                SELECT COUNT(*)
                FROM commerce_sale_lines line
                WHERE line.organization_id = NEW.organization_id
                    AND line.commerce_sale_id = NEW.id
                    AND line.line_type = 'product'
            ) <> (
                SELECT COUNT(*)
                FROM inventory_movement_lines movement_line
                WHERE movement_line.organization_id = NEW.organization_id
                    AND movement_line.inventory_movement_id = NEW.inventory_movement_id
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta no puede confirmarse sin integridad total.';
    END IF;
END
SQL);

        $this->createMysqlDeleteTrigger(
            'commerce_sales_guard_delete',
            'commerce_sales'
        );
        $this->createMysqlImmutablePair(
            'commerce_sale_lines',
            'commerce_sale_lines'
        );
        $this->createMysqlImmutablePair(
            'commerce_payments',
            'commerce_payments'
        );
    }

    private function createSqliteImmutablePair(
        string $prefix,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_update\n"
            ."BEFORE UPDATE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'La evidencia comercial es inmutable.');\n"
            ."END"
        );
        $this->createSqliteDeleteTrigger(
            $prefix.'_guard_delete',
            $table
        );
    }

    private function createSqliteDeleteTrigger(
        string $name,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$name}\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'La evidencia comercial no puede eliminarse.');\n"
            ."END"
        );
    }

    private function createMysqlImmutablePair(
        string $prefix,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_update\n"
            ."BEFORE UPDATE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La evidencia comercial es inmutable.';\n"
            ."END"
        );
        $this->createMysqlDeleteTrigger(
            $prefix.'_guard_delete',
            $table
        );
    }

    private function createMysqlDeleteTrigger(
        string $name,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$name}\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La evidencia comercial no puede eliminarse.';\n"
            ."END"
        );
    }

    private function dropTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
