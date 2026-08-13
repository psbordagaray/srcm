<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    private const INSERT_TRIGGER = 'commerce_payments_cash_tender_guard_insert';
    private const UPDATE_TRIGGER = 'commerce_payments_cash_tender_guard_update';

    public function up(): void
    {
        Schema::table('commerce_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('tendered_amount_minor')
                ->nullable()
                ->after('amount_minor');
            $table->unsignedBigInteger('change_amount_minor')
                ->nullable()
                ->after('tendered_amount_minor');
        });

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::table('commerce_payments', function (Blueprint $table): void {
            $table->dropColumn([
                'tendered_amount_minor',
                'change_amount_minor',
            ]);
        });
    }

    private function createGuards(): void
    {
        $this->dropGuards();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_cash_tender_guard_insert
BEFORE INSERT ON commerce_payments
WHEN (
    NEW.method = 'cash'
    AND NOT (
        (
            NEW.tendered_amount_minor IS NULL
            AND NEW.change_amount_minor IS NULL
        )
        OR (
            NEW.tendered_amount_minor IS NOT NULL
            AND NEW.change_amount_minor IS NOT NULL
            AND NEW.tendered_amount_minor >= NEW.amount_minor
            AND NEW.change_amount_minor =
                NEW.tendered_amount_minor - NEW.amount_minor
        )
    )
) OR (
    NEW.method <> 'cash'
    AND (
        NEW.tendered_amount_minor IS NOT NULL
        OR NEW.change_amount_minor IS NOT NULL
    )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'El efectivo entregado y el vuelto no son consistentes con el importe aplicado.'
    );
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_cash_tender_guard_update
BEFORE UPDATE ON commerce_payments
WHEN
    OLD.tendered_amount_minor IS NOT NEW.tendered_amount_minor
    OR OLD.change_amount_minor IS NOT NEW.change_amount_minor
BEGIN
    SELECT RAISE(
        ABORT,
        'La evidencia de efectivo entregado y vuelto es inmutable.'
    );
END
SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_cash_tender_guard_insert
BEFORE INSERT ON commerce_payments
FOR EACH ROW
BEGIN
    IF (
        NEW.method = 'cash'
        AND NOT (
            (
                NEW.tendered_amount_minor IS NULL
                AND NEW.change_amount_minor IS NULL
            )
            OR (
                NEW.tendered_amount_minor IS NOT NULL
                AND NEW.change_amount_minor IS NOT NULL
                AND NEW.tendered_amount_minor >= NEW.amount_minor
                AND NEW.change_amount_minor =
                    NEW.tendered_amount_minor - NEW.amount_minor
            )
        )
    ) OR (
        NEW.method <> 'cash'
        AND (
            NEW.tendered_amount_minor IS NOT NULL
            OR NEW.change_amount_minor IS NOT NULL
        )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El efectivo entregado y el vuelto no son consistentes con el importe aplicado.';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_cash_tender_guard_update
BEFORE UPDATE ON commerce_payments
FOR EACH ROW
BEGIN
    IF NOT (
        OLD.tendered_amount_minor <=> NEW.tendered_amount_minor
    ) OR NOT (
        OLD.change_amount_minor <=> NEW.change_amount_minor
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La evidencia de efectivo entregado y vuelto es inmutable.';
    END IF;
END
SQL);

            return;
        }

        throw new RuntimeException(
            'P4E cash tender guards soportan SQLite y MySQL.'
        );
    }

    private function dropGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER
            );
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER
            );

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER
            );
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER
            );

            return;
        }

        throw new RuntimeException(
            'P4E cash tender guards soportan SQLite y MySQL.'
        );
    }
};
