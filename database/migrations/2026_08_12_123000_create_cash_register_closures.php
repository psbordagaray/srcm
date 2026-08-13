<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'cash_register_closures',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('cash_register_session_id')
                    ->unique()
                    ->constrained('cash_register_sessions')
                    ->restrictOnDelete();
                $table->foreignId('cash_register_id')
                    ->constrained('cash_registers')
                    ->restrictOnDelete();
                $table->foreignId('financial_account_id')
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId('opened_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('closed_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('expected_amount_minor');
                $table->unsignedBigInteger('counted_amount_minor');
                $table->bigInteger('difference_minor');
                $table->char('currency_code', 3);
                $table->string('difference_reason', 40)->nullable();
                $table->string('note', 1000)->nullable();
                $table->string('idempotency_key', 191);
                $table->char('fingerprint', 64);
                $table->timestampTz('closed_at');
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'cash_register_closures_org_idem_unique'
                );
                $table->index(
                    ['organization_id', 'closed_at'],
                    'cash_register_closures_org_closed_index'
                );
                $table->index(
                    ['cash_register_id', 'closed_at'],
                    'cash_register_closures_register_closed_index'
                );
            }
        );

        $this->dropSessionUpdateGuard();
        $this->createClosureGuards();
        $this->createSessionUpdateGuard();
    }

    public function down(): void
    {
        if (DB::table('cash_register_closures')->exists()) {
            throw new LogicException(
                'No puede revertirse P4D mientras existan arqueos cerrados.'
            );
        }

        $this->dropSessionUpdateGuard();
        $this->dropClosureGuards();

        Schema::dropIfExists('cash_register_closures');

        $this->createLegacySessionUpdateGuard();
    }

    private function dropSessionUpdateGuard(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS cash_register_sessions_guard_update'
        );
    }

    private function dropClosureGuards(): void
    {
        foreach ([
            'cash_register_closures_guard_insert',
            'cash_register_closures_guard_update',
            'cash_register_closures_guard_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createClosureGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteClosureGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlClosureGuards();

            return;
        }

        throw new LogicException(
            "La integridad P4D no está implementada para {$driver}."
        );
    }

    private function createSqliteClosureGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_closures_guard_insert
BEFORE INSERT ON cash_register_closures
WHEN NEW.counted_amount_minor < 0
    OR NEW.difference_minor <>
        NEW.counted_amount_minor - NEW.expected_amount_minor
    OR (
        NEW.difference_minor = 0
        AND NEW.difference_reason IS NOT NULL
    )
    OR (
        NEW.difference_minor <> 0
        AND (
            NEW.difference_reason IS NULL
            OR NEW.difference_reason NOT IN (
                'counting_confirmed',
                'cash_handling_incident',
                'unexplained',
                'other'
            )
            OR trim(COALESCE(NEW.note, '')) = ''
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        JOIN cash_registers register_row
            ON register_row.id = session.cash_register_id
        JOIN financial_accounts account
            ON account.id = register_row.financial_account_id
        WHERE session.id = NEW.cash_register_session_id
            AND session.organization_id = NEW.organization_id
            AND session.status = 'closing_requested'
            AND session.cash_register_id = NEW.cash_register_id
            AND session.opened_by_user_id = NEW.opened_by_user_id
            AND NEW.closed_by_user_id = session.opened_by_user_id
            AND session.currency_code = NEW.currency_code
            AND register_row.organization_id = NEW.organization_id
            AND register_row.financial_account_id =
                NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.type = 'cash_box'
            AND account.currency_code = NEW.currency_code
            AND NEW.expected_amount_minor =
                session.opening_amount_minor + (
                    SELECT COALESCE(
                        SUM(
                            CASE
                                WHEN movement.direction = 'in'
                                    THEN movement.amount_minor
                                ELSE -movement.amount_minor
                            END
                        ),
                        0
                    )
                    FROM cash_movements movement
                    WHERE movement.cash_register_session_id = session.id
                )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El arqueo/cierre de caja P4D no es válido.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_closures_guard_update
BEFORE UPDATE ON cash_register_closures
BEGIN
    SELECT RAISE(
        ABORT,
        'El arqueo/cierre de caja es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_closures_guard_delete
BEFORE DELETE ON cash_register_closures
BEGIN
    SELECT RAISE(
        ABORT,
        'El arqueo/cierre de caja no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlClosureGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_closures_guard_insert
BEFORE INSERT ON cash_register_closures
FOR EACH ROW
BEGIN
    IF NEW.counted_amount_minor < 0
        OR NEW.difference_minor <>
            NEW.counted_amount_minor - NEW.expected_amount_minor
        OR (
            NEW.difference_minor = 0
            AND NEW.difference_reason IS NOT NULL
        )
        OR (
            NEW.difference_minor <> 0
            AND (
                NEW.difference_reason IS NULL
                OR NEW.difference_reason NOT IN (
                    'counting_confirmed',
                    'cash_handling_incident',
                    'unexplained',
                    'other'
                )
                OR TRIM(COALESCE(NEW.note, '')) = ''
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            JOIN cash_registers register_row
                ON register_row.id = session.cash_register_id
            JOIN financial_accounts account
                ON account.id = register_row.financial_account_id
            WHERE session.id = NEW.cash_register_session_id
                AND session.organization_id = NEW.organization_id
                AND session.status = 'closing_requested'
                AND session.cash_register_id = NEW.cash_register_id
                AND session.opened_by_user_id = NEW.opened_by_user_id
                AND NEW.closed_by_user_id =
                    session.opened_by_user_id
                AND session.currency_code = NEW.currency_code
                AND register_row.organization_id =
                    NEW.organization_id
                AND register_row.financial_account_id =
                    NEW.financial_account_id
                AND account.organization_id = NEW.organization_id
                AND account.type = 'cash_box'
                AND account.currency_code = NEW.currency_code
                AND NEW.expected_amount_minor =
                    session.opening_amount_minor + (
                        SELECT COALESCE(
                            SUM(
                                CASE
                                    WHEN movement.direction = 'in'
                                        THEN movement.amount_minor
                                    ELSE -movement.amount_minor
                                END
                            ),
                            0
                        )
                        FROM cash_movements movement
                        WHERE movement.cash_register_session_id =
                            session.id
                    )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El arqueo/cierre de caja P4D no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_closures_guard_update
BEFORE UPDATE ON cash_register_closures
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'El arqueo/cierre de caja es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_register_closures_guard_delete
BEFORE DELETE ON cash_register_closures
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'El arqueo/cierre de caja no puede eliminarse.';
END
SQL);
    }

    private function createSessionUpdateGuard(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
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
    OR (
        OLD.status = 'closing_requested'
        AND NEW.status = 'closed'
        AND NOT EXISTS (
            SELECT 1
            FROM cash_register_closures closure
            WHERE closure.cash_register_session_id = OLD.id
                AND closure.organization_id = OLD.organization_id
                AND closure.cash_register_id = OLD.cash_register_id
                AND closure.opened_by_user_id =
                    OLD.opened_by_user_id
                AND closure.currency_code = OLD.currency_code
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La transición del turno de caja P4D no es válida.'
    );
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
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
        OR NEW.status NOT IN (
            'open',
            'closing_requested',
            'closed'
        )
        OR OLD.status = 'closed'
        OR (
            OLD.status = 'open'
            AND NEW.status NOT IN (
                'open',
                'closing_requested'
            )
        )
        OR (
            OLD.status = 'closing_requested'
            AND NEW.status NOT IN (
                'closing_requested',
                'closed'
            )
        )
        OR (
            OLD.status = 'closing_requested'
            AND NEW.status = 'closed'
            AND NOT EXISTS (
                SELECT 1
                FROM cash_register_closures closure
                WHERE closure.cash_register_session_id = OLD.id
                    AND closure.organization_id =
                        OLD.organization_id
                    AND closure.cash_register_id =
                        OLD.cash_register_id
                    AND closure.opened_by_user_id =
                        OLD.opened_by_user_id
                    AND closure.currency_code = OLD.currency_code
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La transicion del turno de caja P4D no es valida.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "El cierre P4D no está implementado para {$driver}."
        );
    }

    private function createLegacySessionUpdateGuard(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
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
    SELECT RAISE(
        ABORT,
        'La transición del turno de caja no es válida.'
    );
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
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
        OR NEW.status NOT IN (
            'open',
            'closing_requested',
            'closed'
        )
        OR OLD.status = 'closed'
        OR (
            OLD.status = 'open'
            AND NEW.status NOT IN (
                'open',
                'closing_requested'
            )
        )
        OR (
            OLD.status = 'closing_requested'
            AND NEW.status NOT IN (
                'closing_requested',
                'closed'
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La transicion del turno de caja no es valida.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "El guard legacy no está implementado para {$driver}."
        );
    }
};
