<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const COMPLETION_TRIGGERS = [
        'srv_quality_guard_insert',
        'srv_quality_guard_update',
        'srv_quality_guard_delete',
        'srv_deliveries_guard_insert',
        'srv_deliveries_guard_update',
        'srv_deliveries_guard_delete',
        'srv_warranties_guard_insert',
        'srv_warranties_guard_update',
        'srv_warranties_guard_delete',
    ];

    public function up(): void
    {
        $this->dropCompletionTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');

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
            "La protección de cierre de reparaciones no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropCompletionTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteOrderTrigger(false);

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->createMysqlOrderTrigger(false);
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_quality_guard_insert
BEFORE INSERT ON service_quality_inspections
WHEN NOT EXISTS (
        SELECT 1
        FROM service_orders orders
        WHERE orders.id = NEW.service_order_id
            AND orders.organization_id = NEW.organization_id
            AND orders.status = 'quality_control'
    )
    OR NEW.revision <> (
        SELECT COALESCE(MAX(previous.revision), 0) + 1
        FROM service_quality_inspections previous
        WHERE previous.organization_id = NEW.organization_id
            AND previous.service_order_id = NEW.service_order_id
    )
    OR NEW.check_count < 1
    OR NEW.failed_check_count > NEW.check_count
    OR json_valid(NEW.checks) <> 1
    OR json_array_length(NEW.checks) <> NEW.check_count
    OR NEW.failed_check_count <> (
        SELECT COUNT(*)
        FROM json_each(NEW.checks) quality_check
        WHERE json_extract(quality_check.value, '$.passed') = 0
    )
    OR NOT (
        (
            NEW.outcome = 'approved'
            AND NEW.failed_check_count = 0
            AND NEW.rework_reason IS NULL
        )
        OR (
            NEW.outcome = 'rework_required'
            AND NEW.failed_check_count > 0
            AND TRIM(COALESCE(NEW.rework_reason, '')) <> ''
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_work_items work
        WHERE work.organization_id = NEW.organization_id
            AND work.service_order_id = NEW.service_order_id
    )
    OR EXISTS (
        SELECT 1
        FROM service_work_items work
        WHERE work.organization_id = NEW.organization_id
            AND work.service_order_id = NEW.service_order_id
            AND work.status <> 'completed'
    )
BEGIN
    SELECT RAISE(ABORT, 'El control de calidad no coincide con una orden completada.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_deliveries_guard_insert
BEFORE INSERT ON service_deliveries
WHEN NOT EXISTS (
        SELECT 1
        FROM service_orders orders
        WHERE orders.id = NEW.service_order_id
            AND orders.organization_id = NEW.organization_id
            AND orders.status = 'ready_for_delivery'
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_quality_inspections quality
        WHERE quality.id = NEW.service_quality_inspection_id
            AND quality.organization_id = NEW.organization_id
            AND quality.service_order_id = NEW.service_order_id
            AND quality.outcome = 'approved'
            AND NEW.delivered_at >= quality.inspected_at
            AND NOT EXISTS (
                SELECT 1
                FROM service_quality_inspections later
                WHERE later.organization_id = quality.organization_id
                    AND later.service_order_id = quality.service_order_id
                    AND later.revision > quality.revision
            )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_custody_events custody
        WHERE custody.id = NEW.service_custody_event_id
            AND custody.organization_id = NEW.organization_id
            AND custody.service_order_id = NEW.service_order_id
            AND custody.event_type = 'delivered'
            AND custody.from_holder_type = 'organization'
            AND custody.to_holder_name = NEW.recipient_name
            AND custody.occurred_at = NEW.delivered_at
            AND custody.id = (
                SELECT MAX(latest.id)
                FROM service_custody_events latest
                WHERE latest.organization_id = NEW.organization_id
                    AND latest.service_order_id = NEW.service_order_id
            )
    )
    OR (
        NEW.customer_conformity = 0
        AND TRIM(COALESCE(NEW.notes, '')) = ''
    )
BEGIN
    SELECT RAISE(ABORT, 'La entrega no coincide con calidad, custodia o conformidad.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranties_guard_insert
BEFORE INSERT ON service_warranty_grants
WHEN NEW.warranty_days < 1
    OR NEW.expires_at <= NEW.starts_at
    OR NOT EXISTS (
        SELECT 1
        FROM service_deliveries delivery
        INNER JOIN service_work_reports report
            ON report.id = NEW.service_work_report_id
            AND report.organization_id = NEW.organization_id
        INNER JOIN service_work_items work
            ON work.id = report.service_work_item_id
            AND work.organization_id = report.organization_id
        WHERE delivery.id = NEW.service_delivery_id
            AND delivery.organization_id = NEW.organization_id
            AND work.service_order_id = delivery.service_order_id
            AND report.outcome = 'completed'
            AND report.warranty_days = NEW.warranty_days
            AND report.warranty_terms = NEW.coverage_terms
            AND delivery.delivered_at = NEW.starts_at
    )
BEGIN
    SELECT RAISE(ABORT, 'La garantía no coincide con el trabajo entregado.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_quality',
            'service_quality_inspections'
        );
        $this->createSqliteImmutablePair(
            'srv_deliveries',
            'service_deliveries'
        );
        $this->createSqliteImmutablePair(
            'srv_warranties',
            'service_warranty_grants'
        );
        $this->createSqliteOrderTrigger(true);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_quality_guard_insert
BEFORE INSERT ON service_quality_inspections
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
            SELECT 1
            FROM service_orders orders
            WHERE orders.id = NEW.service_order_id
                AND orders.organization_id = NEW.organization_id
                AND orders.status = 'quality_control'
        )
        OR NEW.revision <> (
            SELECT COALESCE(MAX(previous.revision), 0) + 1
            FROM service_quality_inspections previous
            WHERE previous.organization_id = NEW.organization_id
                AND previous.service_order_id = NEW.service_order_id
        )
        OR NEW.check_count < 1
        OR NEW.failed_check_count > NEW.check_count
        OR JSON_LENGTH(NEW.checks) <> NEW.check_count
        OR NEW.failed_check_count <> (
            SELECT COUNT(*)
            FROM JSON_TABLE(
                NEW.checks,
                '$[*]' COLUMNS (
                    passed TINYINT PATH '$.passed'
                )
            ) AS quality_check
            WHERE quality_check.passed = 0
        )
        OR NOT (
            (
                NEW.outcome = 'approved'
                AND NEW.failed_check_count = 0
                AND NEW.rework_reason IS NULL
            )
            OR (
                NEW.outcome = 'rework_required'
                AND NEW.failed_check_count > 0
                AND TRIM(COALESCE(NEW.rework_reason, '')) <> ''
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_work_items work
            WHERE work.organization_id = NEW.organization_id
                AND work.service_order_id = NEW.service_order_id
        )
        OR EXISTS (
            SELECT 1
            FROM service_work_items work
            WHERE work.organization_id = NEW.organization_id
                AND work.service_order_id = NEW.service_order_id
                AND work.status <> 'completed'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El control de calidad no coincide con una orden completada.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_deliveries_guard_insert
BEFORE INSERT ON service_deliveries
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
            SELECT 1
            FROM service_orders orders
            WHERE orders.id = NEW.service_order_id
                AND orders.organization_id = NEW.organization_id
                AND orders.status = 'ready_for_delivery'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_quality_inspections quality
            WHERE quality.id = NEW.service_quality_inspection_id
                AND quality.organization_id = NEW.organization_id
                AND quality.service_order_id = NEW.service_order_id
                AND quality.outcome = 'approved'
                AND NEW.delivered_at >= quality.inspected_at
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_quality_inspections later
                    WHERE later.organization_id = quality.organization_id
                        AND later.service_order_id = quality.service_order_id
                        AND later.revision > quality.revision
                )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_custody_events custody
            WHERE custody.id = NEW.service_custody_event_id
                AND custody.organization_id = NEW.organization_id
                AND custody.service_order_id = NEW.service_order_id
                AND custody.event_type = 'delivered'
                AND custody.from_holder_type = 'organization'
                AND custody.to_holder_name = NEW.recipient_name
                AND custody.occurred_at = NEW.delivered_at
                AND custody.id = (
                    SELECT MAX(latest.id)
                    FROM service_custody_events latest
                    WHERE latest.organization_id = NEW.organization_id
                        AND latest.service_order_id = NEW.service_order_id
                )
        )
        OR (
            NEW.customer_conformity = 0
            AND TRIM(COALESCE(NEW.notes, '')) = ''
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La entrega no coincide con calidad, custodia o conformidad.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranties_guard_insert
BEFORE INSERT ON service_warranty_grants
FOR EACH ROW
BEGIN
    IF NEW.warranty_days < 1
        OR NEW.expires_at <= NEW.starts_at
        OR NOT EXISTS (
            SELECT 1
            FROM service_deliveries delivery
            INNER JOIN service_work_reports report
                ON report.id = NEW.service_work_report_id
                AND report.organization_id = NEW.organization_id
            INNER JOIN service_work_items work
                ON work.id = report.service_work_item_id
                AND work.organization_id = report.organization_id
            WHERE delivery.id = NEW.service_delivery_id
                AND delivery.organization_id = NEW.organization_id
                AND work.service_order_id = delivery.service_order_id
                AND report.outcome = 'completed'
                AND report.warranty_days = NEW.warranty_days
                AND report.warranty_terms = NEW.coverage_terms
                AND delivery.delivered_at = NEW.starts_at
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La garantía no coincide con el trabajo entregado.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_quality',
            'service_quality_inspections'
        );
        $this->createMysqlImmutablePair(
            'srv_deliveries',
            'service_deliveries'
        );
        $this->createMysqlImmutablePair(
            'srv_warranties',
            'service_warranty_grants'
        );
        $this->createMysqlOrderTrigger(true);
    }

    private function createSqliteOrderTrigger(bool $withCompletion): void
    {
        $completionTransitions = $withCompletion
            ? <<<'SQL'

                OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered')
SQL
            : '';
        $completionGuards = $withCompletion
            ? <<<'SQL'

            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'ready_for_delivery'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_quality_inspections quality
                    WHERE quality.organization_id = NEW.organization_id
                        AND quality.service_order_id = NEW.id
                        AND quality.outcome = 'approved'
                        AND quality.id = (
                            SELECT MAX(latest.id)
                            FROM service_quality_inspections latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'in_progress'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_quality_inspections quality
                    WHERE quality.organization_id = NEW.organization_id
                        AND quality.service_order_id = NEW.id
                        AND quality.outcome = 'rework_required'
                        AND quality.id = (
                            SELECT MAX(latest.id)
                            FROM service_quality_inspections latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
            OR (
                OLD.status = 'ready_for_delivery'
                AND NEW.status = 'delivered'
                AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM service_deliveries delivery
                        WHERE delivery.organization_id = NEW.organization_id
                            AND delivery.service_order_id = NEW.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM service_work_reports report
                        INNER JOIN service_work_items work
                            ON work.id = report.service_work_item_id
                            AND work.organization_id = report.organization_id
                        WHERE work.organization_id = NEW.organization_id
                            AND work.service_order_id = NEW.id
                            AND report.outcome = 'completed'
                            AND report.warranty_days > 0
                            AND NOT EXISTS (
                                SELECT 1
                                FROM service_warranty_grants warranty
                                WHERE warranty.organization_id = report.organization_id
                                    AND warranty.service_work_report_id = report.id
                            )
                    )
                )
            )
SQL
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
                OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')
                OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress'){$completionTransitions}
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
            ){$completionGuards}
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición de la orden no es válida o carece de evidencia.');
END
SQL);
    }

    private function createMysqlOrderTrigger(bool $withCompletion): void
    {
        $completionTransitions = $withCompletion
            ? <<<'SQL'

                    OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                    OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered')
SQL
            : '';
        $completionGuards = $withCompletion
            ? <<<'SQL'

                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'ready_for_delivery'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_quality_inspections quality
                        WHERE quality.organization_id = NEW.organization_id
                            AND quality.service_order_id = NEW.id
                            AND quality.outcome = 'approved'
                            AND quality.id = (
                                SELECT MAX(latest.id)
                                FROM service_quality_inspections latest
                                WHERE latest.organization_id = NEW.organization_id
                                    AND latest.service_order_id = NEW.id
                            )
                    )
                )
                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'in_progress'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_quality_inspections quality
                        WHERE quality.organization_id = NEW.organization_id
                            AND quality.service_order_id = NEW.id
                            AND quality.outcome = 'rework_required'
                            AND quality.id = (
                                SELECT MAX(latest.id)
                                FROM service_quality_inspections latest
                                WHERE latest.organization_id = NEW.organization_id
                                    AND latest.service_order_id = NEW.id
                            )
                    )
                )
                OR (
                    OLD.status = 'ready_for_delivery'
                    AND NEW.status = 'delivered'
                    AND (
                        NOT EXISTS (
                            SELECT 1
                            FROM service_deliveries delivery
                            WHERE delivery.organization_id = NEW.organization_id
                                AND delivery.service_order_id = NEW.id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM service_work_reports report
                            INNER JOIN service_work_items work
                                ON work.id = report.service_work_item_id
                                AND work.organization_id = report.organization_id
                            WHERE work.organization_id = NEW.organization_id
                                AND work.service_order_id = NEW.id
                                AND report.outcome = 'completed'
                                AND report.warranty_days > 0
                                AND NOT EXISTS (
                                    SELECT 1
                                    FROM service_warranty_grants warranty
                                    WHERE warranty.organization_id = report.organization_id
                                        AND warranty.service_work_report_id = report.id
                                )
                        )
                    )
                )
SQL
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
                    OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')
                    OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress'){$completionTransitions}
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
                ){$completionGuards}
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transición de la orden no es válida o carece de evidencia.';
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
            ."    SELECT RAISE(ABORT, 'El cierre de servicio es inmutable.');\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'El cierre de servicio no puede eliminarse.');\n"
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
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cierre de servicio es inmutable.';\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cierre de servicio no puede eliminarse.';\n"
            ."END"
        );
    }

    private function dropCompletionTriggers(): void
    {
        foreach (self::COMPLETION_TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
