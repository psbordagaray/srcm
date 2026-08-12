<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('cash_register_session_id')
                ->constrained('cash_register_sessions')
                ->restrictOnDelete();
            $table->foreignId('cash_register_id')
                ->constrained('cash_registers')
                ->restrictOnDelete();
            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();
            $table->foreignId('commerce_payment_id')
                ->unique()
                ->constrained('commerce_payments')
                ->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('type', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('idempotency_key', 191);
            $table->char('fingerprint', 64);
            $table->foreignId('recorded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'cash_movements_org_idem_unique'
            );
            $table->index(
                ['cash_register_session_id', 'occurred_at'],
                'cash_movements_session_occurred_index'
            );
            $table->index(
                ['organization_id', 'occurred_at'],
                'cash_movements_org_occurred_index'
            );
        });

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('cash_movements');
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
            "La integridad del libro de efectivo no está implementada para {$driver}."
        );
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

    private function createSqliteGuards(): void
    {
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
    SELECT RAISE(ABORT, 'El movimiento de efectivo no es válido.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_update
BEFORE UPDATE ON cash_movements
BEGIN
    SELECT RAISE(ABORT, 'El movimiento de efectivo es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_delete
BEFORE DELETE ON cash_movements
BEGIN
    SELECT RAISE(ABORT, 'El movimiento de efectivo no puede eliminarse.');
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
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El movimiento de efectivo no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_update
BEFORE UPDATE ON cash_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El movimiento de efectivo es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_delete
BEFORE DELETE ON cash_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El movimiento de efectivo no puede eliminarse.';
END
SQL);
    }
};
