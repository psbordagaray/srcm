<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGER = 'inventory_locations_guard_balance';

    public function up(): void
    {
        $invalidLocation = DB::table('inventory_locations as locations')
            ->where('locations.active', false)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('inventory_balances as balances')
                    ->whereColumn(
                        'balances.organization_id',
                        'locations.organization_id'
                    )
                    ->whereColumn(
                        'balances.inventory_location_id',
                        'locations.id'
                    )
                    ->where('balances.quantity', '!=', 0);
            })
            ->exists();

        if ($invalidLocation) {
            throw new LogicException(
                'Existen ubicaciones inactivas con saldo físico distinto de cero.'
            );
        }

        $this->dropTrigger();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER inventory_locations_guard_balance
BEFORE UPDATE OF active ON inventory_locations
WHEN OLD.active <> 0
    AND NEW.active = 0
    AND EXISTS (
        SELECT 1
        FROM inventory_balances
        WHERE organization_id = OLD.organization_id
          AND inventory_location_id = OLD.id
          AND quantity <> 0
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'No puede inactivar una ubicación con saldo físico distinto de cero.'
    );
END
SQL
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER inventory_locations_guard_balance
BEFORE UPDATE ON inventory_locations
FOR EACH ROW
BEGIN
    IF OLD.active <> 0
        AND NEW.active = 0
        AND EXISTS (
            SELECT 1
            FROM inventory_balances
            WHERE organization_id = OLD.organization_id
              AND inventory_location_id = OLD.id
              AND quantity <> 0
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No puede inactivar una ubicación con saldo físico distinto de cero.';
    END IF;
END
SQL
            );

            return;
        }

        throw new LogicException(
            "La protección de ubicaciones no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropTrigger();
    }

    private function dropTrigger(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS '.self::TRIGGER
        );
    }
};
