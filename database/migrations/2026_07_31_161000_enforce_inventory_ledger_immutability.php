<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TRIGGERS = [
        'inv_movements_guard_update',
        'inv_movements_guard_delete',
        'inv_movement_lines_guard_insert',
        'inv_movement_lines_guard_update',
        'inv_movement_lines_guard_delete',
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
            "La inmutabilidad del libro no está implementada para {$driver}."
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
        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movements_guard_update
BEFORE UPDATE ON inventory_movements
WHEN OLD.status IN ('confirmed', 'cancelled')
    OR OLD.organization_id <> NEW.organization_id
BEGIN
    SELECT RAISE(
        ABORT,
        'Un movimiento finalizado y su organización son inmutables.'
    );
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movements_guard_delete
BEFORE DELETE ON inventory_movements
WHEN OLD.status <> 'draft'
BEGIN
    SELECT RAISE(
        ABORT,
        'Solo un movimiento borrador puede eliminarse.'
    );
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movement_lines_guard_insert
BEFORE INSERT ON inventory_movement_lines
WHEN NOT EXISTS (
    SELECT 1
    FROM inventory_movements
    WHERE id = NEW.inventory_movement_id
      AND organization_id = NEW.organization_id
      AND status = 'draft'
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Solo un movimiento borrador admite líneas nuevas.'
    );
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movement_lines_guard_update
BEFORE UPDATE ON inventory_movement_lines
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.inventory_movement_id <> NEW.inventory_movement_id
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_movements
        WHERE id = OLD.inventory_movement_id
          AND organization_id = OLD.organization_id
          AND status = 'draft'
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea finalizada y su movimiento son inmutables.'
    );
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movement_lines_guard_delete
BEFORE DELETE ON inventory_movement_lines
WHEN NOT EXISTS (
    SELECT 1
    FROM inventory_movements
    WHERE id = OLD.inventory_movement_id
      AND organization_id = OLD.organization_id
      AND status = 'draft'
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de un movimiento finalizado no puede eliminarse.'
    );
END
SQL
        );
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movements_guard_update
BEFORE UPDATE ON inventory_movements
FOR EACH ROW
BEGIN
    IF OLD.status IN ('confirmed', 'cancelled')
        OR OLD.organization_id <> NEW.organization_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Un movimiento finalizado y su organización son inmutables.';
    END IF;
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movements_guard_delete
BEFORE DELETE ON inventory_movements
FOR EACH ROW
BEGIN
    IF OLD.status <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Solo un movimiento borrador puede eliminarse.';
    END IF;
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movement_lines_guard_insert
BEFORE INSERT ON inventory_movement_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM inventory_movements
        WHERE id = NEW.inventory_movement_id
          AND organization_id = NEW.organization_id
          AND status = 'draft'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Solo un movimiento borrador admite líneas nuevas.';
    END IF;
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movement_lines_guard_update
BEFORE UPDATE ON inventory_movement_lines
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.inventory_movement_id <> NEW.inventory_movement_id
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_movements
            WHERE id = OLD.inventory_movement_id
              AND organization_id = OLD.organization_id
              AND status = 'draft'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una línea finalizada y su movimiento son inmutables.';
    END IF;
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER inv_movement_lines_guard_delete
BEFORE DELETE ON inventory_movement_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM inventory_movements
        WHERE id = OLD.inventory_movement_id
          AND organization_id = OLD.organization_id
          AND status = 'draft'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una línea de un movimiento finalizado no puede eliminarse.';
    END IF;
END
SQL
        );
    }
};
