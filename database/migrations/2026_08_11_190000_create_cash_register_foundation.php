<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('updated_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'normalized_name'],
                'cash_registers_org_name_unique'
            );
            $table->unique(
                'financial_account_id',
                'cash_registers_account_unique'
            );
            $table->index(
                ['organization_id', 'active'],
                'cash_registers_org_active_index'
            );
        });

        Schema::create(
            'cash_register_sessions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('cash_register_id')
                    ->constrained('cash_registers')
                    ->restrictOnDelete();
                $table->foreignId('opened_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('status', 30);
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('opening_amount_minor');
                $table->string('idempotency_key', 191);
                $table->char('fingerprint', 64);
                $table->timestampTz('opened_at');
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'cash_register_sessions_org_idem_unique'
                );
                $table->index(
                    ['organization_id', 'status'],
                    'cash_register_sessions_org_status_index'
                );
                $table->index(
                    ['cash_register_id', 'status'],
                    'cash_register_sessions_register_status_index'
                );
                $table->index(
                    ['opened_by_user_id', 'status'],
                    'cash_register_sessions_user_status_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
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
            "La integridad de caja no está implementada para {$driver}."
        );
    }

    private function dropGuards(): void
    {
        foreach ($this->triggerNames() as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'cash_registers_guard_insert',
            'cash_registers_guard_update',
            'cash_registers_guard_delete',
            'cash_register_sessions_guard_insert',
            'cash_register_sessions_guard_update',
            'cash_register_sessions_guard_delete',
            'cash_register_financial_account_guard_update',
        ];
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_registers_guard_insert
BEFORE INSERT ON cash_registers
WHEN NOT EXISTS (
    SELECT 1
    FROM financial_accounts account
    WHERE account.id = NEW.financial_account_id
        AND account.organization_id = NEW.organization_id
        AND account.type = 'cash_box'
)
BEGIN
    SELECT RAISE(ABORT, 'La caja operativa requiere una cuenta de efectivo de la misma organización.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_registers_guard_update
BEFORE UPDATE ON cash_registers
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.created_by_user_id <> OLD.created_by_user_id
    OR (
        OLD.active = 1
        AND NEW.active = 0
        AND EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            WHERE session.cash_register_id = OLD.id
                AND session.status = 'open'
        )
    )
    OR (
        NEW.financial_account_id <> OLD.financial_account_id
        AND EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            WHERE session.cash_register_id = OLD.id
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.type = 'cash_box'
    )
BEGIN
    SELECT RAISE(ABORT, 'La actualización de la caja operativa no es válida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_registers_guard_delete
BEFORE DELETE ON cash_registers
BEGIN
    SELECT RAISE(ABORT, 'La caja operativa no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_sessions_guard_insert
BEFORE INSERT ON cash_register_sessions
WHEN NEW.status <> 'open'
    OR NEW.opening_amount_minor < 0
    OR NOT EXISTS (
        SELECT 1
        FROM cash_registers register_row
        JOIN financial_accounts account
            ON account.id = register_row.financial_account_id
        WHERE register_row.id = NEW.cash_register_id
            AND register_row.organization_id = NEW.organization_id
            AND register_row.active = 1
            AND account.organization_id = NEW.organization_id
            AND account.active = 1
            AND account.type = 'cash_box'
            AND account.currency_code = NEW.currency_code
    )
    OR EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        WHERE session.cash_register_id = NEW.cash_register_id
            AND session.status = 'open'
    )
    OR EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        WHERE session.organization_id = NEW.organization_id
            AND session.opened_by_user_id = NEW.opened_by_user_id
            AND session.status = 'open'
    )
BEGIN
    SELECT RAISE(ABORT, 'La apertura del turno de caja no es válida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_sessions_guard_update
BEFORE UPDATE ON cash_register_sessions
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.cash_register_id <> OLD.cash_register_id
    OR NEW.opened_by_user_id <> OLD.opened_by_user_id
    OR NEW.currency_code <> OLD.currency_code
    OR NEW.opening_amount_minor <> OLD.opening_amount_minor
    OR NEW.idempotency_key <> OLD.idempotency_key
    OR NEW.fingerprint <> OLD.fingerprint
    OR NEW.opened_at <> OLD.opened_at
    OR NEW.created_at <> OLD.created_at
    OR NEW.status NOT IN ('open', 'closing_requested', 'closed')
    OR OLD.status = 'closed'
    OR (
        OLD.status = 'open'
        AND NEW.status NOT IN ('open', 'closing_requested')
    )
    OR (
        OLD.status = 'closing_requested'
        AND NEW.status NOT IN ('closing_requested', 'closed')
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición del turno de caja no es válida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_sessions_guard_delete
BEFORE DELETE ON cash_register_sessions
BEGIN
    SELECT RAISE(ABORT, 'El turno de caja no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_financial_account_guard_update
BEFORE UPDATE ON financial_accounts
WHEN EXISTS (
        SELECT 1
        FROM cash_registers register_row
        WHERE register_row.financial_account_id = OLD.id
    )
    AND (
        NEW.type <> 'cash_box'
        OR (
            NEW.active = 0
            AND EXISTS (
                SELECT 1
                FROM cash_registers register_row
                WHERE register_row.financial_account_id = OLD.id
                    AND register_row.active = 1
            )
        )
        OR (
            NEW.currency_code <> OLD.currency_code
            AND EXISTS (
                SELECT 1
                FROM cash_registers register_row
                JOIN cash_register_sessions session
                    ON session.cash_register_id = register_row.id
                WHERE register_row.financial_account_id = OLD.id
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La cuenta financiera está protegida por una caja operativa.');
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_registers_guard_insert
BEFORE INSERT ON cash_registers
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.type = 'cash_box'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La caja operativa requiere una cuenta de efectivo de la misma organizacion.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_registers_guard_update
BEFORE UPDATE ON cash_registers
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.created_by_user_id <> OLD.created_by_user_id
        OR (
            OLD.active = 1
            AND NEW.active = 0
            AND EXISTS (
                SELECT 1
                FROM cash_register_sessions session
                WHERE session.cash_register_id = OLD.id
                    AND session.status = 'open'
            )
        )
        OR (
            NEW.financial_account_id <> OLD.financial_account_id
            AND EXISTS (
                SELECT 1
                FROM cash_register_sessions session
                WHERE session.cash_register_id = OLD.id
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id = NEW.financial_account_id
                AND account.organization_id = NEW.organization_id
                AND account.type = 'cash_box'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La actualizacion de la caja operativa no es valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_registers_guard_delete
BEFORE DELETE ON cash_registers
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La caja operativa no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_sessions_guard_insert
BEFORE INSERT ON cash_register_sessions
FOR EACH ROW
BEGIN
    IF NEW.status <> 'open'
        OR NOT EXISTS (
            SELECT 1
            FROM cash_registers register_row
            JOIN financial_accounts account
                ON account.id = register_row.financial_account_id
            WHERE register_row.id = NEW.cash_register_id
                AND register_row.organization_id = NEW.organization_id
                AND register_row.active = 1
                AND account.organization_id = NEW.organization_id
                AND account.active = 1
                AND account.type = 'cash_box'
                AND account.currency_code = NEW.currency_code
        )
        OR EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            WHERE session.cash_register_id = NEW.cash_register_id
                AND session.status = 'open'
        )
        OR EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            WHERE session.organization_id = NEW.organization_id
                AND session.opened_by_user_id = NEW.opened_by_user_id
                AND session.status = 'open'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La apertura del turno de caja no es valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_sessions_guard_update
BEFORE UPDATE ON cash_register_sessions
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.cash_register_id <> OLD.cash_register_id
        OR NEW.opened_by_user_id <> OLD.opened_by_user_id
        OR NEW.currency_code <> OLD.currency_code
        OR NEW.opening_amount_minor <> OLD.opening_amount_minor
        OR NEW.idempotency_key <> OLD.idempotency_key
        OR NEW.fingerprint <> OLD.fingerprint
        OR NEW.opened_at <> OLD.opened_at
        OR NEW.created_at <> OLD.created_at
        OR NEW.status NOT IN ('open', 'closing_requested', 'closed')
        OR OLD.status = 'closed'
        OR (
            OLD.status = 'open'
            AND NEW.status NOT IN ('open', 'closing_requested')
        )
        OR (
            OLD.status = 'closing_requested'
            AND NEW.status NOT IN ('closing_requested', 'closed')
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transicion del turno de caja no es valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_sessions_guard_delete
BEFORE DELETE ON cash_register_sessions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El turno de caja no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_financial_account_guard_update
BEFORE UPDATE ON financial_accounts
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM cash_registers register_row
        WHERE register_row.financial_account_id = OLD.id
    )
        AND (
            NEW.type <> 'cash_box'
            OR (
                NEW.active = 0
                AND EXISTS (
                    SELECT 1
                    FROM cash_registers register_row
                    WHERE register_row.financial_account_id = OLD.id
                        AND register_row.active = 1
                )
            )
            OR (
                NEW.currency_code <> OLD.currency_code
                AND EXISTS (
                    SELECT 1
                    FROM cash_registers register_row
                    JOIN cash_register_sessions session
                        ON session.cash_register_id = register_row.id
                    WHERE register_row.financial_account_id = OLD.id
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La cuenta financiera esta protegida por una caja operativa.';
    END IF;
END
SQL);
    }
};
