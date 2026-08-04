<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const CANCELLATION_TRIGGERS = [
        'srv_cancel_req_guard_insert',
        'srv_cancel_req_guard_update',
        'srv_cancel_req_guard_delete',
        'srv_cancel_res_guard_insert',
        'srv_cancel_res_guard_update',
        'srv_cancel_res_guard_delete',
        'srv_cancel_return_guard_insert',
        'srv_cancel_return_guard_update',
        'srv_cancel_return_guard_delete',
    ];

    public function up(): void
    {
        $this->dropCancellationTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS srv_work_items_guard_update');

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteCancellationTriggers();
            $this->createSqliteOrderTrigger(true);
            $this->createSqliteWorkTrigger(true);

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->createMysqlCancellationTriggers();
            $this->createMysqlOrderTrigger(true);
            $this->createMysqlWorkTrigger(true);

            return;
        }

        throw new LogicException(
            'La integridad de cancelaciones no está implementada para '
                .DB::getDriverName().'.'
        );
    }

    public function down(): void
    {
        $this->dropCancellationTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS srv_work_items_guard_update');

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteOrderTrigger(false);
            $this->createSqliteWorkTrigger(false);

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->createMysqlOrderTrigger(false);
            $this->createMysqlWorkTrigger(false);
        }
    }

    private function dropCancellationTriggers(): void
    {
        foreach (self::CANCELLATION_TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteCancellationTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_cancel_req_guard_insert
BEFORE INSERT ON service_cancellation_requests
WHEN json_valid(NEW.exposure_snapshot) <> 1
    OR NEW.reason NOT IN (
        'customer_changed_mind',
        'replacement_device',
        'revised_promise_rejected',
        'part_unavailable',
        'technical_impossibility',
        'business_decision',
        'other'
    )
    OR TRIM(NEW.requester_name) = ''
    OR TRIM(NEW.channel) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NOT EXISTS (
        SELECT 1
        FROM service_orders orders
        WHERE orders.id = NEW.service_order_id
            AND orders.organization_id = NEW.organization_id
            AND orders.status = NEW.order_status_snapshot
            AND orders.status NOT IN (
                'delivered',
                'cancellation_pending',
                'ready_for_return',
                'cancelled'
            )
    )
    OR NEW.has_started_work <> EXISTS (
        SELECT 1
        FROM service_work_items work
        WHERE work.organization_id = NEW.organization_id
            AND work.service_order_id = NEW.service_order_id
            AND work.status IN (
                'in_progress',
                'with_provider',
                'completed',
                'unresolved'
            )
    )
    OR NEW.has_part_purchases <> EXISTS (
        SELECT 1
        FROM service_part_purchases purchase
        WHERE purchase.organization_id = NEW.organization_id
            AND purchase.service_order_id = NEW.service_order_id
    )
    OR NEW.has_part_consumptions <> EXISTS (
        SELECT 1
        FROM service_part_consumptions consumption
        INNER JOIN service_part_requirements requirement
            ON requirement.id = consumption.service_part_requirement_id
            AND requirement.organization_id = consumption.organization_id
        WHERE requirement.organization_id = NEW.organization_id
            AND requirement.service_order_id = NEW.service_order_id
    )
    OR NEW.has_external_custody <> EXISTS (
        SELECT 1
        FROM service_custody_events custody
        WHERE custody.organization_id = NEW.organization_id
            AND custody.service_order_id = NEW.service_order_id
            AND custody.to_holder_type = 'external_provider'
            AND custody.id = (
                SELECT latest.id
                FROM service_custody_events latest
                WHERE latest.organization_id = NEW.organization_id
                    AND latest.service_order_id = NEW.service_order_id
                ORDER BY latest.occurred_at DESC, latest.id DESC
                LIMIT 1
            )
    )
    OR NEW.has_registered_payments <> EXISTS (
        SELECT 1
        FROM commerce_payments payment
        INNER JOIN commerce_sales sale
            ON sale.id = payment.commerce_sale_id
            AND sale.organization_id = payment.organization_id
        WHERE sale.organization_id = NEW.organization_id
            AND sale.service_order_id = NEW.service_order_id
    )
BEGIN
    SELECT RAISE(ABORT, 'La solicitud no coincide con una orden cancelable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_cancel_res_guard_insert
BEFORE INSERT ON service_cancellation_resolutions
WHEN TRIM(NEW.work_disposition) = ''
    OR TRIM(NEW.parts_disposition) = ''
    OR TRIM(NEW.financial_disposition) = ''
    OR TRIM(NEW.return_condition_notes) = ''
    OR TRIM(NEW.accessories_snapshot) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NOT (
        (
            NEW.financial_outcome = 'customer_charge'
            AND NEW.customer_charge_minor > 0
            AND TRIM(COALESCE(NEW.customer_acceptance_reference, '')) <> ''
        )
        OR (
            NEW.financial_outcome IN ('no_charge', 'business_absorbs_costs')
            AND NEW.customer_charge_minor = 0
            AND NEW.customer_acceptance_reference IS NULL
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_cancellation_requests request
        INNER JOIN service_orders orders
            ON orders.id = request.service_order_id
            AND orders.organization_id = request.organization_id
        WHERE request.id = NEW.service_cancellation_request_id
            AND request.organization_id = NEW.organization_id
            AND orders.status = 'cancellation_pending'
            AND NOT EXISTS (
                SELECT 1
                FROM service_work_items work
                WHERE work.organization_id = orders.organization_id
                    AND work.service_order_id = orders.id
                    AND work.status NOT IN (
                        'completed',
                        'unresolved',
                        'cancelled'
                    )
            )
            AND NOT EXISTS (
                SELECT 1
                FROM commerce_sales sale
                WHERE sale.organization_id = orders.organization_id
                    AND sale.service_order_id = orders.id
            )
            AND EXISTS (
                SELECT 1
                FROM service_custody_events custody
                WHERE custody.organization_id = orders.organization_id
                    AND custody.service_order_id = orders.id
                    AND custody.to_holder_type = 'organization'
                    AND custody.id = (
                        SELECT latest.id
                        FROM service_custody_events latest
                        WHERE latest.organization_id = orders.organization_id
                            AND latest.service_order_id = orders.id
                        ORDER BY latest.occurred_at DESC, latest.id DESC
                        LIMIT 1
                    )
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'La resolución conserva compromisos activos o datos financieros inválidos.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_cancel_return_guard_insert
BEFORE INSERT ON service_cancellation_returns
WHEN TRIM(NEW.recipient_name) = ''
    OR TRIM(NEW.condition_notes) = ''
    OR TRIM(NEW.accessories_snapshot) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NOT EXISTS (
        SELECT 1
        FROM service_cancellation_resolutions resolution
        INNER JOIN service_cancellation_requests request
            ON request.id = resolution.service_cancellation_request_id
            AND request.organization_id = resolution.organization_id
        INNER JOIN service_orders orders
            ON orders.id = request.service_order_id
            AND orders.organization_id = request.organization_id
        INNER JOIN service_custody_events custody
            ON custody.id = NEW.service_custody_event_id
            AND custody.organization_id = NEW.organization_id
        WHERE resolution.id = NEW.service_cancellation_resolution_id
            AND resolution.organization_id = NEW.organization_id
            AND orders.id = NEW.service_order_id
            AND orders.status = 'ready_for_return'
            AND custody.service_order_id = orders.id
            AND custody.event_type = 'delivered'
            AND custody.from_holder_type = 'organization'
            AND custody.to_holder_name = NEW.recipient_name
            AND custody.occurred_at = NEW.returned_at
            AND custody.id = (
                SELECT latest.id
                FROM service_custody_events latest
                WHERE latest.organization_id = orders.organization_id
                    AND latest.service_order_id = orders.id
                ORDER BY latest.occurred_at DESC, latest.id DESC
                LIMIT 1
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'La devolución no coincide con resolución, orden y custodia.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_cancel_req',
            'service_cancellation_requests'
        );
        $this->createSqliteImmutablePair(
            'srv_cancel_res',
            'service_cancellation_resolutions'
        );
        $this->createSqliteImmutablePair(
            'srv_cancel_return',
            'service_cancellation_returns'
        );
    }

    private function createMysqlCancellationTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_cancel_req_guard_insert
BEFORE INSERT ON service_cancellation_requests
FOR EACH ROW
BEGIN
    IF NEW.reason NOT IN (
            'customer_changed_mind',
            'replacement_device',
            'revised_promise_rejected',
            'part_unavailable',
            'technical_impossibility',
            'business_decision',
            'other'
        )
        OR TRIM(NEW.requester_name) = ''
        OR TRIM(NEW.channel) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
        SELECT 1
        FROM service_orders orders
        WHERE orders.id = NEW.service_order_id
            AND orders.organization_id = NEW.organization_id
            AND orders.status = NEW.order_status_snapshot
            AND orders.status NOT IN (
                'delivered',
                'cancellation_pending',
                'ready_for_return',
                'cancelled'
            )
        )
        OR NEW.has_started_work <> EXISTS (
            SELECT 1
            FROM service_work_items work
            WHERE work.organization_id = NEW.organization_id
                AND work.service_order_id = NEW.service_order_id
                AND work.status IN (
                    'in_progress',
                    'with_provider',
                    'completed',
                    'unresolved'
                )
        )
        OR NEW.has_part_purchases <> EXISTS (
            SELECT 1
            FROM service_part_purchases purchase
            WHERE purchase.organization_id = NEW.organization_id
                AND purchase.service_order_id = NEW.service_order_id
        )
        OR NEW.has_part_consumptions <> EXISTS (
            SELECT 1
            FROM service_part_consumptions consumption
            INNER JOIN service_part_requirements requirement
                ON requirement.id = consumption.service_part_requirement_id
                AND requirement.organization_id = consumption.organization_id
            WHERE requirement.organization_id = NEW.organization_id
                AND requirement.service_order_id = NEW.service_order_id
        )
        OR NEW.has_external_custody <> EXISTS (
            SELECT 1
            FROM service_custody_events custody
            WHERE custody.organization_id = NEW.organization_id
                AND custody.service_order_id = NEW.service_order_id
                AND custody.to_holder_type = 'external_provider'
                AND custody.id = (
                    SELECT latest.id
                    FROM service_custody_events latest
                    WHERE latest.organization_id = NEW.organization_id
                        AND latest.service_order_id = NEW.service_order_id
                    ORDER BY latest.occurred_at DESC, latest.id DESC
                    LIMIT 1
                )
        )
        OR NEW.has_registered_payments <> EXISTS (
            SELECT 1
            FROM commerce_payments payment
            INNER JOIN commerce_sales sale
                ON sale.id = payment.commerce_sale_id
                AND sale.organization_id = payment.organization_id
            WHERE sale.organization_id = NEW.organization_id
                AND sale.service_order_id = NEW.service_order_id
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La solicitud no coincide con una orden cancelable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_cancel_res_guard_insert
BEFORE INSERT ON service_cancellation_resolutions
FOR EACH ROW
BEGIN
    IF TRIM(NEW.work_disposition) = ''
        OR TRIM(NEW.parts_disposition) = ''
        OR TRIM(NEW.financial_disposition) = ''
        OR TRIM(NEW.return_condition_notes) = ''
        OR TRIM(NEW.accessories_snapshot) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT (
            (
                NEW.financial_outcome = 'customer_charge'
                AND NEW.customer_charge_minor > 0
                AND TRIM(COALESCE(NEW.customer_acceptance_reference, '')) <> ''
            )
            OR (
                NEW.financial_outcome IN ('no_charge', 'business_absorbs_costs')
                AND NEW.customer_charge_minor = 0
                AND NEW.customer_acceptance_reference IS NULL
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_cancellation_requests request
            INNER JOIN service_orders orders
                ON orders.id = request.service_order_id
                AND orders.organization_id = request.organization_id
            WHERE request.id = NEW.service_cancellation_request_id
                AND request.organization_id = NEW.organization_id
                AND orders.status = 'cancellation_pending'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_work_items work
                    WHERE work.organization_id = orders.organization_id
                        AND work.service_order_id = orders.id
                        AND work.status NOT IN (
                            'completed',
                            'unresolved',
                            'cancelled'
                        )
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM commerce_sales sale
                    WHERE sale.organization_id = orders.organization_id
                        AND sale.service_order_id = orders.id
                )
                AND EXISTS (
                    SELECT 1
                    FROM service_custody_events custody
                    WHERE custody.organization_id = orders.organization_id
                        AND custody.service_order_id = orders.id
                        AND custody.to_holder_type = 'organization'
                        AND custody.id = (
                            SELECT latest.id
                            FROM service_custody_events latest
                            WHERE latest.organization_id = orders.organization_id
                                AND latest.service_order_id = orders.id
                            ORDER BY latest.occurred_at DESC, latest.id DESC
                            LIMIT 1
                        )
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La resolución conserva compromisos activos o datos financieros inválidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_cancel_return_guard_insert
BEFORE INSERT ON service_cancellation_returns
FOR EACH ROW
BEGIN
    IF TRIM(NEW.recipient_name) = ''
        OR TRIM(NEW.condition_notes) = ''
        OR TRIM(NEW.accessories_snapshot) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
        SELECT 1
        FROM service_cancellation_resolutions resolution
        INNER JOIN service_cancellation_requests request
            ON request.id = resolution.service_cancellation_request_id
            AND request.organization_id = resolution.organization_id
        INNER JOIN service_orders orders
            ON orders.id = request.service_order_id
            AND orders.organization_id = request.organization_id
        INNER JOIN service_custody_events custody
            ON custody.id = NEW.service_custody_event_id
            AND custody.organization_id = NEW.organization_id
        WHERE resolution.id = NEW.service_cancellation_resolution_id
            AND resolution.organization_id = NEW.organization_id
            AND orders.id = NEW.service_order_id
            AND orders.status = 'ready_for_return'
            AND custody.service_order_id = orders.id
            AND custody.event_type = 'delivered'
            AND custody.from_holder_type = 'organization'
            AND custody.to_holder_name = NEW.recipient_name
            AND custody.occurred_at = NEW.returned_at
            AND custody.id = (
                SELECT latest.id
                FROM service_custody_events latest
                WHERE latest.organization_id = orders.organization_id
                    AND latest.service_order_id = orders.id
                ORDER BY latest.occurred_at DESC, latest.id DESC
                LIMIT 1
            )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La devolución no coincide con resolución, orden y custodia.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_cancel_req',
            'service_cancellation_requests'
        );
        $this->createMysqlImmutablePair(
            'srv_cancel_res',
            'service_cancellation_resolutions'
        );
        $this->createMysqlImmutablePair(
            'srv_cancel_return',
            'service_cancellation_returns'
        );
    }

    private function createSqliteOrderTrigger(bool $withCancellation): void
    {
        $cancellationTransitions = $withCancellation
            ? <<<'SQL'

                OR (
                    OLD.status NOT IN (
                        'delivered',
                        'cancellation_pending',
                        'ready_for_return',
                        'cancelled'
                    )
                    AND NEW.status = 'cancellation_pending'
                )
                OR (OLD.status = 'cancellation_pending' AND NEW.status = 'ready_for_return')
                OR (OLD.status = 'ready_for_return' AND NEW.status = 'cancelled')
SQL
            : '';
        $cancellationGuards = $withCancellation
            ? <<<'SQL'

                OR (
                    NEW.status = 'cancellation_pending'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_requests request
                        WHERE request.organization_id = NEW.organization_id
                            AND request.service_order_id = NEW.id
                            AND request.order_status_snapshot = OLD.status
                    )
                )
                OR (
                    OLD.status = 'cancellation_pending'
                    AND NEW.status = 'ready_for_return'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_resolutions resolution
                        INNER JOIN service_cancellation_requests request
                            ON request.id = resolution.service_cancellation_request_id
                            AND request.organization_id = resolution.organization_id
                        WHERE request.organization_id = NEW.organization_id
                            AND request.service_order_id = NEW.id
                    )
                )
                OR (
                    OLD.status = 'ready_for_return'
                    AND NEW.status = 'cancelled'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_returns cancellation_return
                        WHERE cancellation_return.organization_id = NEW.organization_id
                            AND cancellation_return.service_order_id = NEW.id
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
                OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered'){$cancellationTransitions}
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
            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'ready_for_delivery'
                AND NOT EXISTS (
                    SELECT 1 FROM service_quality_inspections quality
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
                    SELECT 1 FROM service_quality_inspections quality
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
                        SELECT 1 FROM service_deliveries delivery
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
                                SELECT 1 FROM service_warranty_grants warranty
                                WHERE warranty.organization_id = report.organization_id
                                    AND warranty.service_work_report_id = report.id
                            )
                    )
                )
            ){$cancellationGuards}
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición de la orden no es válida o carece de evidencia.');
END
SQL);
    }

    private function createMysqlOrderTrigger(bool $withCancellation): void
    {
        $cancellationTransitions = $withCancellation
            ? <<<'SQL'

                    OR (
                        OLD.status NOT IN (
                            'delivered',
                            'cancellation_pending',
                            'ready_for_return',
                            'cancelled'
                        )
                        AND NEW.status = 'cancellation_pending'
                    )
                    OR (OLD.status = 'cancellation_pending' AND NEW.status = 'ready_for_return')
                    OR (OLD.status = 'ready_for_return' AND NEW.status = 'cancelled')
SQL
            : '';
        $cancellationGuards = $withCancellation
            ? <<<'SQL'

                    OR (
                        NEW.status = 'cancellation_pending'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_cancellation_requests request
                            WHERE request.organization_id = NEW.organization_id
                                AND request.service_order_id = NEW.id
                                AND request.order_status_snapshot = OLD.status
                        )
                    )
                    OR (
                        OLD.status = 'cancellation_pending'
                        AND NEW.status = 'ready_for_return'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_cancellation_resolutions resolution
                            INNER JOIN service_cancellation_requests request
                                ON request.id = resolution.service_cancellation_request_id
                                AND request.organization_id = resolution.organization_id
                            WHERE request.organization_id = NEW.organization_id
                                AND request.service_order_id = NEW.id
                        )
                    )
                    OR (
                        OLD.status = 'ready_for_return'
                        AND NEW.status = 'cancelled'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_cancellation_returns cancellation_return
                            WHERE cancellation_return.organization_id = NEW.organization_id
                                AND cancellation_return.service_order_id = NEW.id
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
                    OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                    OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered'){$cancellationTransitions}
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
                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'ready_for_delivery'
                    AND NOT EXISTS (
                        SELECT 1 FROM service_quality_inspections quality
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
                        SELECT 1 FROM service_quality_inspections quality
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
                            SELECT 1 FROM service_deliveries delivery
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
                                    SELECT 1 FROM service_warranty_grants warranty
                                    WHERE warranty.organization_id = report.organization_id
                                        AND warranty.service_work_report_id = report.id
                                )
                        )
                    )
                ){$cancellationGuards}
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transición de la orden no es válida o carece de evidencia.';
    END IF;
END
SQL);
    }

    private function createSqliteWorkTrigger(bool $withCancellation): void
    {
        $transition = $withCancellation
            ? <<<'SQL'

                OR (
                    OLD.status IN ('planned', 'in_progress', 'with_provider')
                    AND NEW.status = 'cancelled'
                    AND EXISTS (
                        SELECT 1
                        FROM service_orders orders
                        INNER JOIN service_cancellation_requests request
                            ON request.service_order_id = orders.id
                            AND request.organization_id = orders.organization_id
                        WHERE orders.id = NEW.service_order_id
                            AND orders.organization_id = NEW.organization_id
                            AND orders.status = 'cancellation_pending'
                    )
                )
SQL
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER srv_work_items_guard_update
BEFORE UPDATE ON service_work_items
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.service_order_id <> NEW.service_order_id
    OR OLD.service_quote_option_id <> NEW.service_quote_option_id
    OR OLD.sequence <> NEW.sequence
    OR OLD.title <> NEW.title
    OR OLD.description <> NEW.description
    OR OLD.execution_mode <> NEW.execution_mode
    OR OLD.provider_business_party_id IS NOT NEW.provider_business_party_id
    OR OLD.assigned_user_id IS NOT NEW.assigned_user_id
    OR OLD.created_by_user_id <> NEW.created_by_user_id
    OR OLD.planned_at <> NEW.planned_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.fingerprint <> NEW.fingerprint
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'planned' AND NEW.status = 'in_progress')
                OR (OLD.status = 'planned' AND NEW.status = 'with_provider')
                OR (OLD.status = 'with_provider' AND NEW.status = 'in_progress')
                OR (OLD.status = 'in_progress' AND NEW.status = 'completed')
                OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved'){$transition}
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_work_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_work_item_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_work_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_work_item_id = NEW.id
                    )
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El trabajo es inmutable o su transición carece de historia.');
END
SQL);
    }

    private function createMysqlWorkTrigger(bool $withCancellation): void
    {
        $transition = $withCancellation
            ? <<<'SQL'

                    OR (
                        OLD.status IN ('planned', 'in_progress', 'with_provider')
                        AND NEW.status = 'cancelled'
                        AND EXISTS (
                            SELECT 1
                            FROM service_orders orders
                            INNER JOIN service_cancellation_requests request
                                ON request.service_order_id = orders.id
                                AND request.organization_id = orders.organization_id
                            WHERE orders.id = NEW.service_order_id
                                AND orders.organization_id = NEW.organization_id
                                AND orders.status = 'cancellation_pending'
                        )
                    )
SQL
            : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER srv_work_items_guard_update
BEFORE UPDATE ON service_work_items
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.service_order_id <> NEW.service_order_id
        OR OLD.service_quote_option_id <> NEW.service_quote_option_id
        OR OLD.sequence <> NEW.sequence
        OR OLD.title <> NEW.title
        OR OLD.description <> NEW.description
        OR OLD.execution_mode <> NEW.execution_mode
        OR NOT (OLD.provider_business_party_id <=> NEW.provider_business_party_id)
        OR NOT (OLD.assigned_user_id <=> NEW.assigned_user_id)
        OR OLD.created_by_user_id <> NEW.created_by_user_id
        OR OLD.planned_at <> NEW.planned_at
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR OLD.fingerprint <> NEW.fingerprint
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'planned' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'planned' AND NEW.status = 'with_provider')
                    OR (OLD.status = 'with_provider' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'completed')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved'){$transition}
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_work_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_work_item_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_work_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_work_item_id = NEW.id
                        )
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El trabajo es inmutable o su transición carece de historia.';
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
            ."    SELECT RAISE(ABORT, 'La cancelación es inmutable.');\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'La cancelación no puede eliminarse.');\n"
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
            ."    SIGNAL SQLSTATE '45000'\n"
            ."        SET MESSAGE_TEXT = 'La cancelación es inmutable.';\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000'\n"
            ."        SET MESSAGE_TEXT = 'La cancelación no puede eliminarse.';\n"
            ."END"
        );
    }
};
