<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropCashMovementGuards();
        $this->dropClosureInsertGuard();

        Schema::create(
            'cash_security_drop_requests',
            function (Blueprint $table): void {
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
                $table->foreignId('origin_financial_account_id')
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId('destination_financial_account_id')
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('executed_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('resolved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('amount_minor');
                $table->char('currency_code', 3);
                $table->string('reason_code', 40);
                $table->string('note', 1000)->nullable();
                $table->string('status', 24);
                $table->string('request_idempotency_key', 191);
                $table->char('fingerprint', 64);
                $table->string('approval_idempotency_key', 191)
                    ->nullable();
                $table->char('approval_fingerprint', 64)->nullable();
                $table->string('approval_note', 1000)->nullable();
                $table->string('execution_idempotency_key', 191)
                    ->nullable();
                $table->string('resolution_idempotency_key', 191)
                    ->nullable();
                $table->string('resolution_note', 1000)->nullable();
                $table->timestampTz('requested_at');
                $table->timestampTz('approved_at')->nullable();
                $table->timestampTz('executed_at')->nullable();
                $table->timestampTz('resolved_at')->nullable();

                $table->unique(
                    ['organization_id', 'request_idempotency_key'],
                    'cash_drop_req_org_request_idem_unique'
                );
                $table->unique(
                    ['organization_id', 'approval_idempotency_key'],
                    'cash_drop_req_org_approval_idem_unique'
                );
                $table->unique(
                    ['organization_id', 'execution_idempotency_key'],
                    'cash_drop_req_org_execution_idem_unique'
                );
                $table->unique(
                    ['organization_id', 'resolution_idempotency_key'],
                    'cash_drop_req_org_resolution_idem_unique'
                );
                $table->index(
                    ['organization_id', 'status', 'requested_at'],
                    'cash_drop_req_org_status_requested_index'
                );
                $table->index(
                    ['cash_register_session_id', 'status'],
                    'cash_drop_req_session_status_index'
                );
            }
        );

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->foreignId('cash_security_drop_request_id')
                ->nullable()
                ->after('destination_financial_account_id')
                ->constrained('cash_security_drop_requests')
                ->restrictOnDelete();

            $table->unique(
                'cash_security_drop_request_id',
                'cash_movements_security_drop_request_unique'
            );
        });

        $this->createRequestGuards();
        $this->createHardenedCashMovementGuards();
        $this->createHardenedClosureInsertGuard();
    }

    public function down(): void
    {
        if (
            DB::table('cash_security_drop_requests')->exists()
            || DB::table('cash_movements')
                ->whereNotNull('cash_security_drop_request_id')
                ->exists()
        ) {
            throw new LogicException(
                'No puede revertirse el hardening mientras existan solicitudes '
                .'o retiros autorizados.'
            );
        }

        $this->dropCashMovementGuards();
        $this->dropRequestGuards();
        $this->dropClosureInsertGuard();

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique(
                'cash_movements_security_drop_request_unique'
            );
            $table->dropForeign(['cash_security_drop_request_id']);
            $table->dropColumn('cash_security_drop_request_id');
        });

        Schema::dropIfExists('cash_security_drop_requests');

        $this->createP4CCashMovementGuards();
        $this->createP4DClosureInsertGuard();
    }

    private function dropCashMovementGuards(): void
    {
        foreach ([
            'cash_movements_guard_insert',
            'cash_movements_guard_update',
            'cash_movements_guard_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function dropRequestGuards(): void
    {
        foreach ([
            'cash_security_drop_requests_guard_insert',
            'cash_security_drop_requests_guard_update',
            'cash_security_drop_requests_guard_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function dropClosureInsertGuard(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS cash_register_closures_guard_insert'
        );
    }

    private function createRequestGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteRequestGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlRequestGuards();

            return;
        }

        throw new LogicException(
            "El workflow de autorización no está implementado para {$driver}."
        );
    }

    private function createSqliteRequestGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_security_drop_requests_guard_insert
BEFORE INSERT ON cash_security_drop_requests
WHEN NEW.amount_minor < 1
    OR NEW.status <> 'pending'
    OR NEW.reason_code NOT IN (
        'excess_cash',
        'scheduled_drop',
        'supervisor_request',
        'other'
    )
    OR trim(NEW.request_idempotency_key) = ''
    OR length(NEW.fingerprint) <> 64
    OR NEW.approved_by_user_id IS NOT NULL
    OR NEW.executed_by_user_id IS NOT NULL
    OR NEW.resolved_by_user_id IS NOT NULL
    OR NEW.approval_idempotency_key IS NOT NULL
    OR NEW.approval_fingerprint IS NOT NULL
    OR NEW.approval_note IS NOT NULL
    OR NEW.execution_idempotency_key IS NOT NULL
    OR NEW.resolution_idempotency_key IS NOT NULL
    OR NEW.resolution_note IS NOT NULL
    OR NEW.approved_at IS NOT NULL
    OR NEW.executed_at IS NOT NULL
    OR NEW.resolved_at IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        JOIN cash_registers register_row
            ON register_row.id = session.cash_register_id
        JOIN financial_accounts origin
            ON origin.id = register_row.financial_account_id
        WHERE session.id = NEW.cash_register_session_id
            AND session.organization_id = NEW.organization_id
            AND session.status = 'open'
            AND session.cash_register_id = NEW.cash_register_id
            AND session.opened_by_user_id = NEW.requested_by_user_id
            AND session.currency_code = NEW.currency_code
            AND register_row.organization_id = NEW.organization_id
            AND register_row.active = 1
            AND register_row.financial_account_id =
                NEW.origin_financial_account_id
            AND origin.organization_id = NEW.organization_id
            AND origin.active = 1
            AND origin.type = 'cash_box'
            AND origin.currency_code = NEW.currency_code
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts destination
        WHERE destination.id = NEW.destination_financial_account_id
            AND destination.organization_id = NEW.organization_id
            AND destination.active = 1
            AND destination.type = 'cash_reserve'
            AND destination.currency_code = NEW.currency_code
            AND destination.id <> NEW.origin_financial_account_id
    )
    OR EXISTS (
        SELECT 1
        FROM cash_security_drop_requests active_request
        WHERE active_request.cash_register_session_id =
                NEW.cash_register_session_id
            AND active_request.status IN ('pending', 'approved')
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La solicitud de retiro de seguridad no es válida.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_security_drop_requests_guard_update
BEFORE UPDATE ON cash_security_drop_requests
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.cash_register_session_id <> OLD.cash_register_session_id
    OR NEW.cash_register_id <> OLD.cash_register_id
    OR NEW.origin_financial_account_id <> OLD.origin_financial_account_id
    OR NEW.destination_financial_account_id <>
        OLD.destination_financial_account_id
    OR NEW.requested_by_user_id <> OLD.requested_by_user_id
    OR NEW.amount_minor <> OLD.amount_minor
    OR NEW.currency_code <> OLD.currency_code
    OR NEW.reason_code <> OLD.reason_code
    OR COALESCE(NEW.note, '') <> COALESCE(OLD.note, '')
    OR NEW.request_idempotency_key <> OLD.request_idempotency_key
    OR NEW.fingerprint <> OLD.fingerprint
    OR NEW.requested_at <> OLD.requested_at
    OR OLD.status IN ('executed', 'rejected', 'cancelled', 'expired')
    OR (
        OLD.status = 'pending'
        AND NEW.status = 'approved'
        AND (
            NEW.approved_by_user_id IS NULL
            OR NEW.approved_by_user_id = NEW.requested_by_user_id
            OR NEW.approved_at IS NULL
            OR NEW.approval_idempotency_key IS NULL
            OR NEW.approval_fingerprint IS NULL
            OR NEW.executed_by_user_id IS NOT NULL
            OR NEW.execution_idempotency_key IS NOT NULL
            OR NEW.executed_at IS NOT NULL
            OR NEW.resolved_by_user_id IS NOT NULL
            OR NEW.resolution_idempotency_key IS NOT NULL
            OR NEW.resolution_note IS NOT NULL
            OR NEW.resolved_at IS NOT NULL
        )
    )
    OR (
        OLD.status = 'pending'
        AND NEW.status IN ('rejected', 'cancelled', 'expired')
        AND (
            NEW.approved_by_user_id IS NOT NULL
            OR NEW.approval_idempotency_key IS NOT NULL
            OR NEW.approval_fingerprint IS NOT NULL
            OR NEW.approval_note IS NOT NULL
            OR NEW.approved_at IS NOT NULL
            OR NEW.executed_by_user_id IS NOT NULL
            OR NEW.execution_idempotency_key IS NOT NULL
            OR NEW.executed_at IS NOT NULL
            OR NEW.resolved_by_user_id IS NULL
            OR NEW.resolution_idempotency_key IS NULL
            OR trim(COALESCE(NEW.resolution_note, '')) = ''
            OR NEW.resolved_at IS NULL
        )
    )
    OR (
        OLD.status = 'approved'
        AND NEW.status = 'executed'
        AND (
            NEW.approved_by_user_id IS NULL
            OR NEW.approved_by_user_id <> OLD.approved_by_user_id
            OR NEW.approved_at IS NULL
            OR NEW.approved_at <> OLD.approved_at
            OR NEW.approval_idempotency_key IS NULL
            OR NEW.approval_idempotency_key <>
                OLD.approval_idempotency_key
            OR NEW.approval_fingerprint IS NULL
            OR NEW.approval_fingerprint <> OLD.approval_fingerprint
            OR COALESCE(NEW.approval_note, '') <>
                COALESCE(OLD.approval_note, '')
            OR NEW.executed_by_user_id IS NULL
            OR NEW.executed_by_user_id <> NEW.requested_by_user_id
            OR NEW.executed_at IS NULL
            OR NEW.execution_idempotency_key IS NULL
            OR NEW.resolved_by_user_id IS NOT NULL
            OR NEW.resolution_idempotency_key IS NOT NULL
            OR NEW.resolution_note IS NOT NULL
            OR NEW.resolved_at IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM cash_movements movement
                WHERE movement.cash_security_drop_request_id = OLD.id
                    AND movement.organization_id = OLD.organization_id
                    AND movement.cash_register_session_id =
                        OLD.cash_register_session_id
                    AND movement.type = 'security_drop'
                    AND movement.direction = 'out'
                    AND movement.recorded_by_user_id =
                        NEW.executed_by_user_id
            )
        )
    )
    OR (
        OLD.status = 'approved'
        AND NEW.status IN ('cancelled', 'expired')
        AND (
            NEW.approved_by_user_id IS NULL
            OR NEW.approved_by_user_id <> OLD.approved_by_user_id
            OR NEW.approved_at IS NULL
            OR NEW.approved_at <> OLD.approved_at
            OR NEW.approval_idempotency_key IS NULL
            OR NEW.approval_idempotency_key <>
                OLD.approval_idempotency_key
            OR NEW.approval_fingerprint IS NULL
            OR NEW.approval_fingerprint <> OLD.approval_fingerprint
            OR COALESCE(NEW.approval_note, '') <>
                COALESCE(OLD.approval_note, '')
            OR NEW.executed_by_user_id IS NOT NULL
            OR NEW.execution_idempotency_key IS NOT NULL
            OR NEW.executed_at IS NOT NULL
            OR NEW.resolved_by_user_id IS NULL
            OR NEW.resolution_idempotency_key IS NULL
            OR trim(COALESCE(NEW.resolution_note, '')) = ''
            OR NEW.resolved_at IS NULL
        )
    )
    OR NOT (
        (OLD.status = 'pending' AND NEW.status = 'approved')
        OR (
            OLD.status = 'pending'
            AND NEW.status IN ('rejected', 'cancelled', 'expired')
        )
        OR (OLD.status = 'approved' AND NEW.status = 'executed')
        OR (
            OLD.status = 'approved'
            AND NEW.status IN ('cancelled', 'expired')
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La transición de solicitud de retiro no es válida.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_security_drop_requests_guard_delete
BEFORE DELETE ON cash_security_drop_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'La solicitud de retiro de seguridad no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlRequestGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_security_drop_requests_guard_insert
BEFORE INSERT ON cash_security_drop_requests
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.status <> 'pending'
        OR NEW.reason_code NOT IN (
            'excess_cash',
            'scheduled_drop',
            'supervisor_request',
            'other'
        )
        OR TRIM(NEW.request_idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.approved_by_user_id IS NOT NULL
        OR NEW.executed_by_user_id IS NOT NULL
        OR NEW.resolved_by_user_id IS NOT NULL
        OR NEW.approval_idempotency_key IS NOT NULL
        OR NEW.approval_fingerprint IS NOT NULL
        OR NEW.approval_note IS NOT NULL
        OR NEW.execution_idempotency_key IS NOT NULL
        OR NEW.resolution_idempotency_key IS NOT NULL
        OR NEW.resolution_note IS NOT NULL
        OR NEW.approved_at IS NOT NULL
        OR NEW.executed_at IS NOT NULL
        OR NEW.resolved_at IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            JOIN cash_registers register_row
                ON register_row.id = session.cash_register_id
            JOIN financial_accounts origin
                ON origin.id = register_row.financial_account_id
            WHERE session.id = NEW.cash_register_session_id
                AND session.organization_id = NEW.organization_id
                AND session.status = 'open'
                AND session.cash_register_id = NEW.cash_register_id
                AND session.opened_by_user_id = NEW.requested_by_user_id
                AND session.currency_code = NEW.currency_code
                AND register_row.organization_id = NEW.organization_id
                AND register_row.active = 1
                AND register_row.financial_account_id =
                    NEW.origin_financial_account_id
                AND origin.organization_id = NEW.organization_id
                AND origin.active = 1
                AND origin.type = 'cash_box'
                AND origin.currency_code = NEW.currency_code
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts destination
            WHERE destination.id = NEW.destination_financial_account_id
                AND destination.organization_id = NEW.organization_id
                AND destination.active = 1
                AND destination.type = 'cash_reserve'
                AND destination.currency_code = NEW.currency_code
                AND destination.id <> NEW.origin_financial_account_id
        )
        OR EXISTS (
            SELECT 1
            FROM cash_security_drop_requests active_request
            WHERE active_request.cash_register_session_id =
                    NEW.cash_register_session_id
                AND active_request.status IN ('pending', 'approved')
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La solicitud de retiro de seguridad no es valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_security_drop_requests_guard_update
BEFORE UPDATE ON cash_security_drop_requests
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.cash_register_session_id <> OLD.cash_register_session_id
        OR NEW.cash_register_id <> OLD.cash_register_id
        OR NEW.origin_financial_account_id <>
            OLD.origin_financial_account_id
        OR NEW.destination_financial_account_id <>
            OLD.destination_financial_account_id
        OR NEW.requested_by_user_id <> OLD.requested_by_user_id
        OR NEW.amount_minor <> OLD.amount_minor
        OR NEW.currency_code <> OLD.currency_code
        OR NEW.reason_code <> OLD.reason_code
        OR COALESCE(NEW.note, '') <> COALESCE(OLD.note, '')
        OR NEW.request_idempotency_key <> OLD.request_idempotency_key
        OR NEW.fingerprint <> OLD.fingerprint
        OR NEW.requested_at <> OLD.requested_at
        OR OLD.status IN ('executed', 'rejected', 'cancelled', 'expired')
        OR NOT (
            (
                OLD.status = 'pending'
                AND NEW.status = 'approved'
                AND NEW.approved_by_user_id IS NOT NULL
                AND NEW.approved_by_user_id <> NEW.requested_by_user_id
                AND NEW.approved_at IS NOT NULL
                AND NEW.approval_idempotency_key IS NOT NULL
                AND NEW.approval_fingerprint IS NOT NULL
                AND NEW.executed_by_user_id IS NULL
                AND NEW.execution_idempotency_key IS NULL
                AND NEW.executed_at IS NULL
                AND NEW.resolved_by_user_id IS NULL
                AND NEW.resolution_idempotency_key IS NULL
                AND NEW.resolution_note IS NULL
                AND NEW.resolved_at IS NULL
            )
            OR (
                OLD.status = 'pending'
                AND NEW.status IN ('rejected', 'cancelled', 'expired')
                AND NEW.approved_by_user_id IS NULL
                AND NEW.approval_idempotency_key IS NULL
                AND NEW.approval_fingerprint IS NULL
                AND NEW.approval_note IS NULL
                AND NEW.approved_at IS NULL
                AND NEW.executed_by_user_id IS NULL
                AND NEW.execution_idempotency_key IS NULL
                AND NEW.executed_at IS NULL
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolution_idempotency_key IS NOT NULL
                AND TRIM(COALESCE(NEW.resolution_note, '')) <> ''
                AND NEW.resolved_at IS NOT NULL
            )
            OR (
                OLD.status = 'approved'
                AND NEW.status = 'executed'
                AND NEW.approved_by_user_id = OLD.approved_by_user_id
                AND NEW.approved_at = OLD.approved_at
                AND NEW.approval_idempotency_key =
                    OLD.approval_idempotency_key
                AND NEW.approval_fingerprint = OLD.approval_fingerprint
                AND COALESCE(NEW.approval_note, '') =
                    COALESCE(OLD.approval_note, '')
                AND NEW.executed_by_user_id = NEW.requested_by_user_id
                AND NEW.executed_at IS NOT NULL
                AND NEW.execution_idempotency_key IS NOT NULL
                AND NEW.resolved_by_user_id IS NULL
                AND NEW.resolution_idempotency_key IS NULL
                AND NEW.resolution_note IS NULL
                AND NEW.resolved_at IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM cash_movements movement
                    WHERE movement.cash_security_drop_request_id = OLD.id
                        AND movement.organization_id = OLD.organization_id
                        AND movement.cash_register_session_id =
                            OLD.cash_register_session_id
                        AND movement.type = 'security_drop'
                        AND movement.direction = 'out'
                        AND movement.recorded_by_user_id =
                            NEW.executed_by_user_id
                )
            )
            OR (
                OLD.status = 'approved'
                AND NEW.status IN ('cancelled', 'expired')
                AND NEW.approved_by_user_id = OLD.approved_by_user_id
                AND NEW.approved_at = OLD.approved_at
                AND NEW.approval_idempotency_key =
                    OLD.approval_idempotency_key
                AND NEW.approval_fingerprint = OLD.approval_fingerprint
                AND COALESCE(NEW.approval_note, '') =
                    COALESCE(OLD.approval_note, '')
                AND NEW.executed_by_user_id IS NULL
                AND NEW.execution_idempotency_key IS NULL
                AND NEW.executed_at IS NULL
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolution_idempotency_key IS NOT NULL
                AND TRIM(COALESCE(NEW.resolution_note, '')) <> ''
                AND NEW.resolved_at IS NOT NULL
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La transicion de solicitud de retiro no es valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_security_drop_requests_guard_delete
BEFORE DELETE ON cash_security_drop_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'La solicitud de retiro de seguridad no puede eliminarse.';
END
SQL);
    }

    private function createHardenedCashMovementGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteHardenedCashMovementGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlHardenedCashMovementGuards();

            return;
        }

        throw new LogicException(
            "La integridad de CashMovement no está implementada para {$driver}."
        );
    }

    private function createSqliteHardenedCashMovementGuards(): void
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
            OR NEW.cash_security_drop_request_id IS NOT NULL
            OR NEW.reason_code IS NOT NULL
            OR NEW.note IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM commerce_payments payment
                WHERE payment.id = NEW.commerce_payment_id
                    AND payment.organization_id = NEW.organization_id
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
            OR NEW.cash_security_drop_request_id IS NULL
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
                    AND destination.organization_id = NEW.organization_id
                    AND destination.active = 1
                    AND destination.type = 'cash_reserve'
                    AND destination.currency_code = NEW.currency_code
                    AND destination.id <> NEW.financial_account_id
            )
            OR NOT EXISTS (
                SELECT 1
                FROM cash_security_drop_requests request_row
                WHERE request_row.id = NEW.cash_security_drop_request_id
                    AND request_row.organization_id = NEW.organization_id
                    AND request_row.status = 'approved'
                    AND request_row.cash_register_session_id =
                        NEW.cash_register_session_id
                    AND request_row.cash_register_id = NEW.cash_register_id
                    AND request_row.origin_financial_account_id =
                        NEW.financial_account_id
                    AND request_row.destination_financial_account_id =
                        NEW.destination_financial_account_id
                    AND request_row.requested_by_user_id =
                        NEW.recorded_by_user_id
                    AND request_row.approved_by_user_id IS NOT NULL
                    AND request_row.approved_by_user_id <>
                        request_row.requested_by_user_id
                    AND request_row.approval_fingerprint IS NOT NULL
                    AND request_row.amount_minor = NEW.amount_minor
                    AND request_row.currency_code = NEW.currency_code
                    AND request_row.reason_code = NEW.reason_code
                    AND COALESCE(request_row.note, '') =
                        COALESCE(NEW.note, '')
            )
            OR NEW.amount_minor > (
                SELECT session.opening_amount_minor + COALESCE(
                    SUM(
                        CASE
                            WHEN movement.direction = 'in'
                                THEN movement.amount_minor
                            ELSE -movement.amount_minor
                        END
                    ),
                    0
                )
                FROM cash_register_sessions session
                LEFT JOIN cash_movements movement
                    ON movement.cash_register_session_id = session.id
                WHERE session.id = NEW.cash_register_session_id
                GROUP BY session.id, session.opening_amount_minor
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo autorizado no es válido.'
    );
END
SQL);

        $this->createSqliteCashMovementImmutableGuards();
    }

    private function createMysqlHardenedCashMovementGuards(): void
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
                OR NEW.cash_security_drop_request_id IS NOT NULL
                OR NEW.reason_code IS NOT NULL
                OR NEW.note IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM commerce_payments payment
                    WHERE payment.id = NEW.commerce_payment_id
                        AND payment.organization_id = NEW.organization_id
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
                OR NEW.cash_security_drop_request_id IS NULL
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
                        AND destination.organization_id = NEW.organization_id
                        AND destination.active = 1
                        AND destination.type = 'cash_reserve'
                        AND destination.currency_code = NEW.currency_code
                        AND destination.id <> NEW.financial_account_id
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM cash_security_drop_requests request_row
                    WHERE request_row.id =
                            NEW.cash_security_drop_request_id
                        AND request_row.organization_id =
                            NEW.organization_id
                        AND request_row.status = 'approved'
                        AND request_row.cash_register_session_id =
                            NEW.cash_register_session_id
                        AND request_row.cash_register_id =
                            NEW.cash_register_id
                        AND request_row.origin_financial_account_id =
                            NEW.financial_account_id
                        AND request_row.destination_financial_account_id =
                            NEW.destination_financial_account_id
                        AND request_row.requested_by_user_id =
                            NEW.recorded_by_user_id
                        AND request_row.approved_by_user_id IS NOT NULL
                        AND request_row.approved_by_user_id <>
                            request_row.requested_by_user_id
                        AND request_row.approval_fingerprint IS NOT NULL
                        AND request_row.amount_minor = NEW.amount_minor
                        AND request_row.currency_code = NEW.currency_code
                        AND request_row.reason_code = NEW.reason_code
                        AND COALESCE(request_row.note, '') =
                            COALESCE(NEW.note, '')
                )
                OR NEW.amount_minor > (
                    SELECT session.opening_amount_minor + COALESCE(
                        SUM(
                            CASE
                                WHEN movement.direction = 'in'
                                    THEN movement.amount_minor
                                ELSE -movement.amount_minor
                            END
                        ),
                        0
                    )
                    FROM cash_register_sessions session
                    LEFT JOIN cash_movements movement
                        ON movement.cash_register_session_id = session.id
                    WHERE session.id = NEW.cash_register_session_id
                    GROUP BY session.id, session.opening_amount_minor
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de efectivo autorizado no es valido.';
    END IF;
END
SQL);

        $this->createMysqlCashMovementImmutableGuards();
    }

    private function createSqliteCashMovementImmutableGuards(): void
    {
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
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlCashMovementImmutableGuards(): void
    {
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
        SET MESSAGE_TEXT =
            'El movimiento de efectivo no puede eliminarse.';
END
SQL);
    }

    private function createHardenedClosureInsertGuard(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
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
    OR EXISTS (
        SELECT 1
        FROM cash_security_drop_requests active_request
        WHERE active_request.cash_register_session_id =
                NEW.cash_register_session_id
            AND active_request.status IN ('pending', 'approved')
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

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
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
        OR EXISTS (
            SELECT 1
            FROM cash_security_drop_requests active_request
            WHERE active_request.cash_register_session_id =
                    NEW.cash_register_session_id
                AND active_request.status IN ('pending', 'approved')
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
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El arqueo/cierre de caja P4D no es valido.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "La integridad de cierre no está implementada para {$driver}."
        );
    }

    private function createP4CCashMovementGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
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
                    AND payment.organization_id = NEW.organization_id
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
                    AND destination.organization_id = NEW.organization_id
                    AND destination.active = 1
                    AND destination.type = 'cash_reserve'
                    AND destination.currency_code = NEW.currency_code
                    AND destination.id <> NEW.financial_account_id
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El movimiento de efectivo P4C no es válido.');
END
SQL);
            $this->createSqliteCashMovementImmutableGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
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
                        AND payment.organization_id = NEW.organization_id
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
                        AND destination.organization_id = NEW.organization_id
                        AND destination.active = 1
                        AND destination.type = 'cash_reserve'
                        AND destination.currency_code = NEW.currency_code
                        AND destination.id <> NEW.financial_account_id
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de efectivo P4C no es valido.';
    END IF;
END
SQL);
            $this->createMysqlCashMovementImmutableGuards();

            return;
        }

        throw new LogicException(
            "La integridad P4C no está implementada para {$driver}."
        );
    }

    private function createP4DClosureInsertGuard(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
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
    SELECT RAISE(ABORT, 'El arqueo/cierre de caja P4D no es válido.');
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
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
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El arqueo/cierre de caja P4D no es valido.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "La integridad P4D no está implementada para {$driver}."
        );
    }
};
