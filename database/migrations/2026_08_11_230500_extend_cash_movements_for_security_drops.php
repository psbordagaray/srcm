<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropGuards();

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('commerce_payment_id')
                ->nullable()
                ->change();

            $table->foreignId('destination_financial_account_id')
                ->nullable()
                ->after('financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();

            $table->string('reason_code', 40)
                ->nullable()
                ->after('type');

            $table->string('note', 1000)
                ->nullable()
                ->after('reason_code');

            $table->index(
                ['destination_financial_account_id', 'occurred_at'],
                'cash_movements_destination_occurred_index'
            );
        });

        $this->createGuards();
    }

    public function down(): void
    {
        if (
            DB::table('cash_movements')
                ->where('type', '<>', 'sale_payment')
                ->exists()
        ) {
            throw new LogicException(
                'No puede revertirse P4C mientras existan movimientos de caja '
                .'posteriores a sale_payment.'
            );
        }

        $this->dropGuards();

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropIndex(
                'cash_movements_destination_occurred_index'
            );
            $table->dropForeign([
                'destination_financial_account_id',
            ]);
            $table->dropColumn([
                'destination_financial_account_id',
                'reason_code',
                'note',
            ]);

            $table->unsignedBigInteger('commerce_payment_id')
                ->nullable(false)
                ->change();
        });

        $this->createLegacyGuards();
    }

    private function dropGuards(): void
    {
        foreach ([
            'cash_movements_guard_insert',
            'cash_movements_guard_update',
            'cash_movements_guard_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlGuards();

            return;
        }

        throw new LogicException(
            "La integridad P4C no está implementada para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_insert
BEFORE INSERT ON cash_movements
WHEN NEW.amount_minor < 1
    OR NEW.type NOT IN ('sale_payment', 'security_drop')
    OR NOT EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        JOIN cash_registers register_row
            ON register_row.id = session.cash_register_id
        JOIN financial_accounts account
            ON account.id = register_row.financial_account_id
        WHERE session.id = NEW.cash_register_session_id
            AND session.organization_id = NEW.organization_id
            AND session.status = 'open'
            AND session.cash_register_id = NEW.cash_register_id
            AND session.opened_by_user_id = NEW.recorded_by_user_id
            AND register_row.organization_id = NEW.organization_id
            AND register_row.active = 1
            AND register_row.financial_account_id =
                NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.active = 1
            AND account.type = 'cash_box'
            AND account.currency_code = NEW.currency_code
    )
    OR (
        NEW.type = 'sale_payment'
        AND (
            NEW.direction <> 'in'
            OR NEW.commerce_payment_id IS NULL
            OR NEW.destination_financial_account_id IS NOT NULL
            OR NEW.reason_code IS NOT NULL
            OR NEW.note IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM commerce_payments payment
                WHERE payment.id = NEW.commerce_payment_id
                    AND payment.organization_id =
                        NEW.organization_id
                    AND payment.method = 'cash'
                    AND payment.financial_account_id =
                        NEW.financial_account_id
                    AND payment.amount_minor = NEW.amount_minor
                    AND payment.received_by_user_id =
                        NEW.recorded_by_user_id
            )
        )
    )
    OR (
        NEW.type = 'security_drop'
        AND (
            NEW.direction <> 'out'
            OR NEW.commerce_payment_id IS NOT NULL
            OR NEW.destination_financial_account_id IS NULL
            OR NEW.reason_code IS NULL
            OR NEW.reason_code NOT IN (
                'excess_cash',
                'scheduled_drop',
                'supervisor_request',
                'other'
            )
            OR NOT EXISTS (
                SELECT 1
                FROM financial_accounts destination
                WHERE destination.id =
                    NEW.destination_financial_account_id
                    AND destination.organization_id =
                        NEW.organization_id
                    AND destination.active = 1
                    AND destination.type = 'cash_reserve'
                    AND destination.currency_code =
                        NEW.currency_code
                    AND destination.id <>
                        NEW.financial_account_id
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo P4C no es válido.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_update
BEFORE UPDATE ON cash_movements
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_delete
BEFORE DELETE ON cash_movements
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.type NOT IN ('sale_payment', 'security_drop')
        OR NOT EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            JOIN cash_registers register_row
                ON register_row.id = session.cash_register_id
            JOIN financial_accounts account
                ON account.id = register_row.financial_account_id
            WHERE session.id = NEW.cash_register_session_id
                AND session.organization_id = NEW.organization_id
                AND session.status = 'open'
                AND session.cash_register_id = NEW.cash_register_id
                AND session.opened_by_user_id =
                    NEW.recorded_by_user_id
                AND register_row.organization_id =
                    NEW.organization_id
                AND register_row.active = 1
                AND register_row.financial_account_id =
                    NEW.financial_account_id
                AND account.organization_id =
                    NEW.organization_id
                AND account.active = 1
                AND account.type = 'cash_box'
                AND account.currency_code = NEW.currency_code
        )
        OR (
            NEW.type = 'sale_payment'
            AND (
                NEW.direction <> 'in'
                OR NEW.commerce_payment_id IS NULL
                OR NEW.destination_financial_account_id IS NOT NULL
                OR NEW.reason_code IS NOT NULL
                OR NEW.note IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM commerce_payments payment
                    WHERE payment.id = NEW.commerce_payment_id
                        AND payment.organization_id =
                            NEW.organization_id
                        AND payment.method = 'cash'
                        AND payment.financial_account_id =
                            NEW.financial_account_id
                        AND payment.amount_minor =
                            NEW.amount_minor
                        AND payment.received_by_user_id =
                            NEW.recorded_by_user_id
                )
            )
        )
        OR (
            NEW.type = 'security_drop'
            AND (
                NEW.direction <> 'out'
                OR NEW.commerce_payment_id IS NOT NULL
                OR NEW.destination_financial_account_id IS NULL
                OR NEW.reason_code IS NULL
                OR NEW.reason_code NOT IN (
                    'excess_cash',
                    'scheduled_drop',
                    'supervisor_request',
                    'other'
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM financial_accounts destination
                    WHERE destination.id =
                        NEW.destination_financial_account_id
                        AND destination.organization_id =
                            NEW.organization_id
                        AND destination.active = 1
                        AND destination.type = 'cash_reserve'
                        AND destination.currency_code =
                            NEW.currency_code
                        AND destination.id <>
                            NEW.financial_account_id
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de efectivo P4C no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_update
BEFORE UPDATE ON cash_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'El movimiento de efectivo es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_delete
BEFORE DELETE ON cash_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'El movimiento de efectivo no puede eliminarse.';
END
SQL);
    }

    private function createLegacyGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_insert
BEFORE INSERT ON cash_movements
WHEN NEW.amount_minor < 1
    OR NEW.direction <> 'in'
    OR NEW.type <> 'sale_payment'
    OR NOT EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        JOIN cash_registers register_row
            ON register_row.id = session.cash_register_id
        JOIN financial_accounts account
            ON account.id = register_row.financial_account_id
        JOIN commerce_payments payment
            ON payment.id = NEW.commerce_payment_id
        WHERE session.id = NEW.cash_register_session_id
            AND session.organization_id = NEW.organization_id
            AND session.status = 'open'
            AND session.cash_register_id = NEW.cash_register_id
            AND session.opened_by_user_id = NEW.recorded_by_user_id
            AND register_row.organization_id = NEW.organization_id
            AND register_row.active = 1
            AND register_row.financial_account_id =
                NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.active = 1
            AND account.type = 'cash_box'
            AND account.currency_code = NEW.currency_code
            AND payment.organization_id = NEW.organization_id
            AND payment.method = 'cash'
            AND payment.financial_account_id =
                NEW.financial_account_id
            AND payment.amount_minor = NEW.amount_minor
            AND payment.received_by_user_id =
                NEW.recorded_by_user_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo no es válido.'
    );
END
SQL);

            $this->createSqliteImmutableGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.direction <> 'in'
        OR NEW.type <> 'sale_payment'
        OR NOT EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            JOIN cash_registers register_row
                ON register_row.id = session.cash_register_id
            JOIN financial_accounts account
                ON account.id = register_row.financial_account_id
            JOIN commerce_payments payment
                ON payment.id = NEW.commerce_payment_id
            WHERE session.id = NEW.cash_register_session_id
                AND session.organization_id = NEW.organization_id
                AND session.status = 'open'
                AND session.cash_register_id = NEW.cash_register_id
                AND session.opened_by_user_id =
                    NEW.recorded_by_user_id
                AND register_row.organization_id =
                    NEW.organization_id
                AND register_row.active = 1
                AND register_row.financial_account_id =
                    NEW.financial_account_id
                AND account.organization_id =
                    NEW.organization_id
                AND account.active = 1
                AND account.type = 'cash_box'
                AND account.currency_code = NEW.currency_code
                AND payment.organization_id = NEW.organization_id
                AND payment.method = 'cash'
                AND payment.financial_account_id =
                    NEW.financial_account_id
                AND payment.amount_minor = NEW.amount_minor
                AND payment.received_by_user_id =
                    NEW.recorded_by_user_id
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de efectivo no es valido.';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_update
BEFORE UPDATE ON cash_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'El movimiento de efectivo es inmutable.';
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_delete
BEFORE DELETE ON cash_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'El movimiento de efectivo no puede eliminarse.';
END
SQL);

            return;
        }

        throw new LogicException(
            "La integridad legacy no está implementada para {$driver}."
        );
    }

    private function createSqliteImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_update
BEFORE UPDATE ON cash_movements
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_delete
BEFORE DELETE ON cash_movements
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo no puede eliminarse.'
    );
END
SQL);
    }
};
