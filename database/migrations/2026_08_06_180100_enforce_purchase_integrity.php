<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGERS = [
        'purchase_orders_guard_insert',
        'purchase_orders_guard_update',
        'purchase_orders_guard_delete',
        'purchase_order_lines_guard_insert',
        'purchase_order_lines_guard_update',
        'purchase_order_lines_guard_delete',
        'purchase_receipts_guard_insert',
        'purchase_receipts_guard_update',
        'purchase_receipts_guard_delete',
        'purchase_receipt_lines_guard_insert',
        'purchase_receipt_lines_guard_update',
        'purchase_receipt_lines_guard_delete',
    ];

    public function up(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            'La integridad de Compras no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    public function down(): void
    {
        $this->dropTriggers();
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_orders_guard_insert
BEFORE INSERT ON purchase_orders
WHEN NEW.status <> 'draft'
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR NEW.expected_logistics_cost_minor < 0
    OR NEW.merchandise_subtotal_minor < 0
    OR NEW.expected_total_minor
        <> NEW.merchandise_subtotal_minor
            + NEW.expected_logistics_cost_minor
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.issued_by_user_id IS NOT NULL
    OR NEW.issued_at IS NOT NULL
    OR NEW.cancelled_by_user_id IS NOT NULL
    OR NEW.cancelled_at IS NOT NULL
    OR NEW.cancellation_reason IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM suppliers supplier
        WHERE supplier.organization_id = NEW.organization_id
          AND supplier.id = NEW.supplier_id
          AND supplier.active = 1
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La orden borrador no conserva proveedor, moneda, costos o huella válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_orders_guard_update
BEFORE UPDATE ON purchase_orders
BEGIN
    SELECT CASE WHEN
        NEW.organization_id IS NOT OLD.organization_id
        OR NEW.public_id IS NOT OLD.public_id
        OR NEW.idempotency_key IS NOT OLD.idempotency_key
        OR NEW.created_by_user_id IS NOT OLD.created_by_user_id
    THEN RAISE(
        ABORT,
        'La identidad de la orden de compra es inmutable.'
    ) END;

    SELECT CASE WHEN
        OLD.status = 'draft'
        AND NEW.status NOT IN ('draft', 'issued')
    THEN RAISE(
        ABORT,
        'Una orden borrador sólo puede permanecer borrador o emitirse.'
    ) END;

    SELECT CASE WHEN
        OLD.status = 'issued'
        AND NEW.status NOT IN (
            'issued',
            'partially_received',
            'received',
            'cancelled'
        )
    THEN RAISE(
        ABORT,
        'La transición de la orden emitida no está permitida.'
    ) END;

    SELECT CASE WHEN
        OLD.status = 'partially_received'
        AND NEW.status NOT IN (
            'partially_received',
            'received'
        )
    THEN RAISE(
        ABORT,
        'La orden parcialmente recibida sólo puede completarse.'
    ) END;

    SELECT CASE WHEN
        OLD.status IN ('received', 'cancelled')
    THEN RAISE(
        ABORT,
        'Una orden cerrada es inmutable.'
    ) END;

    SELECT CASE WHEN
        OLD.status <> 'draft'
        AND (
            NEW.supplier_id IS NOT OLD.supplier_id
            OR NEW.currency_code IS NOT OLD.currency_code
            OR NEW.expected_logistics_cost_minor
                IS NOT OLD.expected_logistics_cost_minor
            OR NEW.merchandise_subtotal_minor
                IS NOT OLD.merchandise_subtotal_minor
            OR NEW.expected_total_minor
                IS NOT OLD.expected_total_minor
            OR NEW.notes IS NOT OLD.notes
            OR NEW.fingerprint IS NOT OLD.fingerprint
            OR NEW.issued_by_user_id
                IS NOT OLD.issued_by_user_id
            OR NEW.issued_at IS NOT OLD.issued_at
        )
    THEN RAISE(
        ABORT,
        'La información comercial de una orden emitida es inmutable.'
    ) END;

    SELECT CASE WHEN
        NEW.status <> 'draft'
        AND (
            NEW.issued_by_user_id IS NULL
            OR NEW.issued_at IS NULL
        )
    THEN RAISE(
        ABORT,
        'Una orden emitida requiere responsable y fecha.'
    ) END;

    SELECT CASE WHEN
        NEW.status = 'cancelled'
        AND (
            NEW.cancelled_by_user_id IS NULL
            OR NEW.cancelled_at IS NULL
            OR NEW.cancellation_reason IS NULL
        )
    THEN RAISE(
        ABORT,
        'La cancelación requiere responsable, fecha y motivo.'
    ) END;

    SELECT CASE WHEN
        NEW.status <> 'cancelled'
        AND (
            NEW.cancelled_by_user_id IS NOT NULL
            OR NEW.cancelled_at IS NOT NULL
            OR NEW.cancellation_reason IS NOT NULL
        )
    THEN RAISE(
        ABORT,
        'Solo una orden cancelada conserva datos de cancelación.'
    ) END;

    SELECT CASE WHEN
        LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR NEW.expected_logistics_cost_minor < 0
        OR NEW.merchandise_subtotal_minor < 0
        OR NEW.expected_total_minor
            <> NEW.merchandise_subtotal_minor
                + NEW.expected_logistics_cost_minor
        OR LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
            SELECT 1
            FROM suppliers supplier
            WHERE supplier.organization_id = NEW.organization_id
              AND supplier.id = NEW.supplier_id
              AND supplier.active = 1
        )
    THEN RAISE(
        ABORT,
        'La orden no conserva proveedor, moneda, costos o huella válidos.'
    ) END;

    SELECT CASE WHEN
        NEW.status = 'draft'
        AND (
            NEW.issued_by_user_id IS NOT NULL
            OR NEW.issued_at IS NOT NULL
            OR NEW.cancelled_by_user_id IS NOT NULL
            OR NEW.cancelled_at IS NOT NULL
            OR NEW.cancellation_reason IS NOT NULL
        )
    THEN RAISE(
        ABORT,
        'Una orden borrador no posee emisión ni cancelación.'
    ) END;

    SELECT CASE WHEN
        OLD.status = 'draft'
        AND NEW.status = 'issued'
        AND (
            NOT EXISTS (
                SELECT 1
                FROM purchase_order_lines line
                WHERE line.organization_id = NEW.organization_id
                  AND line.purchase_order_id = NEW.id
            )
            OR NEW.merchandise_subtotal_minor <> (
                SELECT COALESCE(SUM(line.subtotal_minor), 0)
                FROM purchase_order_lines line
                WHERE line.organization_id = NEW.organization_id
                  AND line.purchase_order_id = NEW.id
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_order_lines line
                INNER JOIN catalog_products product
                    ON product.id = line.catalog_product_id
                WHERE line.organization_id = NEW.organization_id
                  AND line.purchase_order_id = NEW.id
                  AND (
                      line.supplier_id <> NEW.supplier_id
                      OR product.active <> 1
                      OR product.base_unit_code <> line.base_unit_code
                      OR product.quantity_scale <> line.quantity_scale
                      OR line.ordered_quantity <= 0
                      OR line.ordered_quantity <> ROUND(
                          line.ordered_quantity,
                          product.quantity_scale
                      )
                      OR line.subtotal_minor < 0
                      OR line.subtotal_minor <>
                          line.ordered_quantity * line.unit_cost_minor
                      OR (
                          line.supplier_offer_id IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1
                              FROM supplier_offers offer
                              WHERE offer.organization_id =
                                    line.organization_id
                                AND offer.id =
                                    line.supplier_offer_id
                                AND offer.supplier_id =
                                    line.supplier_id
                                AND offer.catalog_product_id =
                                    line.catalog_product_id
                                AND offer.active = 1
                          )
                      )
                  )
            )
        )
    THEN RAISE(
        ABORT,
        'La orden no puede emitirse sin líneas comerciales válidas.'
    ) END;

    SELECT CASE WHEN
        NEW.status = 'partially_received'
        AND (
            NOT EXISTS (
                SELECT 1
                FROM purchase_receipt_lines receipt_line
                WHERE receipt_line.organization_id = NEW.organization_id
                  AND receipt_line.purchase_order_id = NEW.id
            )
            OR NOT EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
                  AND (
                      SELECT COALESCE(
                          SUM(receipt_line.received_quantity),
                          0
                      )
                      FROM purchase_receipt_lines receipt_line
                      WHERE receipt_line.organization_id =
                            order_line.organization_id
                        AND receipt_line.purchase_order_line_id =
                            order_line.id
                  ) < order_line.ordered_quantity
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
                  AND (
                      SELECT COALESCE(
                          SUM(receipt_line.received_quantity),
                          0
                      )
                      FROM purchase_receipt_lines receipt_line
                      WHERE receipt_line.organization_id =
                            order_line.organization_id
                        AND receipt_line.purchase_order_line_id =
                            order_line.id
                  ) > order_line.ordered_quantity
            )
        )
    THEN RAISE(
        ABORT,
        'El estado parcial no coincide con las cantidades recibidas.'
    ) END;

    SELECT CASE WHEN
        NEW.status = 'received'
        AND (
            NOT EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
                  AND (
                      SELECT COALESCE(
                          SUM(receipt_line.received_quantity),
                          0
                      )
                      FROM purchase_receipt_lines receipt_line
                      WHERE receipt_line.organization_id =
                            order_line.organization_id
                        AND receipt_line.purchase_order_line_id =
                            order_line.id
                  ) <> order_line.ordered_quantity
            )
        )
    THEN RAISE(
        ABORT,
        'El estado recibido requiere completar todas las líneas.'
    ) END;

    SELECT CASE WHEN
        NEW.status = 'cancelled'
        AND EXISTS (
            SELECT 1
            FROM purchase_receipts receipt
            WHERE receipt.organization_id = NEW.organization_id
              AND receipt.purchase_order_id = NEW.id
        )
    THEN RAISE(
        ABORT,
        'Una orden con recepciones no puede cancelarse.'
    ) END;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_orders_guard_delete
BEFORE DELETE ON purchase_orders
WHEN OLD.status <> 'draft'
BEGIN
    SELECT RAISE(
        ABORT,
        'Una orden emitida no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_order_lines_guard_insert
BEFORE INSERT ON purchase_order_lines
WHEN NEW.ordered_quantity <= 0
    OR NEW.unit_cost_minor < 0
    OR NEW.subtotal_minor < 0
    OR NEW.subtotal_minor
        <> NEW.ordered_quantity * NEW.unit_cost_minor
    OR NEW.quantity_scale < 0
    OR NEW.quantity_scale > 6
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_orders purchase_order
        INNER JOIN catalog_products product
            ON product.id = NEW.catalog_product_id
        WHERE purchase_order.id = NEW.purchase_order_id
          AND purchase_order.organization_id =
              NEW.organization_id
          AND purchase_order.supplier_id =
              NEW.supplier_id
          AND purchase_order.status = 'draft'
          AND product.active = 1
          AND product.base_unit_code =
              NEW.base_unit_code
          AND product.quantity_scale =
              NEW.quantity_scale
          AND NEW.ordered_quantity = ROUND(
              NEW.ordered_quantity,
              product.quantity_scale
          )
          AND (
              NEW.supplier_offer_id IS NULL
              OR EXISTS (
                  SELECT 1
                  FROM supplier_offers offer
                  WHERE offer.organization_id =
                      NEW.organization_id
                    AND offer.id =
                      NEW.supplier_offer_id
                    AND offer.supplier_id =
                      NEW.supplier_id
                    AND offer.catalog_product_id =
                      NEW.catalog_product_id
                    AND offer.active = 1
              )
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea no corresponde a una orden borrador válida.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_order_lines_guard_update
BEFORE UPDATE ON purchase_order_lines
BEGIN
    SELECT CASE WHEN
        NEW.organization_id IS NOT OLD.organization_id
        OR NEW.purchase_order_id IS NOT OLD.purchase_order_id
    THEN RAISE(
        ABORT,
        'La pertenencia de la línea de compra es inmutable.'
    ) END;

    SELECT CASE WHEN
        NEW.ordered_quantity <= 0
        OR NEW.unit_cost_minor < 0
        OR NEW.subtotal_minor < 0
        OR NEW.subtotal_minor
            <> NEW.ordered_quantity * NEW.unit_cost_minor
        OR NEW.quantity_scale < 0
        OR NEW.quantity_scale > 6
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_orders purchase_order
            INNER JOIN catalog_products product
                ON product.id = NEW.catalog_product_id
            WHERE purchase_order.id = NEW.purchase_order_id
              AND purchase_order.organization_id =
                  NEW.organization_id
              AND purchase_order.supplier_id =
                  NEW.supplier_id
              AND purchase_order.status = 'draft'
              AND product.active = 1
              AND product.base_unit_code =
                  NEW.base_unit_code
              AND product.quantity_scale =
                  NEW.quantity_scale
              AND NEW.ordered_quantity = ROUND(
                  NEW.ordered_quantity,
                  product.quantity_scale
              )
              AND (
                  NEW.supplier_offer_id IS NULL
                  OR EXISTS (
                      SELECT 1
                      FROM supplier_offers offer
                      WHERE offer.organization_id =
                            NEW.organization_id
                        AND offer.id =
                            NEW.supplier_offer_id
                        AND offer.supplier_id =
                            NEW.supplier_id
                        AND offer.catalog_product_id =
                            NEW.catalog_product_id
                        AND offer.active = 1
                  )
              )
        )
    THEN RAISE(
        ABORT,
        'La línea actualizada no conserva una orden borrador válida.'
    ) END;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_order_lines_guard_delete
BEFORE DELETE ON purchase_order_lines
WHEN NOT EXISTS (
    SELECT 1
    FROM purchase_orders
    WHERE id = OLD.purchase_order_id
      AND organization_id = OLD.organization_id
      AND status = 'draft'
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Las líneas de una orden emitida no pueden eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipts_guard_insert
BEFORE INSERT ON purchase_receipts
WHEN
    NEW.logistics_cost_minor < 0
    OR NEW.merchandise_total_minor < 0
    OR NEW.actual_total_minor
        <> NEW.merchandise_total_minor
            + NEW.logistics_cost_minor
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.received_at IS NULL
    OR NEW.confirmed_at IS NULL
    OR NEW.received_by_user_id IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_orders
        WHERE id = NEW.purchase_order_id
          AND organization_id = NEW.organization_id
          AND supplier_id = NEW.supplier_id
          AND status IN ('issued', 'partially_received')
    )
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_movements
        WHERE id = NEW.inventory_movement_id
          AND organization_id = NEW.organization_id
          AND status = 'confirmed'
          AND type = 'receipt'
          AND source_type = 'purchase_receipt'
          AND source_id = NEW.public_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La recepción requiere orden habilitada y movimiento confirmado.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipts_guard_update
BEFORE UPDATE ON purchase_receipts
BEGIN
    SELECT RAISE(
        ABORT,
        'Una recepción confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipts_guard_delete
BEFORE DELETE ON purchase_receipts
BEGIN
    SELECT RAISE(
        ABORT,
        'Una recepción confirmada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipt_lines_guard_insert
BEFORE INSERT ON purchase_receipt_lines
WHEN NEW.received_quantity <= 0
    OR NEW.actual_unit_cost_minor < 0
    OR NEW.subtotal_minor < 0
    OR NEW.subtotal_minor
        <> NEW.received_quantity
            * NEW.actual_unit_cost_minor
    OR NEW.condition NOT IN (
        'new',
        'used',
        'refurbished',
        'damaged',
        'display'
    )
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_receipts receipt
        INNER JOIN purchase_order_lines order_line
            ON order_line.organization_id =
                receipt.organization_id
            AND order_line.purchase_order_id =
                receipt.purchase_order_id
        INNER JOIN inventory_movement_lines movement_line
            ON movement_line.organization_id =
                receipt.organization_id
            AND movement_line.inventory_movement_id =
                receipt.inventory_movement_id
        INNER JOIN inventory_locations location
            ON location.organization_id =
                receipt.organization_id
        INNER JOIN catalog_products product
            ON product.id = order_line.catalog_product_id
        WHERE receipt.organization_id =
              NEW.organization_id
          AND receipt.id = NEW.purchase_receipt_id
          AND receipt.purchase_order_id =
              NEW.purchase_order_id
          AND receipt.inventory_movement_id =
              NEW.inventory_movement_id
          AND order_line.id =
              NEW.purchase_order_line_id
          AND order_line.catalog_product_id =
              NEW.catalog_product_id
          AND movement_line.id =
              NEW.inventory_movement_line_id
          AND movement_line.catalog_product_id =
              NEW.catalog_product_id
          AND movement_line.destination_location_id =
              NEW.inventory_location_id
          AND movement_line.condition =
              NEW.condition
          AND movement_line.base_quantity =
              NEW.received_quantity
          AND location.id =
              NEW.inventory_location_id
          AND location.active = 1
          AND NEW.received_quantity = ROUND(
              NEW.received_quantity,
              product.quantity_scale
          )
          AND (
              SELECT COALESCE(
                  SUM(existing.received_quantity),
                  0
              )
              FROM purchase_receipt_lines existing
              WHERE existing.organization_id =
                    NEW.organization_id
                AND existing.purchase_order_line_id =
                    NEW.purchase_order_line_id
          ) + NEW.received_quantity
              <= order_line.ordered_quantity
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea de recepción no coincide con orden, ubicación y movimiento.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipt_lines_guard_update
BEFORE UPDATE ON purchase_receipt_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de recepción confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipt_lines_guard_delete
BEFORE DELETE ON purchase_receipt_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de recepción confirmada no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_orders_guard_insert
BEFORE INSERT ON purchase_orders
FOR EACH ROW
BEGIN
    IF NEW.status <> 'draft'
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR NEW.expected_total_minor
            <> NEW.merchandise_subtotal_minor
                + NEW.expected_logistics_cost_minor
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.issued_by_user_id IS NOT NULL
        OR NEW.issued_at IS NOT NULL
        OR NEW.cancelled_by_user_id IS NOT NULL
        OR NEW.cancelled_at IS NOT NULL
        OR NEW.cancellation_reason IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM suppliers supplier
            WHERE supplier.organization_id =
                  NEW.organization_id
              AND supplier.id = NEW.supplier_id
              AND supplier.active = 1
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La orden borrador no conserva datos validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_orders_guard_update
BEFORE UPDATE ON purchase_orders
FOR EACH ROW
BEGIN
    IF
        NOT (NEW.organization_id <=> OLD.organization_id)
        OR NOT (NEW.public_id <=> OLD.public_id)
        OR NOT (NEW.idempotency_key <=> OLD.idempotency_key)
        OR NOT (NEW.created_by_user_id <=> OLD.created_by_user_id)
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La identidad de la orden de compra es inmutable.';
    END IF;

    IF
        OLD.status = 'draft'
        AND NEW.status NOT IN ('draft', 'issued')
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Una orden borrador solo puede emitirse.';
    END IF;

    IF
        OLD.status = 'issued'
        AND NEW.status NOT IN (
            'issued',
            'partially_received',
            'received',
            'cancelled'
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Transicion de orden emitida no permitida.';
    END IF;

    IF
        OLD.status = 'partially_received'
        AND NEW.status NOT IN (
            'partially_received',
            'received'
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La orden parcial solo puede completarse.';
    END IF;

    IF OLD.status IN ('received', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Una orden cerrada es inmutable.';
    END IF;

    IF
        OLD.status <> 'draft'
        AND (
            NOT (NEW.supplier_id <=> OLD.supplier_id)
            OR NOT (
                NEW.currency_code <=> OLD.currency_code
            )
            OR NOT (
                NEW.expected_logistics_cost_minor
                    <=> OLD.expected_logistics_cost_minor
            )
            OR NOT (
                NEW.merchandise_subtotal_minor
                    <=> OLD.merchandise_subtotal_minor
            )
            OR NOT (
                NEW.expected_total_minor
                    <=> OLD.expected_total_minor
            )
            OR NOT (NEW.notes <=> OLD.notes)
            OR NOT (NEW.fingerprint <=> OLD.fingerprint)
            OR NOT (
                NEW.issued_by_user_id
                    <=> OLD.issued_by_user_id
            )
            OR NOT (NEW.issued_at <=> OLD.issued_at)
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La informacion comercial emitida es inmutable.';
    END IF;

    IF
        NEW.status <> 'draft'
        AND (
            NEW.issued_by_user_id IS NULL
            OR NEW.issued_at IS NULL
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La emision requiere responsable y fecha.';
    END IF;

    IF
        NEW.status = 'cancelled'
        AND (
            NEW.cancelled_by_user_id IS NULL
            OR NEW.cancelled_at IS NULL
            OR NEW.cancellation_reason IS NULL
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La cancelacion requiere responsable fecha y motivo.';
    END IF;

    IF
        NEW.status <> 'cancelled'
        AND (
            NEW.cancelled_by_user_id IS NOT NULL
            OR NEW.cancelled_at IS NOT NULL
            OR NEW.cancellation_reason IS NOT NULL
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Solo la orden cancelada conserva cancelacion.';
    END IF;

    IF
        CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR NEW.expected_total_minor
            <> NEW.merchandise_subtotal_minor
                + NEW.expected_logistics_cost_minor
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
            SELECT 1
            FROM suppliers supplier
            WHERE supplier.organization_id = NEW.organization_id
              AND supplier.id = NEW.supplier_id
              AND supplier.active = 1
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La orden no conserva proveedor moneda costos o huella validos.';
    END IF;

    IF
        NEW.status = 'draft'
        AND (
            NEW.issued_by_user_id IS NOT NULL
            OR NEW.issued_at IS NOT NULL
            OR NEW.cancelled_by_user_id IS NOT NULL
            OR NEW.cancelled_at IS NOT NULL
            OR NEW.cancellation_reason IS NOT NULL
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Una orden borrador no posee emision ni cancelacion.';
    END IF;

    IF
        OLD.status = 'draft'
        AND NEW.status = 'issued'
        AND (
            NOT EXISTS (
                SELECT 1
                FROM purchase_order_lines line
                WHERE line.organization_id = NEW.organization_id
                  AND line.purchase_order_id = NEW.id
            )
            OR NEW.merchandise_subtotal_minor <> (
                SELECT COALESCE(SUM(line.subtotal_minor), 0)
                FROM purchase_order_lines line
                WHERE line.organization_id = NEW.organization_id
                  AND line.purchase_order_id = NEW.id
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_order_lines line
                INNER JOIN catalog_products product
                    ON product.id = line.catalog_product_id
                WHERE line.organization_id = NEW.organization_id
                  AND line.purchase_order_id = NEW.id
                  AND (
                      line.supplier_id <> NEW.supplier_id
                      OR product.active <> 1
                      OR product.base_unit_code <> line.base_unit_code
                      OR product.quantity_scale <> line.quantity_scale
                      OR line.ordered_quantity <= 0
                      OR line.ordered_quantity <> ROUND(
                          line.ordered_quantity,
                          product.quantity_scale
                      )
                      OR line.subtotal_minor <>
                          line.ordered_quantity * line.unit_cost_minor
                      OR (
                          line.supplier_offer_id IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1
                              FROM supplier_offers offer
                              WHERE offer.organization_id =
                                    line.organization_id
                                AND offer.id =
                                    line.supplier_offer_id
                                AND offer.supplier_id =
                                    line.supplier_id
                                AND offer.catalog_product_id =
                                    line.catalog_product_id
                                AND offer.active = 1
                          )
                      )
                  )
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La orden no puede emitirse sin lineas comerciales validas.';
    END IF;

    IF
        NEW.status = 'partially_received'
        AND (
            NOT EXISTS (
                SELECT 1
                FROM purchase_receipt_lines receipt_line
                WHERE receipt_line.organization_id = NEW.organization_id
                  AND receipt_line.purchase_order_id = NEW.id
            )
            OR NOT EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
                  AND (
                      SELECT COALESCE(
                          SUM(receipt_line.received_quantity),
                          0
                      )
                      FROM purchase_receipt_lines receipt_line
                      WHERE receipt_line.organization_id =
                            order_line.organization_id
                        AND receipt_line.purchase_order_line_id =
                            order_line.id
                  ) < order_line.ordered_quantity
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
                  AND (
                      SELECT COALESCE(
                          SUM(receipt_line.received_quantity),
                          0
                      )
                      FROM purchase_receipt_lines receipt_line
                      WHERE receipt_line.organization_id =
                            order_line.organization_id
                        AND receipt_line.purchase_order_line_id =
                            order_line.id
                  ) > order_line.ordered_quantity
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El estado parcial no coincide con las cantidades recibidas.';
    END IF;

    IF
        NEW.status = 'received'
        AND (
            NOT EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_order_lines order_line
                WHERE order_line.organization_id = NEW.organization_id
                  AND order_line.purchase_order_id = NEW.id
                  AND (
                      SELECT COALESCE(
                          SUM(receipt_line.received_quantity),
                          0
                      )
                      FROM purchase_receipt_lines receipt_line
                      WHERE receipt_line.organization_id =
                            order_line.organization_id
                        AND receipt_line.purchase_order_line_id =
                            order_line.id
                  ) <> order_line.ordered_quantity
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El estado recibido requiere completar todas las lineas.';
    END IF;

    IF
        NEW.status = 'cancelled'
        AND EXISTS (
            SELECT 1
            FROM purchase_receipts receipt
            WHERE receipt.organization_id = NEW.organization_id
              AND receipt.purchase_order_id = NEW.id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Una orden con recepciones no puede cancelarse.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_orders_guard_delete
BEFORE DELETE ON purchase_orders
FOR EACH ROW
BEGIN
    IF OLD.status <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Una orden emitida no puede eliminarse.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_order_lines_guard_insert
BEFORE INSERT ON purchase_order_lines
FOR EACH ROW
BEGIN
    IF NEW.ordered_quantity <= 0
        OR NEW.subtotal_minor
            <> NEW.ordered_quantity
                * NEW.unit_cost_minor
        OR NEW.quantity_scale > 6
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_orders purchase_order
            INNER JOIN catalog_products product
                ON product.id =
                    NEW.catalog_product_id
            WHERE purchase_order.id =
                  NEW.purchase_order_id
              AND purchase_order.organization_id =
                  NEW.organization_id
              AND purchase_order.supplier_id =
                  NEW.supplier_id
              AND purchase_order.status = 'draft'
              AND product.active = 1
              AND product.base_unit_code =
                  NEW.base_unit_code
              AND product.quantity_scale =
                  NEW.quantity_scale
              AND NEW.ordered_quantity = ROUND(
                  NEW.ordered_quantity,
                  product.quantity_scale
              )
              AND (
                  NEW.supplier_offer_id IS NULL
                  OR EXISTS (
                      SELECT 1
                      FROM supplier_offers offer
                      WHERE offer.organization_id =
                            NEW.organization_id
                        AND offer.id =
                            NEW.supplier_offer_id
                        AND offer.supplier_id =
                            NEW.supplier_id
                        AND offer.catalog_product_id =
                            NEW.catalog_product_id
                        AND offer.active = 1
                  )
              )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La linea no corresponde a una orden valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_order_lines_guard_update
BEFORE UPDATE ON purchase_order_lines
FOR EACH ROW
BEGIN
    IF
        NOT (
            NEW.organization_id
                <=> OLD.organization_id
        )
        OR NOT (
            NEW.purchase_order_id
                <=> OLD.purchase_order_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La pertenencia de la linea es inmutable.';
    END IF;

    IF NEW.ordered_quantity <= 0
        OR NEW.subtotal_minor
            <> NEW.ordered_quantity
                * NEW.unit_cost_minor
        OR NEW.quantity_scale > 6
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_orders purchase_order
            INNER JOIN catalog_products product
                ON product.id =
                    NEW.catalog_product_id
            WHERE purchase_order.id =
                  NEW.purchase_order_id
              AND purchase_order.organization_id =
                  NEW.organization_id
              AND purchase_order.supplier_id =
                  NEW.supplier_id
              AND purchase_order.status = 'draft'
              AND product.active = 1
              AND product.base_unit_code =
                  NEW.base_unit_code
              AND product.quantity_scale =
                  NEW.quantity_scale
              AND NEW.ordered_quantity = ROUND(
                  NEW.ordered_quantity,
                  product.quantity_scale
              )
              AND (
                  NEW.supplier_offer_id IS NULL
                  OR EXISTS (
                      SELECT 1
                      FROM supplier_offers offer
                      WHERE offer.organization_id =
                            NEW.organization_id
                        AND offer.id =
                            NEW.supplier_offer_id
                        AND offer.supplier_id =
                            NEW.supplier_id
                        AND offer.catalog_product_id =
                            NEW.catalog_product_id
                        AND offer.active = 1
                  )
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La linea actualizada no conserva una orden valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_order_lines_guard_delete
BEFORE DELETE ON purchase_order_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM purchase_orders
        WHERE id = OLD.purchase_order_id
          AND organization_id = OLD.organization_id
          AND status = 'draft'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Las lineas emitidas no pueden eliminarse.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipts_guard_insert
BEFORE INSERT ON purchase_receipts
FOR EACH ROW
BEGIN
    IF
        NEW.actual_total_minor
            <> NEW.merchandise_total_minor
                + NEW.logistics_cost_minor
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.received_at IS NULL
        OR NEW.confirmed_at IS NULL
        OR NEW.received_by_user_id IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_orders
            WHERE id = NEW.purchase_order_id
              AND organization_id = NEW.organization_id
              AND supplier_id = NEW.supplier_id
              AND status IN (
                  'issued',
                  'partially_received'
              )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_movements
            WHERE id = NEW.inventory_movement_id
              AND organization_id = NEW.organization_id
              AND status = 'confirmed'
              AND type = 'receipt'
              AND source_type = 'purchase_receipt'
              AND source_id = NEW.public_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La recepcion requiere orden y movimiento validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipts_guard_update
BEFORE UPDATE ON purchase_receipts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una recepcion confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipts_guard_delete
BEFORE DELETE ON purchase_receipts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una recepcion confirmada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipt_lines_guard_insert
BEFORE INSERT ON purchase_receipt_lines
FOR EACH ROW
BEGIN
    IF NEW.received_quantity <= 0
        OR NEW.subtotal_minor
            <> NEW.received_quantity
                * NEW.actual_unit_cost_minor
        OR NEW.condition NOT IN (
            'new',
            'used',
            'refurbished',
            'damaged',
            'display'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_receipts receipt
            INNER JOIN purchase_order_lines order_line
                ON order_line.organization_id =
                    receipt.organization_id
                AND order_line.purchase_order_id =
                    receipt.purchase_order_id
            INNER JOIN inventory_movement_lines movement_line
                ON movement_line.organization_id =
                    receipt.organization_id
                AND movement_line.inventory_movement_id =
                    receipt.inventory_movement_id
            INNER JOIN inventory_locations location
                ON location.organization_id =
                    receipt.organization_id
            INNER JOIN catalog_products product
                ON product.id =
                    order_line.catalog_product_id
            WHERE receipt.organization_id =
                  NEW.organization_id
              AND receipt.id =
                  NEW.purchase_receipt_id
              AND receipt.purchase_order_id =
                  NEW.purchase_order_id
              AND receipt.inventory_movement_id =
                  NEW.inventory_movement_id
              AND order_line.id =
                  NEW.purchase_order_line_id
              AND order_line.catalog_product_id =
                  NEW.catalog_product_id
              AND movement_line.id =
                  NEW.inventory_movement_line_id
              AND movement_line.catalog_product_id =
                  NEW.catalog_product_id
              AND movement_line.destination_location_id =
                  NEW.inventory_location_id
              AND movement_line.condition =
                  NEW.condition
              AND movement_line.base_quantity =
                  NEW.received_quantity
              AND location.id =
                  NEW.inventory_location_id
              AND location.active = 1
              AND NEW.received_quantity = ROUND(
                  NEW.received_quantity,
                  product.quantity_scale
              )
              AND (
                  SELECT COALESCE(
                      SUM(existing.received_quantity),
                      0
                  )
                  FROM purchase_receipt_lines existing
                  WHERE existing.organization_id =
                        NEW.organization_id
                    AND existing.purchase_order_line_id =
                        NEW.purchase_order_line_id
              ) + NEW.received_quantity
                  <= order_line.ordered_quantity
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La linea recibida no coincide con su evidencia.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipt_lines_guard_update
BEFORE UPDATE ON purchase_receipt_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una linea de recepcion es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_receipt_lines_guard_delete
BEFORE DELETE ON purchase_receipt_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una linea de recepcion no puede eliminarse.';
END
SQL);
    }

    private function dropTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }
};
