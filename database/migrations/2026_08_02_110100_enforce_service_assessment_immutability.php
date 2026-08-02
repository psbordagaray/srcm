<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'srv_diagnostics_guard_update',
        'srv_diagnostics_guard_delete',
        'srv_findings_guard_update',
        'srv_findings_guard_delete',
        'srv_quotes_guard_insert',
        'srv_quotes_guard_update',
        'srv_quotes_guard_delete',
        'srv_quote_options_guard_update',
        'srv_quote_options_guard_delete',
        'srv_quote_lines_guard_update',
        'srv_quote_lines_guard_delete',
        'srv_quote_decisions_guard_insert',
        'srv_quote_decisions_guard_update',
        'srv_quote_decisions_guard_delete',
    ];

    public function up(): void
    {
        $this->dropAssessmentTriggers();
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
            "La protección de diagnóstico no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropAssessmentTriggers();
        DB::unprepared('DROP TRIGGER IF EXISTS srv_orders_guard_update');

        if (DB::getDriverName() === 'sqlite') {
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
    OR OLD.status <> NEW.status
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.metadata IS NOT NEW.metadata
BEGIN
    SELECT RAISE(ABORT, 'La orden recibida es inmutable hasta registrar una transición.');
END
SQL);

            return;
        }

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
        OR OLD.status <> NEW.status
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR NOT (OLD.metadata <=> NEW.metadata) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La orden recibida es inmutable hasta registrar una transición.';
    END IF;
END
SQL);
    }

    private function dropAssessmentTriggers(): void
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

        $this->createSqliteImmutablePair(
            'srv_diagnostics',
            'service_diagnostics'
        );
        $this->createSqliteImmutablePair(
            'srv_findings',
            'service_diagnostic_findings'
        );
        $this->createSqliteImmutablePair('srv_quotes', 'service_quotes');
        $this->createSqliteImmutablePair(
            'srv_quote_options',
            'service_quote_options'
        );
        $this->createSqliteImmutablePair(
            'srv_quote_lines',
            'service_quote_lines'
        );
        $this->createSqliteImmutablePair(
            'srv_quote_decisions',
            'service_quote_decisions'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_quotes_guard_insert
BEFORE INSERT ON service_quotes
WHEN NOT EXISTS (
    SELECT 1
    FROM service_diagnostics diagnostic
    WHERE diagnostic.id = NEW.service_diagnostic_id
        AND diagnostic.organization_id = NEW.organization_id
        AND diagnostic.service_order_id = NEW.service_order_id
)
BEGIN
    SELECT RAISE(ABORT, 'El presupuesto debe referir al diagnóstico de la misma orden.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_quote_decisions_guard_insert
BEFORE INSERT ON service_quote_decisions
WHEN (NEW.decision = 'approved' AND NEW.service_quote_option_id IS NULL)
    OR (NEW.decision = 'rejected' AND NEW.service_quote_option_id IS NOT NULL)
    OR (NEW.decision NOT IN ('approved', 'rejected'))
    OR (
        NEW.service_quote_option_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM service_quote_options quote_option
            WHERE quote_option.id = NEW.service_quote_option_id
                AND quote_option.organization_id = NEW.organization_id
                AND quote_option.service_quote_id = NEW.service_quote_id
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La decisión no coincide con una alternativa del presupuesto.');
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
            ."    SELECT RAISE(ABORT, 'El registro técnico es inmutable.');\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'El registro técnico no puede eliminarse.');\n"
            ."END"
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

        $this->createMysqlImmutablePair(
            'srv_diagnostics',
            'service_diagnostics'
        );
        $this->createMysqlImmutablePair(
            'srv_findings',
            'service_diagnostic_findings'
        );
        $this->createMysqlImmutablePair('srv_quotes', 'service_quotes');
        $this->createMysqlImmutablePair(
            'srv_quote_options',
            'service_quote_options'
        );
        $this->createMysqlImmutablePair(
            'srv_quote_lines',
            'service_quote_lines'
        );
        $this->createMysqlImmutablePair(
            'srv_quote_decisions',
            'service_quote_decisions'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_quotes_guard_insert
BEFORE INSERT ON service_quotes
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM service_diagnostics diagnostic
        WHERE diagnostic.id = NEW.service_diagnostic_id
            AND diagnostic.organization_id = NEW.organization_id
            AND diagnostic.service_order_id = NEW.service_order_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El presupuesto debe referir al diagnóstico de la misma orden.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_quote_decisions_guard_insert
BEFORE INSERT ON service_quote_decisions
FOR EACH ROW
BEGIN
    IF (NEW.decision = 'approved' AND NEW.service_quote_option_id IS NULL)
        OR (NEW.decision = 'rejected' AND NEW.service_quote_option_id IS NOT NULL)
        OR (NEW.decision NOT IN ('approved', 'rejected'))
        OR (
            NEW.service_quote_option_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM service_quote_options quote_option
                WHERE quote_option.id = NEW.service_quote_option_id
                    AND quote_option.organization_id = NEW.organization_id
                    AND quote_option.service_quote_id = NEW.service_quote_id
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La decisión no coincide con una alternativa del presupuesto.';
    END IF;
END
SQL);
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
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro técnico es inmutable.';\n"
            ."END"
        );
        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro técnico no puede eliminarse.';\n"
            ."END"
        );
    }
};
