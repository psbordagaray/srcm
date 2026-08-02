<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'srv_work_items_guard_insert',
        'srv_work_items_guard_update',
        'srv_work_items_guard_delete',
        'srv_work_history_guard_update',
        'srv_work_history_guard_delete',
        'srv_work_custody_guard_insert',
        'srv_work_custody_guard_update',
        'srv_work_custody_guard_delete',
        'srv_work_reports_guard_insert',
        'srv_work_reports_guard_update',
        'srv_work_reports_guard_delete',
    ];

    public function up(): void
    {
        $this->dropWorkTriggers();
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
            "La protección de ejecución no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropWorkTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteCoreTwoOrderTrigger();

            return;
        }

        $this->createMysqlCoreTwoOrderTrigger();
    }

    private function dropWorkTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_insert
BEFORE INSERT ON service_work_items
WHEN NEW.status <> 'planned'
    OR NEW.execution_mode NOT IN ('internal', 'external')
    OR (
        NEW.execution_mode = 'internal'
        AND (
            NEW.assigned_user_id IS NULL
            OR NEW.provider_business_party_id IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id = NEW.organization_id
                    AND membership.user_id = NEW.assigned_user_id
                    AND membership.active = 1
            )
        )
    )
    OR (
        NEW.execution_mode = 'external'
        AND (
            NEW.provider_business_party_id IS NULL
            OR NEW.assigned_user_id IS NOT NULL
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_quote_options quote_option
        INNER JOIN service_quotes quote
            ON quote.id = quote_option.service_quote_id
            AND quote.organization_id = quote_option.organization_id
        INNER JOIN service_quote_decisions decision
            ON decision.service_quote_id = quote.id
            AND decision.organization_id = quote.organization_id
            AND decision.service_quote_option_id = quote_option.id
        WHERE quote_option.id = NEW.service_quote_option_id
            AND quote_option.organization_id = NEW.organization_id
            AND quote.service_order_id = NEW.service_order_id
            AND decision.decision = 'approved'
    )
BEGIN
    SELECT RAISE(ABORT, 'El trabajo no coincide con el alcance aprobado o su responsable.');
END
SQL);

        DB::unprepared(<<<'SQL'
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
                OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved')
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

        $this->createSqliteDeleteTrigger(
            'srv_work_items_guard_delete',
            'service_work_items'
        );
        $this->createSqliteImmutablePair(
            'srv_work_history',
            'service_work_status_histories'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_custody_guard_insert
BEFORE INSERT ON service_work_custody_links
WHEN NEW.direction NOT IN ('dispatch', 'return')
    OR NOT EXISTS (
        SELECT 1
        FROM service_work_items work_item
        INNER JOIN service_custody_events custody
            ON custody.organization_id = work_item.organization_id
            AND custody.service_order_id = work_item.service_order_id
        WHERE work_item.id = NEW.service_work_item_id
            AND work_item.organization_id = NEW.organization_id
            AND custody.id = NEW.service_custody_event_id
            AND (
                (
                    NEW.direction = 'dispatch'
                    AND work_item.execution_mode = 'external'
                    AND work_item.status = 'planned'
                    AND custody.event_type = 'transferred'
                )
                OR (
                    NEW.direction = 'return'
                    AND work_item.execution_mode = 'external'
                    AND work_item.status = 'with_provider'
                    AND custody.event_type = 'returned'
                )
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'El vínculo no coincide con la custodia del trabajo.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_work_custody',
            'service_work_custody_links'
        );

        DB::unprepared(<<<'SQL'
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
    )
BEGIN
    SELECT RAISE(ABORT, 'El resultado técnico o su garantía no son válidos.');
END
SQL);

        $this->createSqliteImmutablePair(
            'srv_work_reports',
            'service_work_reports'
        );
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_insert
BEFORE INSERT ON service_work_items
FOR EACH ROW
BEGIN
    IF NEW.status <> 'planned'
        OR NEW.execution_mode NOT IN ('internal', 'external')
        OR (
            NEW.execution_mode = 'internal'
            AND (
                NEW.assigned_user_id IS NULL
                OR NEW.provider_business_party_id IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id = NEW.organization_id
                        AND membership.user_id = NEW.assigned_user_id
                        AND membership.active = 1
                )
            )
        )
        OR (
            NEW.execution_mode = 'external'
            AND (
                NEW.provider_business_party_id IS NULL
                OR NEW.assigned_user_id IS NOT NULL
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_quote_options quote_option
            INNER JOIN service_quotes quote
                ON quote.id = quote_option.service_quote_id
                AND quote.organization_id = quote_option.organization_id
            INNER JOIN service_quote_decisions decision
                ON decision.service_quote_id = quote.id
                AND decision.organization_id = quote.organization_id
                AND decision.service_quote_option_id = quote_option.id
            WHERE quote_option.id = NEW.service_quote_option_id
                AND quote_option.organization_id = NEW.organization_id
                AND quote.service_order_id = NEW.service_order_id
                AND decision.decision = 'approved'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El trabajo no coincide con el alcance aprobado o su responsable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
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
                    OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved')
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

        $this->createMysqlDeleteTrigger(
            'srv_work_items_guard_delete',
            'service_work_items'
        );
        $this->createMysqlImmutablePair(
            'srv_work_history',
            'service_work_status_histories'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_custody_guard_insert
BEFORE INSERT ON service_work_custody_links
FOR EACH ROW
BEGIN
    IF NEW.direction NOT IN ('dispatch', 'return')
        OR NOT EXISTS (
            SELECT 1
            FROM service_work_items work_item
            INNER JOIN service_custody_events custody
                ON custody.organization_id = work_item.organization_id
                AND custody.service_order_id = work_item.service_order_id
            WHERE work_item.id = NEW.service_work_item_id
                AND work_item.organization_id = NEW.organization_id
                AND custody.id = NEW.service_custody_event_id
                AND (
                    (
                        NEW.direction = 'dispatch'
                        AND work_item.execution_mode = 'external'
                        AND work_item.status = 'planned'
                        AND custody.event_type = 'transferred'
                    )
                    OR (
                        NEW.direction = 'return'
                        AND work_item.execution_mode = 'external'
                        AND work_item.status = 'with_provider'
                        AND custody.event_type = 'returned'
                    )
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El vínculo no coincide con la custodia del trabajo.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_work_custody',
            'service_work_custody_links'
        );

        DB::unprepared(<<<'SQL'
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
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El resultado técnico o su garantía no son válidos.';
    END IF;
END
SQL);

        $this->createMysqlImmutablePair(
            'srv_work_reports',
            'service_work_reports'
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
            ."    SELECT RAISE(ABORT, 'El registro de ejecución es inmutable.');\n"
            ."END"
        );
        $this->createSqliteDeleteTrigger(
            $prefix.'_guard_delete',
            $table
        );
    }

    private function createSqliteDeleteTrigger(
        string $trigger,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$trigger}\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'El registro de ejecución no puede eliminarse.');\n"
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
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de ejecución es inmutable.';\n"
            ."END"
        );
        $this->createMysqlDeleteTrigger(
            $prefix.'_guard_delete',
            $table
        );
    }

    private function createMysqlDeleteTrigger(
        string $trigger,
        string $table
    ): void {
        DB::unprepared(
            "CREATE TRIGGER {$trigger}\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de ejecución no puede eliminarse.';\n"
            ."END"
        );
    }

    private function createSqliteCoreTwoOrderTrigger(): void
    {
        DB::unprepared(<<<'SQL'
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

    private function createMysqlCoreTwoOrderTrigger(): void
    {
        DB::unprepared(<<<'SQL'
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
};
