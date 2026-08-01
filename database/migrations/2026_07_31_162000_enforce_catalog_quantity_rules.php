<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TRIGGERS = [
        'catalog_quantity_rules_guard_insert',
        'catalog_quantity_rules_guard_update',
    ];

    public function up(): void
    {
        $invalidProduct = DB::table('catalog_products')
            ->where(function ($query): void {
                $query->whereNotIn(
                    'base_unit_code',
                    ['unit', 'l', 'm', 'kg']
                )
                    ->orWhere('quantity_scale', '<', 0)
                    ->orWhere('quantity_scale', '>', 6)
                    ->orWhere(function ($unitQuery): void {
                        $unitQuery->where('base_unit_code', 'unit')
                            ->where('quantity_scale', '!=', 0);
                    });
            })
            ->exists();

        if ($invalidProduct) {
            throw new LogicException(
                'Existen productos con reglas de cantidad inválidas.'
            );
        }

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
            "Las reglas de cantidad no están protegidas para {$driver}."
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
CREATE TRIGGER catalog_quantity_rules_guard_insert
BEFORE INSERT ON catalog_products
WHEN NEW.quantity_scale < 0
    OR NEW.quantity_scale > 6
    OR NEW.base_unit_code NOT IN ('unit', 'l', 'm', 'kg')
    OR (NEW.base_unit_code = 'unit' AND NEW.quantity_scale <> 0)
BEGIN
    SELECT RAISE(
        ABORT,
        'La unidad base y la precisión del producto son inválidas.'
    );
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
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
        AND EXISTS (
            SELECT 1
            FROM inventory_movement_lines
            WHERE catalog_product_id = OLD.id
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Las reglas de cantidad son inválidas o ya poseen movimientos.'
    );
END
SQL
        );
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER catalog_quantity_rules_guard_insert
BEFORE INSERT ON catalog_products
FOR EACH ROW
BEGIN
    IF NEW.base_unit_code NOT IN ('unit', 'l', 'm', 'kg')
        OR NEW.quantity_scale > 6
        OR (NEW.base_unit_code = 'unit' AND NEW.quantity_scale <> 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La unidad base y la precisión del producto son inválidas.';
    END IF;
END
SQL
        );

        DB::unprepared(
            <<<'SQL'
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
            AND EXISTS (
                SELECT 1
                FROM inventory_movement_lines
                WHERE catalog_product_id = OLD.id
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Las reglas de cantidad son inválidas o ya poseen movimientos.';
    END IF;
END
SQL
        );
    }
};
