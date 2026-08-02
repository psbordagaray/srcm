<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'srv_assets_guard_update',
        'srv_assets_guard_delete',
        'srv_asset_ids_guard_update',
        'srv_asset_ids_guard_delete',
        'srv_orders_guard_update',
        'srv_orders_guard_delete',
        'srv_intakes_guard_update',
        'srv_intakes_guard_delete',
        'srv_status_history_guard_update',
        'srv_status_history_guard_delete',
        'srv_custody_guard_update',
        'srv_custody_guard_delete',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        $this->dropTriggers();

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            "La protección de órdenes no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropTriggers();
    }

    private function dropTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_assets_guard_update
BEFORE UPDATE ON service_assets
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
BEGIN
    SELECT RAISE(ABORT, 'La identidad organizacional del activo es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_assets_guard_delete
BEFORE DELETE ON service_assets
BEGIN
    SELECT RAISE(ABORT, 'Un activo de servicio no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_asset_ids_guard_update
BEFORE UPDATE ON service_asset_identifiers
BEGIN
    SELECT RAISE(ABORT, 'Los identificadores técnicos son inmutables.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_asset_ids_guard_delete
BEFORE DELETE ON service_asset_identifiers
BEGIN
    SELECT RAISE(ABORT, 'Un identificador técnico no puede eliminarse.');
END
SQL);

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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_orders_guard_delete
BEFORE DELETE ON service_orders
BEGIN
    SELECT RAISE(ABORT, 'Una orden de servicio no puede eliminarse.');
END
SQL);

        foreach ([
            'srv_intakes' => 'service_order_intakes',
            'srv_status_history' => 'service_order_status_histories',
            'srv_custody' => 'service_custody_events',
        ] as $prefix => $table) {
            DB::unprepared(
                "CREATE TRIGGER {$prefix}_guard_update\n"
                ."BEFORE UPDATE ON {$table}\n"
                ."BEGIN\n"
                ."    SELECT RAISE(ABORT, 'El registro histórico es inmutable.');\n"
                ."END"
            );
            DB::unprepared(
                "CREATE TRIGGER {$prefix}_guard_delete\n"
                ."BEFORE DELETE ON {$table}\n"
                ."BEGIN\n"
                ."    SELECT RAISE(ABORT, 'El registro histórico no puede eliminarse.');\n"
                ."END"
            );
        }
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_assets_guard_update
BEFORE UPDATE ON service_assets
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.public_id <> NEW.public_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La identidad organizacional del activo es inmutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_assets_guard_delete
BEFORE DELETE ON service_assets
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Un activo de servicio no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_asset_ids_guard_update
BEFORE UPDATE ON service_asset_identifiers
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los identificadores técnicos son inmutables.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_asset_ids_guard_delete
BEFORE DELETE ON service_asset_identifiers
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Un identificador técnico no puede eliminarse.';
END
SQL);

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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_orders_guard_delete
BEFORE DELETE ON service_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Una orden de servicio no puede eliminarse.';
END
SQL);

        foreach ([
            'srv_intakes' => 'service_order_intakes',
            'srv_status_history' => 'service_order_status_histories',
            'srv_custody' => 'service_custody_events',
        ] as $prefix => $table) {
            DB::unprepared(
                "CREATE TRIGGER {$prefix}_guard_update\n"
                ."BEFORE UPDATE ON {$table}\n"
                ."FOR EACH ROW\n"
                ."BEGIN\n"
                ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro histórico es inmutable.';\n"
                ."END"
            );
            DB::unprepared(
                "CREATE TRIGGER {$prefix}_guard_delete\n"
                ."BEFORE DELETE ON {$table}\n"
                ."FOR EACH ROW\n"
                ."BEGIN\n"
                ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro histórico no puede eliminarse.';\n"
                ."END"
            );
        }
    }
};
