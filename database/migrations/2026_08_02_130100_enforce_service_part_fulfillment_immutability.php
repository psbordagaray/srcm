<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const PART_TRIGGERS = [
        'srv_part_req_guard_insert',
        'srv_part_req_guard_update',
        'srv_part_req_guard_delete',
        'srv_part_purchases_guard_insert',
        'srv_part_purchases_guard_update',
        'srv_part_purchases_guard_delete',
        'srv_part_purchase_lines_guard_insert',
        'srv_part_purchase_lines_guard_update',
        'srv_part_purchase_lines_guard_delete',
        'srv_part_consumptions_guard_insert',
        'srv_part_consumptions_guard_update',
        'srv_part_consumptions_guard_delete',
    ];

    public function up(): void
    {
        $this->dropPartTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS srv_work_reports_guard_insert');
        DB::unprepared(
            'DROP TRIGGER IF EXISTS catalog_quantity_rules_guard_update'
        );

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
            "La protección de repuestos no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropPartTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS srv_work_reports_guard_insert');
        DB::unprepared(
            'DROP TRIGGER IF EXISTS catalog_quantity_rules_guard_update'
        );

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteCoreThreeOrderTrigger();
            $this->createSqliteCoreThreeReportTrigger();
            $this->createSqliteCoreThreeCatalogTrigger();

            return;
        }

        $this->createMysqlCoreThreeOrderTrigger();
        $this->createMysqlCoreThreeReportTrigger();
        $this->createMysqlCoreThreeCatalogTrigger();
    }

    private function dropPartTriggers(): void
    {
        foreach (self::PART_TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteTriggers(): void
    {
        $this->createSqliteOrderTrigger(true);
        $this->createSqliteCatalogTrigger(true);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_insert
BEFORE INSERT ON service_part_requirements
WHEN NEW.source NOT IN ('stock', 'direct_purchase')
    OR NEW.condition NOT IN ('new', 'used', 'refurbished', 'damaged', 'display')
    OR NEW.required_quantity <= 0
    OR NOT EXISTS (
        SELECT 1
        FROM service_work_items work_item
        INNER JOIN service_orders service_order
            ON service_order.organization_id = work_item.organization_id
            AND service_order.id = work_item.service_order_id
        INNER JOIN service_quote_lines quote_line
            ON quote_line.organization_id = work_item.organization_id
            AND quote_line.service_quote_option_id = work_item.service_quote_option_id
        INNER JOIN catalog_products product
            ON product.id = NEW.catalog_product_id
        WHERE work_item.organization_id = NEW.organization_id
            AND work_item.id = NEW.service_work_item_id
            AND service_order.id = NEW.service_order_id
            AND service_order.status IN ('in_progress', 'awaiting_parts')
            AND work_item.status IN ('planned', 'in_progress')
            AND quote_line.id = NEW.service_quote_line_id
            AND quote_line.line_type = 'part'
            AND quote_line.quantity = NEW.required_quantity
            AND product.active = 1
            AND product.base_unit_code = NEW.base_unit_code
            AND NEW.required_quantity = ROUND(
                NEW.required_quantity,
                product.quantity_scale
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'El repuesto no coincide con el alcance aprobado.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_part_req',
            'service_part_requirements'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_purchases_guard_insert
BEFORE INSERT ON service_part_purchases
WHEN NEW.parts_total_minor < 0
    OR NEW.logistics_cost_minor < 0
    OR NEW.grand_total_minor <> NEW.parts_total_minor + NEW.logistics_cost_minor
    OR LENGTH(NEW.currency_code) <> 3
    OR NOT EXISTS (
        SELECT 1
        FROM service_orders service_order
        INNER JOIN suppliers supplier
            ON supplier.organization_id = service_order.organization_id
        WHERE service_order.organization_id = NEW.organization_id
            AND service_order.id = NEW.service_order_id
            AND service_order.status IN ('awaiting_parts', 'in_progress')
            AND supplier.id = NEW.supplier_id
            AND supplier.active = 1
    )
BEGIN
    SELECT RAISE(ABORT, 'La compra afectada no es válida para la orden.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_part_purchases',
            'service_part_purchases'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_purchase_lines_guard_insert
BEFORE INSERT ON service_part_purchase_lines
WHEN NEW.quantity <= 0
    OR NEW.unit_cost_minor < 0
    OR NEW.line_total_minor < 0
    OR NOT EXISTS (
        SELECT 1
        FROM service_part_purchases purchase
        INNER JOIN service_part_requirements requirement
            ON requirement.organization_id = purchase.organization_id
            AND requirement.service_order_id = purchase.service_order_id
        INNER JOIN catalog_products product
            ON product.id = requirement.catalog_product_id
        WHERE purchase.organization_id = NEW.organization_id
            AND purchase.id = NEW.service_part_purchase_id
            AND requirement.id = NEW.service_part_requirement_id
            AND requirement.source = 'direct_purchase'
            AND NEW.quantity = ROUND(NEW.quantity, product.quantity_scale)
            AND (
                SELECT COALESCE(SUM(existing.quantity), 0)
                FROM service_part_purchase_lines existing
                WHERE existing.organization_id = NEW.organization_id
                    AND existing.service_part_requirement_id = requirement.id
            ) + NEW.quantity <= requirement.required_quantity
    )
BEGIN
    SELECT RAISE(ABORT, 'La línea no corresponde a un repuesto comprado para la orden.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_part_purchase_lines',
            'service_part_purchase_lines'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_consumptions_guard_insert
BEFORE INSERT ON service_part_consumptions
WHEN NEW.quantity <= 0
    OR (
        NEW.service_part_purchase_line_id IS NULL
        AND NEW.inventory_movement_line_id IS NULL
    )
    OR (
        NEW.service_part_purchase_line_id IS NOT NULL
        AND NEW.inventory_movement_line_id IS NOT NULL
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_part_requirements requirement
        INNER JOIN service_work_items work_item
            ON work_item.organization_id = requirement.organization_id
            AND work_item.id = requirement.service_work_item_id
        INNER JOIN service_orders service_order
            ON service_order.organization_id = requirement.organization_id
            AND service_order.id = requirement.service_order_id
        INNER JOIN catalog_products product
            ON product.id = requirement.catalog_product_id
        WHERE requirement.organization_id = NEW.organization_id
            AND requirement.id = NEW.service_part_requirement_id
            AND work_item.status = 'in_progress'
            AND service_order.status = 'in_progress'
            AND NEW.quantity = ROUND(NEW.quantity, product.quantity_scale)
            AND (
                SELECT COALESCE(SUM(existing.quantity), 0)
                FROM service_part_consumptions existing
                WHERE existing.organization_id = NEW.organization_id
                    AND existing.service_part_requirement_id = requirement.id
            ) + NEW.quantity <= requirement.required_quantity
            AND (
                (
                    requirement.source = 'direct_purchase'
                    AND NEW.service_part_purchase_line_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM service_part_purchase_lines purchase_line
                        WHERE purchase_line.organization_id = NEW.organization_id
                            AND purchase_line.id = NEW.service_part_purchase_line_id
                            AND purchase_line.service_part_requirement_id = requirement.id
                            AND (
                                SELECT COALESCE(SUM(line_use.quantity), 0)
                                FROM service_part_consumptions line_use
                                WHERE line_use.organization_id = NEW.organization_id
                                    AND line_use.service_part_purchase_line_id = purchase_line.id
                            ) + NEW.quantity <= purchase_line.quantity
                    )
                )
                OR (
                    requirement.source = 'stock'
                    AND NEW.inventory_movement_line_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM inventory_movement_lines movement_line
                        INNER JOIN inventory_movements movement
                            ON movement.organization_id = movement_line.organization_id
                            AND movement.id = movement_line.inventory_movement_id
                        WHERE movement_line.organization_id = NEW.organization_id
                            AND movement_line.id = NEW.inventory_movement_line_id
                            AND movement.status = 'confirmed'
                            AND movement.type = 'issue'
                            AND movement.source_type = 'service_order'
                            AND movement.source_id = CAST(requirement.service_order_id AS TEXT)
                            AND movement_line.catalog_product_id = requirement.catalog_product_id
                            AND movement_line.condition = requirement.condition
                            AND movement_line.base_quantity = NEW.quantity
                            AND movement_line.base_unit_code = requirement.base_unit_code
                            AND movement_line.source_location_id IS NOT NULL
                            AND movement_line.destination_location_id IS NULL
                    )
                )
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'El consumo del repuesto no posee una fuente válida.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_part_consumptions',
            'service_part_consumptions'
        );
        $this->createSqliteReportTrigger(true);
    }

    private function createMysqlTriggers(): void
    {
        $this->createMysqlOrderTrigger(true);
        $this->createMysqlCatalogTrigger(true);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_insert
BEFORE INSERT ON service_part_requirements
FOR EACH ROW
BEGIN
    IF NEW.source NOT IN ('stock', 'direct_purchase')
        OR NEW.condition NOT IN ('new', 'used', 'refurbished', 'damaged', 'display')
        OR NEW.required_quantity <= 0
        OR NOT EXISTS (
            SELECT 1
            FROM service_work_items work_item
            INNER JOIN service_orders service_order
                ON service_order.organization_id = work_item.organization_id
                AND service_order.id = work_item.service_order_id
            INNER JOIN service_quote_lines quote_line
                ON quote_line.organization_id = work_item.organization_id
                AND quote_line.service_quote_option_id = work_item.service_quote_option_id
            INNER JOIN catalog_products product
                ON product.id = NEW.catalog_product_id
            WHERE work_item.organization_id = NEW.organization_id
                AND work_item.id = NEW.service_work_item_id
                AND service_order.id = NEW.service_order_id
                AND service_order.status IN ('in_progress', 'awaiting_parts')
                AND work_item.status IN ('planned', 'in_progress')
                AND quote_line.id = NEW.service_quote_line_id
                AND quote_line.line_type = 'part'
                AND quote_line.quantity = NEW.required_quantity
                AND product.active = 1
                AND product.base_unit_code = NEW.base_unit_code
                AND NEW.required_quantity = ROUND(
                    NEW.required_quantity,
                    product.quantity_scale
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El repuesto no coincide con el alcance aprobado.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_part_req',
            'service_part_requirements'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_purchases_guard_insert
BEFORE INSERT ON service_part_purchases
FOR EACH ROW
BEGIN
    IF NEW.grand_total_minor <> NEW.parts_total_minor + NEW.logistics_cost_minor
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NOT EXISTS (
            SELECT 1
            FROM service_orders service_order
            INNER JOIN suppliers supplier
                ON supplier.organization_id = service_order.organization_id
            WHERE service_order.organization_id = NEW.organization_id
                AND service_order.id = NEW.service_order_id
                AND service_order.status IN ('awaiting_parts', 'in_progress')
                AND supplier.id = NEW.supplier_id
                AND supplier.active = 1
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La compra afectada no es válida para la orden.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_part_purchases',
            'service_part_purchases'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_purchase_lines_guard_insert
BEFORE INSERT ON service_part_purchase_lines
FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0
        OR NOT EXISTS (
            SELECT 1
            FROM service_part_purchases purchase
            INNER JOIN service_part_requirements requirement
                ON requirement.organization_id = purchase.organization_id
                AND requirement.service_order_id = purchase.service_order_id
            INNER JOIN catalog_products product
                ON product.id = requirement.catalog_product_id
            WHERE purchase.organization_id = NEW.organization_id
                AND purchase.id = NEW.service_part_purchase_id
                AND requirement.id = NEW.service_part_requirement_id
                AND requirement.source = 'direct_purchase'
                AND NEW.quantity = ROUND(NEW.quantity, product.quantity_scale)
                AND (
                    SELECT COALESCE(SUM(existing.quantity), 0)
                    FROM service_part_purchase_lines existing
                    WHERE existing.organization_id = NEW.organization_id
                        AND existing.service_part_requirement_id = requirement.id
                ) + NEW.quantity <= requirement.required_quantity
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La línea no corresponde a un repuesto comprado para la orden.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_part_purchase_lines',
            'service_part_purchase_lines'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_consumptions_guard_insert
BEFORE INSERT ON service_part_consumptions
FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0
        OR (
            NEW.service_part_purchase_line_id IS NULL
            AND NEW.inventory_movement_line_id IS NULL
        )
        OR (
            NEW.service_part_purchase_line_id IS NOT NULL
            AND NEW.inventory_movement_line_id IS NOT NULL
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_part_requirements requirement
            INNER JOIN service_work_items work_item
                ON work_item.organization_id = requirement.organization_id
                AND work_item.id = requirement.service_work_item_id
            INNER JOIN service_orders service_order
                ON service_order.organization_id = requirement.organization_id
                AND service_order.id = requirement.service_order_id
            INNER JOIN catalog_products product
                ON product.id = requirement.catalog_product_id
            WHERE requirement.organization_id = NEW.organization_id
                AND requirement.id = NEW.service_part_requirement_id
                AND work_item.status = 'in_progress'
                AND service_order.status = 'in_progress'
                AND NEW.quantity = ROUND(NEW.quantity, product.quantity_scale)
                AND (
                    SELECT COALESCE(SUM(existing.quantity), 0)
                    FROM service_part_consumptions existing
                    WHERE existing.organization_id = NEW.organization_id
                        AND existing.service_part_requirement_id = requirement.id
                ) + NEW.quantity <= requirement.required_quantity
                AND (
                    (
                        requirement.source = 'direct_purchase'
                        AND NEW.service_part_purchase_line_id IS NOT NULL
                        AND EXISTS (
                            SELECT 1
                            FROM service_part_purchase_lines purchase_line
                            WHERE purchase_line.organization_id = NEW.organization_id
                                AND purchase_line.id = NEW.service_part_purchase_line_id
                                AND purchase_line.service_part_requirement_id = requirement.id
                                AND (
                                    SELECT COALESCE(SUM(line_use.quantity), 0)
                                    FROM service_part_consumptions line_use
                                    WHERE line_use.organization_id = NEW.organization_id
                                        AND line_use.service_part_purchase_line_id = purchase_line.id
                                ) + NEW.quantity <= purchase_line.quantity
                        )
                    )
                    OR (
                        requirement.source = 'stock'
                        AND NEW.inventory_movement_line_id IS NOT NULL
                        AND EXISTS (
                            SELECT 1
                            FROM inventory_movement_lines movement_line
                            INNER JOIN inventory_movements movement
                                ON movement.organization_id = movement_line.organization_id
                                AND movement.id = movement_line.inventory_movement_id
                            WHERE movement_line.organization_id = NEW.organization_id
                                AND movement_line.id = NEW.inventory_movement_line_id
                                AND movement.status = 'confirmed'
                                AND movement.type = 'issue'
                                AND movement.source_type = 'service_order'
                                AND movement.source_id = CAST(requirement.service_order_id AS CHAR)
                                AND movement_line.catalog_product_id = requirement.catalog_product_id
                                AND movement_line.condition = requirement.condition
                                AND movement_line.base_quantity = NEW.quantity
                                AND movement_line.base_unit_code = requirement.base_unit_code
                                AND movement_line.source_location_id IS NOT NULL
                                AND movement_line.destination_location_id IS NULL
                        )
                    )
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El consumo del repuesto no posee una fuente válida.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_part_consumptions',
            'service_part_consumptions'
        );
        $this->createMysqlReportTrigger(true);
    }

    private function createSqliteOrderTrigger(bool $withParts): void
    {
        $partTransitions = $withParts
            ? "\n                OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')\n                OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')"
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER srv_orders_guard_update
BEFORE UPDATE ON service_orders
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
    OR OLD.order_number <> NEW.order_number
    OR OLD.service_asset_id <> NEW.service_asset_id
    OR OLD.customer_business_party_id IS NOT NEW.customer_business_party_id
    OR OLD.owner_business_party_id IS NOT NEW.owner_business_party_id
    OR OLD.intake_location_id <> NEW.intake_location_id
    OR OLD.created_by_user_id <> NEW.created_by_user_id
    OR OLD.received_at <> NEW.received_at
    OR OLD.promised_at IS NOT NEW.promised_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.metadata IS NOT NEW.metadata
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'received' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'diagnosing' AND NEW.status = 'awaiting_approval')
                OR (OLD.status = 'awaiting_approval' AND NEW.status = 'in_progress')
                OR (OLD.status = 'awaiting_approval' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'in_progress' AND NEW.status = 'with_external_provider')
                OR (OLD.status = 'with_external_provider' AND NEW.status = 'in_progress')
                OR (OLD.status = 'in_progress' AND NEW.status = 'quality_control')
                OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing'){$partTransitions}
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_order_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_order_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_order_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_order_id = NEW.id
                    )
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición de la orden no es válida o carece de historia.');
END
SQL);
    }

    private function createMysqlOrderTrigger(bool $withParts): void
    {
        $partTransitions = $withParts
            ? "\n                    OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')\n                    OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')"
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER srv_orders_guard_update
BEFORE UPDATE ON service_orders
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.public_id <> NEW.public_id
        OR OLD.order_number <> NEW.order_number
        OR OLD.service_asset_id <> NEW.service_asset_id
        OR NOT (OLD.customer_business_party_id <=> NEW.customer_business_party_id)
        OR NOT (OLD.owner_business_party_id <=> NEW.owner_business_party_id)
        OR OLD.intake_location_id <> NEW.intake_location_id
        OR OLD.created_by_user_id <> NEW.created_by_user_id
        OR OLD.received_at <> NEW.received_at
        OR NOT (OLD.promised_at <=> NEW.promised_at)
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR NOT (OLD.metadata <=> NEW.metadata)
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'received' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'diagnosing' AND NEW.status = 'awaiting_approval')
                    OR (OLD.status = 'awaiting_approval' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'awaiting_approval' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'with_external_provider')
                    OR (OLD.status = 'with_external_provider' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'quality_control')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing'){$partTransitions}
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_order_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_order_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_order_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transición de la orden no es válida o carece de historia.';
    END IF;
END
SQL);
    }

    private function createSqliteReportTrigger(bool $withParts): void
    {
        $partGuard = $withParts
            ? <<<'SQL'

    OR (
        NEW.outcome = 'completed'
        AND EXISTS (
            SELECT 1
            FROM service_part_requirements requirement
            WHERE requirement.organization_id = NEW.organization_id
                AND requirement.service_work_item_id = NEW.service_work_item_id
                AND (
                    SELECT COALESCE(SUM(consumption.quantity), 0)
                    FROM service_part_consumptions consumption
                    WHERE consumption.organization_id = requirement.organization_id
                        AND consumption.service_part_requirement_id = requirement.id
                ) < requirement.required_quantity
        )
    )
SQL
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER srv_work_reports_guard_insert
BEFORE INSERT ON service_work_reports
WHEN NOT EXISTS (
        SELECT 1
        FROM service_work_items work_item
        WHERE work_item.id = NEW.service_work_item_id
            AND work_item.organization_id = NEW.organization_id
            AND work_item.status = 'in_progress'
    )
    OR NEW.outcome NOT IN ('completed', 'unresolved')
    OR (
        NEW.outcome = 'completed'
        AND (
            NEW.unresolved_reason IS NOT NULL
            OR (NEW.warranty_days IS NOT NULL AND NEW.warranty_days < 0)
            OR (NEW.warranty_days > 0 AND NEW.warranty_terms IS NULL)
        )
    )
    OR (
        NEW.outcome = 'unresolved'
        AND (
            NEW.unresolved_reason IS NULL
            OR NEW.warranty_days IS NOT NULL
            OR NEW.warranty_terms IS NOT NULL
        )
    ){$partGuard}
BEGIN
    SELECT RAISE(ABORT, 'El resultado técnico, su garantía o sus repuestos no son válidos.');
END
SQL);
    }

    private function createSqliteCatalogTrigger(bool $withParts): void
    {
        $partGuard = $withParts
            ? <<<'SQL'

            OR EXISTS (
                SELECT 1
                FROM service_part_requirements
                WHERE catalog_product_id = OLD.id
            )
SQL
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER catalog_quantity_rules_guard_update
BEFORE UPDATE ON catalog_products
WHEN NEW.quantity_scale < 0
    OR NEW.quantity_scale > 6
    OR NEW.base_unit_code NOT IN ('unit', 'l', 'm', 'kg')
    OR (NEW.base_unit_code = 'unit' AND NEW.quantity_scale <> 0)
    OR (
        (
            OLD.base_unit_code IS NOT NEW.base_unit_code
            OR OLD.quantity_scale IS NOT NEW.quantity_scale
        )
        AND (
            EXISTS (
                SELECT 1
                FROM inventory_movement_lines
                WHERE catalog_product_id = OLD.id
            ){$partGuard}
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Las reglas de cantidad son inválidas o ya poseen movimientos o repuestos.'
    );
END
SQL);
    }

    private function createMysqlReportTrigger(bool $withParts): void
    {
        $partGuard = $withParts
            ? <<<'SQL'

        OR (
            NEW.outcome = 'completed'
            AND EXISTS (
                SELECT 1
                FROM service_part_requirements requirement
                WHERE requirement.organization_id = NEW.organization_id
                    AND requirement.service_work_item_id = NEW.service_work_item_id
                    AND (
                        SELECT COALESCE(SUM(consumption.quantity), 0)
                        FROM service_part_consumptions consumption
                        WHERE consumption.organization_id = requirement.organization_id
                            AND consumption.service_part_requirement_id = requirement.id
                    ) < requirement.required_quantity
            )
        )
SQL
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER srv_work_reports_guard_insert
BEFORE INSERT ON service_work_reports
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
            SELECT 1
            FROM service_work_items work_item
            WHERE work_item.id = NEW.service_work_item_id
                AND work_item.organization_id = NEW.organization_id
                AND work_item.status = 'in_progress'
        )
        OR NEW.outcome NOT IN ('completed', 'unresolved')
        OR (
            NEW.outcome = 'completed'
            AND (
                NEW.unresolved_reason IS NOT NULL
                OR (NEW.warranty_days IS NOT NULL AND NEW.warranty_days < 0)
                OR (NEW.warranty_days > 0 AND NEW.warranty_terms IS NULL)
            )
        )
        OR (
            NEW.outcome = 'unresolved'
            AND (
                NEW.unresolved_reason IS NULL
                OR NEW.warranty_days IS NOT NULL
                OR NEW.warranty_terms IS NOT NULL
            )
        ){$partGuard} THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El resultado técnico, su garantía o sus repuestos no son válidos.';
    END IF;
END
SQL);
    }

    private function createMysqlCatalogTrigger(bool $withParts): void
    {
        $partGuard = $withParts
            ? <<<'SQL'

                OR EXISTS (
                    SELECT 1
                    FROM service_part_requirements
                    WHERE catalog_product_id = OLD.id
                )
SQL
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER catalog_quantity_rules_guard_update
BEFORE UPDATE ON catalog_products
FOR EACH ROW
BEGIN
    IF NEW.base_unit_code NOT IN ('unit', 'l', 'm', 'kg')
        OR NEW.quantity_scale > 6
        OR (NEW.base_unit_code = 'unit' AND NEW.quantity_scale <> 0)
        OR (
            (
                NOT (OLD.base_unit_code <=> NEW.base_unit_code)
                OR OLD.quantity_scale <> NEW.quantity_scale
            )
            AND (
                EXISTS (
                    SELECT 1
                    FROM inventory_movement_lines
                    WHERE catalog_product_id = OLD.id
                ){$partGuard}
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Las reglas de cantidad son inválidas o ya poseen movimientos o repuestos.';
    END IF;
END
SQL);
    }

    private function createSqliteImmutablePair(
        string $prefix,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_update\n"
            ."BEFORE UPDATE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'El registro de repuestos es inmutable.');\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'El registro de repuestos no puede eliminarse.');\n"
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
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de repuestos es inmutable.';\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de repuestos no puede eliminarse.';\n"
            ."END"
        );
    }

    private function createSqliteCoreThreeOrderTrigger(): void
    {
        $this->createSqliteOrderTrigger(false);
    }

    private function createMysqlCoreThreeOrderTrigger(): void
    {
        $this->createMysqlOrderTrigger(false);
    }

    private function createSqliteCoreThreeReportTrigger(): void
    {
        $this->createSqliteReportTrigger(false);
    }

    private function createMysqlCoreThreeReportTrigger(): void
    {
        $this->createMysqlReportTrigger(false);
    }

    private function createSqliteCoreThreeCatalogTrigger(): void
    {
        $this->createSqliteCatalogTrigger(false);
    }

    private function createMysqlCoreThreeCatalogTrigger(): void
    {
        $this->createMysqlCatalogTrigger(false);
    }
};
