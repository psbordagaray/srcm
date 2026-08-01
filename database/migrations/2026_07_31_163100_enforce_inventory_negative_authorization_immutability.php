<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'inv_neg_requests_guard_insert',
        'inv_neg_requests_guard_update',
        'inv_neg_requests_guard_delete',
        'inv_neg_request_lines_guard_insert',
        'inv_neg_request_lines_guard_update',
        'inv_neg_request_lines_guard_delete',
        'inv_neg_overrides_guard_insert',
        'inv_neg_overrides_guard_update',
        'inv_neg_overrides_guard_delete',
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
            "La inmutabilidad de autorizaciones no está implementada para {$driver}."
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
CREATE TRIGGER inv_neg_requests_guard_insert
BEFORE INSERT ON inventory_negative_requests
WHEN NEW.status <> 'pending'
    OR NEW.approved_by_user_id IS NOT NULL
    OR NEW.approved_at IS NOT NULL
    OR NEW.rejected_by_user_id IS NOT NULL
    OR NEW.rejected_at IS NOT NULL
    OR NEW.rejection_reason IS NOT NULL
    OR NEW.invalidated_at IS NOT NULL
    OR NEW.invalidation_reason IS NOT NULL
    OR NEW.fulfilled_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'Una solicitud nueva debe estar pendiente.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_requests_guard_update
BEFORE UPDATE ON inventory_negative_requests
WHEN OLD.organization_id IS NOT NEW.organization_id
    OR OLD.public_id IS NOT NEW.public_id
    OR OLD.inventory_movement_id IS NOT NEW.inventory_movement_id
    OR OLD.requested_by_user_id IS NOT NEW.requested_by_user_id
    OR OLD.reason IS NOT NEW.reason
    OR OLD.movement_fingerprint IS NOT NEW.movement_fingerprint
    OR OLD.snapshot_fingerprint IS NOT NEW.snapshot_fingerprint
    OR OLD.request_fingerprint IS NOT NEW.request_fingerprint
    OR OLD.requested_at IS NOT NEW.requested_at
    OR NOT (
        (OLD.status = 'pending' AND NEW.status IN (
            'approved', 'rejected', 'invalidated'
        ))
        OR (OLD.status = 'approved' AND NEW.status = 'fulfilled')
    )
BEGIN
    SELECT RAISE(ABORT, 'La solicitud negativa es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_requests_guard_delete
BEFORE DELETE ON inventory_negative_requests
BEGIN
    SELECT RAISE(ABORT, 'La solicitud negativa no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_request_lines_guard_insert
BEFORE INSERT ON inventory_negative_request_lines
WHEN NOT EXISTS (
    SELECT 1 FROM inventory_negative_requests
    WHERE id = NEW.inventory_negative_request_id
      AND organization_id = NEW.organization_id
      AND status = 'pending'
)
BEGIN
    SELECT RAISE(ABORT, 'La solicitud no admite posiciones nuevas.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_request_lines_guard_update
BEFORE UPDATE ON inventory_negative_request_lines
BEGIN
    SELECT RAISE(ABORT, 'Una posición autorizada es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_request_lines_guard_delete
BEFORE DELETE ON inventory_negative_request_lines
BEGIN
    SELECT RAISE(ABORT, 'Una posición autorizada no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_overrides_guard_insert
BEFORE INSERT ON inventory_negative_overrides
WHEN NEW.status <> 'active'
    OR NEW.consumed_at IS NOT NULL
    OR NEW.revoked_by_user_id IS NOT NULL
    OR NEW.revoked_at IS NOT NULL
    OR NEW.revocation_reason IS NOT NULL
    OR NEW.invalidated_at IS NOT NULL
    OR NEW.invalidation_reason IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'Un Override nuevo debe estar activo.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_overrides_guard_update
BEFORE UPDATE ON inventory_negative_overrides
WHEN OLD.organization_id IS NOT NEW.organization_id
    OR OLD.public_id IS NOT NEW.public_id
    OR OLD.inventory_negative_request_id IS NOT NEW.inventory_negative_request_id
    OR OLD.inventory_movement_id IS NOT NEW.inventory_movement_id
    OR OLD.authorized_user_id IS NOT NEW.authorized_user_id
    OR OLD.granted_by_user_id IS NOT NEW.granted_by_user_id
    OR OLD.movement_fingerprint IS NOT NEW.movement_fingerprint
    OR OLD.snapshot_fingerprint IS NOT NEW.snapshot_fingerprint
    OR OLD.issued_at IS NOT NEW.issued_at
    OR NOT (
        OLD.status = 'active'
        AND NEW.status IN ('consumed', 'revoked', 'invalidated')
    )
BEGIN
    SELECT RAISE(ABORT, 'El Override negativo es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_overrides_guard_delete
BEFORE DELETE ON inventory_negative_overrides
BEGIN
    SELECT RAISE(ABORT, 'El Override negativo no puede eliminarse.');
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_requests_guard_insert
BEFORE INSERT ON inventory_negative_requests
FOR EACH ROW
BEGIN
    IF NEW.status <> 'pending'
        OR NEW.approved_by_user_id IS NOT NULL
        OR NEW.approved_at IS NOT NULL
        OR NEW.rejected_by_user_id IS NOT NULL
        OR NEW.rejected_at IS NOT NULL
        OR NEW.rejection_reason IS NOT NULL
        OR NEW.invalidated_at IS NOT NULL
        OR NEW.invalidation_reason IS NOT NULL
        OR NEW.fulfilled_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una solicitud nueva debe estar pendiente.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_requests_guard_update
BEFORE UPDATE ON inventory_negative_requests
FOR EACH ROW
BEGIN
    IF NOT (OLD.organization_id <=> NEW.organization_id)
        OR NOT (OLD.public_id <=> NEW.public_id)
        OR NOT (OLD.inventory_movement_id <=> NEW.inventory_movement_id)
        OR NOT (OLD.requested_by_user_id <=> NEW.requested_by_user_id)
        OR NOT (OLD.reason <=> NEW.reason)
        OR NOT (OLD.movement_fingerprint <=> NEW.movement_fingerprint)
        OR NOT (OLD.snapshot_fingerprint <=> NEW.snapshot_fingerprint)
        OR NOT (OLD.request_fingerprint <=> NEW.request_fingerprint)
        OR NOT (OLD.requested_at <=> NEW.requested_at)
        OR NOT (
            (OLD.status = 'pending' AND NEW.status IN (
                'approved', 'rejected', 'invalidated'
            ))
            OR (OLD.status = 'approved' AND NEW.status = 'fulfilled')
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La solicitud negativa es inmutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_requests_guard_delete
BEFORE DELETE ON inventory_negative_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La solicitud negativa no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_request_lines_guard_insert
BEFORE INSERT ON inventory_negative_request_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM inventory_negative_requests
        WHERE id = NEW.inventory_negative_request_id
          AND organization_id = NEW.organization_id
          AND status = 'pending'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La solicitud no admite posiciones nuevas.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_request_lines_guard_update
BEFORE UPDATE ON inventory_negative_request_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Una posición autorizada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_request_lines_guard_delete
BEFORE DELETE ON inventory_negative_request_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Una posición autorizada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_overrides_guard_insert
BEFORE INSERT ON inventory_negative_overrides
FOR EACH ROW
BEGIN
    IF NEW.status <> 'active'
        OR NEW.consumed_at IS NOT NULL
        OR NEW.revoked_by_user_id IS NOT NULL
        OR NEW.revoked_at IS NOT NULL
        OR NEW.revocation_reason IS NOT NULL
        OR NEW.invalidated_at IS NOT NULL
        OR NEW.invalidation_reason IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Un Override nuevo debe estar activo.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_overrides_guard_update
BEFORE UPDATE ON inventory_negative_overrides
FOR EACH ROW
BEGIN
    IF NOT (OLD.organization_id <=> NEW.organization_id)
        OR NOT (OLD.public_id <=> NEW.public_id)
        OR NOT (OLD.inventory_negative_request_id <=> NEW.inventory_negative_request_id)
        OR NOT (OLD.inventory_movement_id <=> NEW.inventory_movement_id)
        OR NOT (OLD.authorized_user_id <=> NEW.authorized_user_id)
        OR NOT (OLD.granted_by_user_id <=> NEW.granted_by_user_id)
        OR NOT (OLD.movement_fingerprint <=> NEW.movement_fingerprint)
        OR NOT (OLD.snapshot_fingerprint <=> NEW.snapshot_fingerprint)
        OR NOT (OLD.issued_at <=> NEW.issued_at)
        OR NOT (
            OLD.status = 'active'
            AND NEW.status IN ('consumed', 'revoked', 'invalidated')
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El Override negativo es inmutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_overrides_guard_delete
BEFORE DELETE ON inventory_negative_overrides
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El Override negativo no puede eliminarse.';
END
SQL);
    }
};
