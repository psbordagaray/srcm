<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXEC_INSERT = 'purchase_payment_executions_guard_insert';
    private const EXEC_UPDATE = 'purchase_payment_executions_guard_update';
    private const EXEC_DELETE = 'purchase_payment_executions_guard_delete';
    private const REQUEST_INSERT = 'purchase_payment_requests_guard_insert';
    private const REQUEST_UPDATE = 'purchase_payment_requests_guard_update';
    private const CASH_INSERT = 'cash_movements_guard_insert';
    private const CASH_UPDATE = 'cash_movements_guard_update';
    private const CASH_DELETE = 'cash_movements_guard_delete';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->recoverInterruptedSqliteCashMovementRebuild();
        }

        if (! Schema::hasTable('purchase_payment_executions')) {
            Schema::create(
                'purchase_payment_executions',
                function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('organization_id')
                        ->constrained('organizations')
                        ->restrictOnDelete();
                    $table->uuid('public_id')->unique();
                    $table->foreignId('purchase_payment_request_id')
                        ->unique()
                        ->constrained('purchase_payment_requests')
                        ->restrictOnDelete();
                    $table->foreignId('purchase_obligation_id')
                        ->constrained('purchase_obligations')
                        ->restrictOnDelete();
                    $table->foreignId('origin_financial_account_id')
                        ->constrained('financial_accounts')
                        ->restrictOnDelete();
                    $table->foreignId('beneficiary_business_party_id')
                        ->constrained('business_parties')
                        ->restrictOnDelete();
                    $table->foreignId('cash_register_session_id')
                        ->constrained('cash_register_sessions')
                        ->restrictOnDelete();
                    $table->foreignId('cash_register_id')
                        ->constrained('cash_registers')
                        ->restrictOnDelete();
                    $table->foreignId('executed_by_user_id')
                        ->constrained('users')
                        ->restrictOnDelete();
                    $table->unsignedBigInteger('amount_minor');
                    $table->char('currency_code', 3);
                    $table->string('execution_reference', 180)->nullable();
                    $table->text('execution_note')->nullable();
                    $table->string('idempotency_key', 180);
                    $table->char('fingerprint', 64);
                    $table->timestamp('executed_at');
                    $table->timestamp('created_at');

                    $table->unique(
                        ['organization_id', 'idempotency_key'],
                        'purchase_pay_exec_org_idem_unique'
                    );
                    $table->index(
                        [
                            'organization_id',
                            'purchase_obligation_id',
                            'executed_at',
                        ],
                        'purchase_pay_exec_obligation_idx'
                    );
                }
            );
        } else {
            $this->assertRecoverableExecutionTable();
        }

        if ($driver === 'sqlite') {
            $this->ensureSqliteCashMovementLink();
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (! Schema::hasColumn(
                'cash_movements',
                'purchase_payment_execution_id'
            )) {
                Schema::table(
                    'cash_movements',
                    function (Blueprint $table): void {
                        $table->foreignId(
                            'purchase_payment_execution_id'
                        )
                            ->nullable()
                            ->unique()
                            ->after(
                                'cash_security_drop_request_id'
                            )
                            ->constrained(
                                'purchase_payment_executions'
                            )
                            ->restrictOnDelete();
                    }
                );
            }
        } else {
            throw new LogicException(
                'La integridad P4F.3 no está implementada para '.$driver.'.'
            );
        }

        $this->rebuildGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P4F.3 conserva hechos monetarios append-only y no admite rollback automático.'
        );
    }


    private function recoverInterruptedSqliteCashMovementRebuild(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $hasCash = Schema::hasTable('cash_movements');
        $hasTemp = Schema::hasTable('__temp__cash_movements');

        if ($hasCash && $hasTemp) {
            throw new LogicException(
                'SQLite conserva cash_movements y __temp__cash_movements simultáneamente; se requiere diagnóstico manual.'
            );
        }

        if ($hasCash) {
            return;
        }

        if (! $hasTemp) {
            throw new LogicException(
                'SQLite no posee cash_movements ni su tabla temporal recuperable.'
            );
        }

        if (! Schema::hasColumn(
            '__temp__cash_movements',
            'purchase_payment_execution_id'
        )) {
            throw new LogicException(
                'La tabla temporal de cash_movements no contiene el vínculo P4F.3 esperado.'
            );
        }

        $rowsBefore = (int) DB::table(
            '__temp__cash_movements'
        )->count();

        $dependencies = DB::select(<<<'SQL'
SELECT name, sql
FROM sqlite_master
WHERE type = 'trigger'
  AND sql IS NOT NULL
  AND tbl_name <> '__temp__cash_movements'
  AND lower(sql) LIKE '%cash_movements%'
ORDER BY name
SQL);

        DB::transaction(function () use (
            $dependencies,
            $rowsBefore
        ): void {
            foreach ($dependencies as $trigger) {
                DB::unprepared(
                    'DROP TRIGGER IF EXISTS '
                    .$this->quoteSqliteIdentifier(
                        (string) $trigger->name
                    )
                );
            }

            DB::statement(
                'ALTER TABLE "__temp__cash_movements" '
                .'RENAME TO "cash_movements"'
            );

            foreach ($dependencies as $trigger) {
                if (
                    ! is_string($trigger->sql)
                    || trim($trigger->sql) === ''
                ) {
                    throw new LogicException(
                        'No pudo preservarse la definición de un trigger dependiente de cash_movements.'
                    );
                }

                DB::unprepared($trigger->sql);
            }

            $rowsAfter = (int) DB::table(
                'cash_movements'
            )->count();

            if ($rowsAfter !== $rowsBefore) {
                throw new LogicException(
                    'La recuperación de cash_movements alteró la cantidad de hechos.'
                );
            }
        }, 3);
    }

    private function assertRecoverableExecutionTable(): void
    {
        foreach ([
            'organization_id',
            'public_id',
            'purchase_payment_request_id',
            'purchase_obligation_id',
            'origin_financial_account_id',
            'beneficiary_business_party_id',
            'cash_register_session_id',
            'cash_register_id',
            'executed_by_user_id',
            'amount_minor',
            'currency_code',
            'idempotency_key',
            'fingerprint',
            'executed_at',
            'created_at',
        ] as $column) {
            if (! Schema::hasColumn(
                'purchase_payment_executions',
                $column
            )) {
                throw new LogicException(
                    'La tabla purchase_payment_executions parcial no conserva la forma P4F.3 esperada.'
                );
            }
        }

        if (
            DB::table('purchase_payment_executions')
                ->exists()
        ) {
            throw new LogicException(
                'Existe una ejecución P4F.3 sin registro de migración; no se continuará automáticamente.'
            );
        }
    }

    private function ensureSqliteCashMovementLink(): void
    {
        if (! Schema::hasTable('cash_movements')) {
            throw new LogicException(
                'cash_movements debe existir antes de completar P4F.3.'
            );
        }

        if (! Schema::hasColumn(
            'cash_movements',
            'purchase_payment_execution_id'
        )) {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "purchase_payment_execution_id"
INTEGER NULL
REFERENCES "purchase_payment_executions" ("id")
ON DELETE RESTRICT
SQL);
        }

        $this->ensureSqliteCashMovementIndexes();
        $this->assertSqliteExecutionForeignKey();
    }

    private function ensureSqliteCashMovementIndexes(): void
    {
        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS "cash_movements_public_id_unique" ON "cash_movements" ("public_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS "cash_movements_commerce_payment_id_unique" ON "cash_movements" ("commerce_payment_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS "cash_movements_org_idem_unique" ON "cash_movements" ("organization_id", "idempotency_key")',
            'CREATE INDEX IF NOT EXISTS "cash_movements_session_occurred_index" ON "cash_movements" ("cash_register_session_id", "occurred_at")',
            'CREATE INDEX IF NOT EXISTS "cash_movements_org_occurred_index" ON "cash_movements" ("organization_id", "occurred_at")',
            'CREATE INDEX IF NOT EXISTS "cash_movements_destination_occurred_index" ON "cash_movements" ("destination_financial_account_id", "occurred_at")',
            'CREATE UNIQUE INDEX IF NOT EXISTS "cash_movements_security_drop_request_unique" ON "cash_movements" ("cash_security_drop_request_id")',
            'CREATE UNIQUE INDEX IF NOT EXISTS "cash_movements_purchase_payment_execution_id_unique" ON "cash_movements" ("purchase_payment_execution_id")',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    private function assertSqliteExecutionForeignKey(): void
    {
        $foreignKeys = DB::select(
            'PRAGMA foreign_key_list("cash_movements")'
        );

        foreach ($foreignKeys as $foreignKey) {
            if (
                (string) $foreignKey->from
                    === 'purchase_payment_execution_id'
                && (string) $foreignKey->table
                    === 'purchase_payment_executions'
                && (string) $foreignKey->to === 'id'
            ) {
                return;
            }
        }

        throw new LogicException(
            'cash_movements no conserva la FK P4F.3 hacia purchase_payment_executions.'
        );
    }

    private function quoteSqliteIdentifier(
        string $identifier
    ): string {
        return '"'
            .str_replace('"', '""', $identifier)
            .'"';
    }

    private function rebuildGuards(): void
    {
        foreach ([
            self::EXEC_INSERT,
            self::EXEC_UPDATE,
            self::EXEC_DELETE,
            self::REQUEST_INSERT,
            self::REQUEST_UPDATE,
            self::CASH_INSERT,
            self::CASH_UPDATE,
            self::CASH_DELETE,
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

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
            'La integridad P4F.3 no está implementada para '.$driver.'.'
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_executions_guard_insert
BEFORE INSERT ON purchase_payment_executions
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.executed_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_payment_requests request
        WHERE request.id = NEW.purchase_payment_request_id
          AND request.organization_id = NEW.organization_id
          AND request.purchase_obligation_id = NEW.purchase_obligation_id
          AND request.origin_financial_account_id = NEW.origin_financial_account_id
          AND request.beneficiary_business_party_id =
              NEW.beneficiary_business_party_id
          AND request.amount_minor = NEW.amount_minor
          AND request.currency_code = NEW.currency_code
          AND request.status = 'approved'
          AND request.approved_by_user_id IS NOT NULL
          AND request.approved_by_user_id <> NEW.executed_by_user_id
          AND request.approval_fingerprint IS NOT NULL
          AND LENGTH(request.approval_fingerprint) = 64
    )
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_obligations obligation
        WHERE obligation.id = NEW.purchase_obligation_id
          AND obligation.organization_id = NEW.organization_id
          AND obligation.beneficiary_business_party_id =
              NEW.beneficiary_business_party_id
          AND obligation.currency_code = NEW.currency_code
          AND NEW.amount_minor <=
              obligation.amount_minor - COALESCE(
                  (
                      SELECT SUM(previous.amount_minor)
                      FROM purchase_payment_executions previous
                      WHERE previous.organization_id = NEW.organization_id
                        AND previous.purchase_obligation_id =
                            NEW.purchase_obligation_id
                  ),
                  0
              )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id = NEW.organization_id
          AND membership.user_id = NEW.executed_by_user_id
          AND membership.active = 1
          AND membership.role IN ('admin', 'operator')
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.origin_financial_account_id
          AND account.organization_id = NEW.organization_id
          AND account.active = 1
          AND account.type = 'cash_box'
          AND account.currency_code = NEW.currency_code
    )
    OR NOT EXISTS (
        SELECT 1
        FROM cash_register_sessions session
        JOIN cash_registers register_row
          ON register_row.id = session.cash_register_id
        WHERE session.id = NEW.cash_register_session_id
          AND session.organization_id = NEW.organization_id
          AND session.status = 'open'
          AND session.opened_by_user_id = NEW.executed_by_user_id
          AND session.currency_code = NEW.currency_code
          AND session.cash_register_id = NEW.cash_register_id
          AND register_row.organization_id = NEW.organization_id
          AND register_row.active = 1
          AND register_row.financial_account_id =
              NEW.origin_financial_account_id
    )
    OR NEW.amount_minor > (
        SELECT
            session.opening_amount_minor
            + COALESCE(
                (
                    SELECT SUM(
                        CASE
                            WHEN movement.direction = 'in'
                                THEN movement.amount_minor
                            ELSE -movement.amount_minor
                        END
                    )
                    FROM cash_movements movement
                    WHERE movement.cash_register_session_id = session.id
                ),
                0
            )
        FROM cash_register_sessions session
        WHERE session.id = NEW.cash_register_session_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La ejecución de pago P4F.3 no conserva autorización, saldo, caja o autoridad válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_executions_guard_update
BEFORE UPDATE ON purchase_payment_executions
BEGIN
    SELECT RAISE(
        ABORT,
        'Una ejecución de pago confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_executions_guard_delete
BEFORE DELETE ON purchase_payment_executions
BEGIN
    SELECT RAISE(
        ABORT,
        'Una ejecución de pago confirmada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_insert
BEFORE INSERT ON purchase_payment_requests
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR NEW.status <> 'pending'
    OR LENGTH(NEW.fingerprint) <> 64
    OR LENGTH(TRIM(NEW.request_idempotency_key)) = 0
    OR NEW.requested_at IS NULL
    OR NEW.created_at IS NULL
    OR NEW.approved_by_user_id IS NOT NULL
    OR NEW.resolved_by_user_id IS NOT NULL
    OR NEW.approval_idempotency_key IS NOT NULL
    OR NEW.approval_fingerprint IS NOT NULL
    OR NEW.approval_note IS NOT NULL
    OR NEW.resolution_idempotency_key IS NOT NULL
    OR NEW.resolution_note IS NOT NULL
    OR NEW.approved_at IS NOT NULL
    OR NEW.resolved_at IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_obligations obligation
        WHERE obligation.id = NEW.purchase_obligation_id
          AND obligation.organization_id = NEW.organization_id
          AND obligation.beneficiary_business_party_id =
              NEW.beneficiary_business_party_id
          AND obligation.currency_code = NEW.currency_code
          AND NEW.amount_minor <=
              obligation.amount_minor - COALESCE(
                  (
                      SELECT SUM(execution.amount_minor)
                      FROM purchase_payment_executions execution
                      WHERE execution.organization_id = NEW.organization_id
                        AND execution.purchase_obligation_id =
                            NEW.purchase_obligation_id
                  ),
                  0
              )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.origin_financial_account_id
          AND account.organization_id = NEW.organization_id
          AND account.active = 1
          AND account.currency_code = NEW.currency_code
    )
    OR NOT EXISTS (
        SELECT 1
        FROM business_parties party
        WHERE party.id = NEW.beneficiary_business_party_id
          AND party.organization_id = NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id = NEW.organization_id
          AND membership.user_id = NEW.requested_by_user_id
          AND membership.active = 1
          AND membership.role IN ('admin', 'operator')
    )
    OR EXISTS (
        SELECT 1
        FROM purchase_payment_requests active_request
        WHERE active_request.organization_id = NEW.organization_id
          AND active_request.purchase_obligation_id =
              NEW.purchase_obligation_id
          AND active_request.status IN ('pending', 'approved')
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La solicitud de pago no conserva obligación, saldo, origen, importe, autoridad o estado válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_update
BEFORE UPDATE ON purchase_payment_requests
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.purchase_obligation_id <> OLD.purchase_obligation_id
    OR NEW.origin_financial_account_id <> OLD.origin_financial_account_id
    OR NEW.beneficiary_business_party_id <>
        OLD.beneficiary_business_party_id
    OR NEW.requested_by_user_id <> OLD.requested_by_user_id
    OR NEW.amount_minor <> OLD.amount_minor
    OR NEW.currency_code <> OLD.currency_code
    OR COALESCE(NEW.request_note, '') <>
        COALESCE(OLD.request_note, '')
    OR NEW.request_idempotency_key <> OLD.request_idempotency_key
    OR NEW.fingerprint <> OLD.fingerprint
    OR NEW.requested_at <> OLD.requested_at
    OR NEW.created_at <> OLD.created_at
    OR OLD.status IN ('rejected', 'cancelled', 'expired', 'executed')
    OR NOT (
        (
            OLD.status = 'pending'
            AND NEW.status = 'approved'
            AND NEW.approved_by_user_id IS NOT NULL
            AND NEW.approved_by_user_id <> NEW.requested_by_user_id
            AND NEW.approval_idempotency_key IS NOT NULL
            AND LENGTH(TRIM(NEW.approval_idempotency_key)) > 0
            AND NEW.approval_fingerprint IS NOT NULL
            AND LENGTH(NEW.approval_fingerprint) = 64
            AND NEW.approved_at IS NOT NULL
            AND NEW.resolved_by_user_id IS NULL
            AND NEW.resolution_idempotency_key IS NULL
            AND NEW.resolution_note IS NULL
            AND NEW.resolved_at IS NULL
            AND EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id = NEW.organization_id
                  AND membership.user_id = NEW.approved_by_user_id
                  AND membership.active = 1
                  AND membership.role = 'admin'
            )
            AND EXISTS (
                SELECT 1
                FROM financial_accounts account
                WHERE account.id = NEW.origin_financial_account_id
                  AND account.organization_id = NEW.organization_id
                  AND account.active = 1
                  AND account.currency_code = NEW.currency_code
            )
        )
        OR (
            OLD.status = 'pending'
            AND NEW.status IN ('rejected', 'cancelled', 'expired')
            AND NEW.approved_by_user_id IS NULL
            AND NEW.approval_idempotency_key IS NULL
            AND NEW.approval_fingerprint IS NULL
            AND NEW.approval_note IS NULL
            AND NEW.approved_at IS NULL
            AND NEW.resolved_by_user_id IS NOT NULL
            AND NEW.resolution_idempotency_key IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_idempotency_key)) > 0
            AND NEW.resolution_note IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_note)) > 0
            AND NEW.resolved_at IS NOT NULL
            AND (
                (
                    NEW.status = 'cancelled'
                    AND NEW.resolved_by_user_id = NEW.requested_by_user_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id = NEW.organization_id
                      AND membership.user_id = NEW.resolved_by_user_id
                      AND membership.active = 1
                      AND membership.role = 'admin'
                      AND (
                          NEW.status = 'cancelled'
                          OR NEW.resolved_by_user_id <>
                              NEW.requested_by_user_id
                      )
                )
            )
        )
        OR (
            OLD.status = 'approved'
            AND NEW.status IN ('cancelled', 'expired')
            AND NEW.approved_by_user_id = OLD.approved_by_user_id
            AND NEW.approval_idempotency_key =
                OLD.approval_idempotency_key
            AND NEW.approval_fingerprint = OLD.approval_fingerprint
            AND COALESCE(NEW.approval_note, '') =
                COALESCE(OLD.approval_note, '')
            AND NEW.approved_at = OLD.approved_at
            AND NEW.resolved_by_user_id IS NOT NULL
            AND NEW.resolution_idempotency_key IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_idempotency_key)) > 0
            AND NEW.resolution_note IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_note)) > 0
            AND NEW.resolved_at IS NOT NULL
            AND (
                (
                    NEW.status = 'cancelled'
                    AND NEW.resolved_by_user_id = NEW.requested_by_user_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id = NEW.organization_id
                      AND membership.user_id = NEW.resolved_by_user_id
                      AND membership.active = 1
                      AND membership.role = 'admin'
                      AND (
                          NEW.status = 'cancelled'
                          OR NEW.resolved_by_user_id <>
                              NEW.requested_by_user_id
                      )
                )
            )
        )
        OR (
            OLD.status = 'approved'
            AND NEW.status = 'executed'
            AND NEW.approved_by_user_id = OLD.approved_by_user_id
            AND NEW.approval_idempotency_key =
                OLD.approval_idempotency_key
            AND NEW.approval_fingerprint = OLD.approval_fingerprint
            AND COALESCE(NEW.approval_note, '') =
                COALESCE(OLD.approval_note, '')
            AND NEW.approved_at = OLD.approved_at
            AND NEW.resolved_by_user_id IS NULL
            AND NEW.resolution_idempotency_key IS NULL
            AND NEW.resolution_note IS NULL
            AND NEW.resolved_at IS NULL
            AND EXISTS (
                SELECT 1
                FROM purchase_payment_executions execution
                JOIN cash_movements movement
                  ON movement.purchase_payment_execution_id = execution.id
                WHERE execution.purchase_payment_request_id = OLD.id
                  AND execution.organization_id = OLD.organization_id
                  AND execution.purchase_obligation_id =
                      OLD.purchase_obligation_id
                  AND execution.origin_financial_account_id =
                      OLD.origin_financial_account_id
                  AND execution.beneficiary_business_party_id =
                      OLD.beneficiary_business_party_id
                  AND execution.amount_minor = OLD.amount_minor
                  AND execution.currency_code = OLD.currency_code
                  AND execution.executed_by_user_id <>
                      OLD.approved_by_user_id
                  AND movement.organization_id = OLD.organization_id
                  AND movement.direction = 'out'
                  AND movement.type = 'purchase_payment'
                  AND movement.amount_minor = OLD.amount_minor
                  AND movement.currency_code = OLD.currency_code
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Transición o mutación inválida de solicitud de pago.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_insert
BEFORE INSERT ON cash_movements
WHEN NEW.amount_minor < 1
    OR NEW.type NOT IN ('sale_payment', 'security_drop', 'purchase_payment')
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
            OR NEW.purchase_payment_execution_id IS NOT NULL
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
            OR NEW.purchase_payment_execution_id IS NOT NULL
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
                WHERE destination.id = NEW.destination_financial_account_id
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
        )
    )
    OR (
        NEW.type = 'purchase_payment'
        AND (
            NEW.direction <> 'out'
            OR NEW.commerce_payment_id IS NOT NULL
            OR NEW.destination_financial_account_id IS NOT NULL
            OR NEW.cash_security_drop_request_id IS NOT NULL
            OR NEW.purchase_payment_execution_id IS NULL
            OR NEW.reason_code IS NOT NULL
            OR NEW.note IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM purchase_payment_executions execution
                WHERE execution.id = NEW.purchase_payment_execution_id
                    AND execution.organization_id = NEW.organization_id
                    AND execution.cash_register_session_id =
                        NEW.cash_register_session_id
                    AND execution.cash_register_id = NEW.cash_register_id
                    AND execution.origin_financial_account_id =
                        NEW.financial_account_id
                    AND execution.amount_minor = NEW.amount_minor
                    AND execution.currency_code = NEW.currency_code
                    AND execution.executed_by_user_id =
                        NEW.recorded_by_user_id
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de efectivo P4F.3 no es válido.'
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
CREATE TRIGGER purchase_payment_executions_guard_insert
BEFORE INSERT ON purchase_payment_executions
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.executed_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_payment_requests request
            WHERE request.id = NEW.purchase_payment_request_id
              AND request.organization_id = NEW.organization_id
              AND request.purchase_obligation_id =
                  NEW.purchase_obligation_id
              AND request.origin_financial_account_id =
                  NEW.origin_financial_account_id
              AND request.beneficiary_business_party_id =
                  NEW.beneficiary_business_party_id
              AND request.amount_minor = NEW.amount_minor
              AND request.currency_code = NEW.currency_code
              AND request.status = 'approved'
              AND request.approved_by_user_id IS NOT NULL
              AND request.approved_by_user_id <> NEW.executed_by_user_id
              AND request.approval_fingerprint IS NOT NULL
              AND CHAR_LENGTH(request.approval_fingerprint) = 64
        )
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_obligations obligation
            WHERE obligation.id = NEW.purchase_obligation_id
              AND obligation.organization_id = NEW.organization_id
              AND obligation.beneficiary_business_party_id =
                  NEW.beneficiary_business_party_id
              AND obligation.currency_code = NEW.currency_code
              AND NEW.amount_minor <=
                  obligation.amount_minor - COALESCE(
                      (
                          SELECT SUM(previous.amount_minor)
                          FROM purchase_payment_executions previous
                          WHERE previous.organization_id =
                              NEW.organization_id
                            AND previous.purchase_obligation_id =
                                NEW.purchase_obligation_id
                      ),
                      0
                  )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id = NEW.organization_id
              AND membership.user_id = NEW.executed_by_user_id
              AND membership.active = 1
              AND membership.role IN ('admin', 'operator')
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id = NEW.origin_financial_account_id
              AND account.organization_id = NEW.organization_id
              AND account.active = 1
              AND account.type = 'cash_box'
              AND account.currency_code = NEW.currency_code
        )
        OR NOT EXISTS (
            SELECT 1
            FROM cash_register_sessions session
            JOIN cash_registers register_row
              ON register_row.id = session.cash_register_id
            WHERE session.id = NEW.cash_register_session_id
              AND session.organization_id = NEW.organization_id
              AND session.status = 'open'
              AND session.opened_by_user_id = NEW.executed_by_user_id
              AND session.currency_code = NEW.currency_code
              AND session.cash_register_id = NEW.cash_register_id
              AND register_row.organization_id = NEW.organization_id
              AND register_row.active = 1
              AND register_row.financial_account_id =
                  NEW.origin_financial_account_id
        )
        OR NEW.amount_minor > (
            SELECT
                session.opening_amount_minor
                + COALESCE(
                    (
                        SELECT SUM(
                            CASE
                                WHEN movement.direction = 'in'
                                    THEN movement.amount_minor
                                ELSE -movement.amount_minor
                            END
                        )
                        FROM cash_movements movement
                        WHERE movement.cash_register_session_id =
                            session.id
                    ),
                    0
                )
            FROM cash_register_sessions session
            WHERE session.id = NEW.cash_register_session_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La ejecucion de pago P4F.3 no conserva autorizacion, saldo, caja o autoridad validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_executions_guard_update
BEFORE UPDATE ON purchase_payment_executions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una ejecucion de pago confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_executions_guard_delete
BEFORE DELETE ON purchase_payment_executions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una ejecucion de pago confirmada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_insert
BEFORE INSERT ON purchase_payment_requests
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR NEW.status <> 'pending'
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR CHAR_LENGTH(TRIM(NEW.request_idempotency_key)) = 0
        OR NEW.requested_at IS NULL
        OR NEW.created_at IS NULL
        OR NEW.approved_by_user_id IS NOT NULL
        OR NEW.resolved_by_user_id IS NOT NULL
        OR NEW.approval_idempotency_key IS NOT NULL
        OR NEW.approval_fingerprint IS NOT NULL
        OR NEW.approval_note IS NOT NULL
        OR NEW.resolution_idempotency_key IS NOT NULL
        OR NEW.resolution_note IS NOT NULL
        OR NEW.approved_at IS NOT NULL
        OR NEW.resolved_at IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_obligations obligation
            WHERE obligation.id = NEW.purchase_obligation_id
              AND obligation.organization_id = NEW.organization_id
              AND obligation.beneficiary_business_party_id =
                  NEW.beneficiary_business_party_id
              AND obligation.currency_code = NEW.currency_code
              AND NEW.amount_minor <=
                  obligation.amount_minor - COALESCE(
                      (
                          SELECT SUM(execution.amount_minor)
                          FROM purchase_payment_executions execution
                          WHERE execution.organization_id =
                              NEW.organization_id
                            AND execution.purchase_obligation_id =
                                NEW.purchase_obligation_id
                      ),
                      0
                  )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id = NEW.origin_financial_account_id
              AND account.organization_id = NEW.organization_id
              AND account.active = 1
              AND account.currency_code = NEW.currency_code
        )
        OR NOT EXISTS (
            SELECT 1
            FROM business_parties party
            WHERE party.id = NEW.beneficiary_business_party_id
              AND party.organization_id = NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id = NEW.organization_id
              AND membership.user_id = NEW.requested_by_user_id
              AND membership.active = 1
              AND membership.role IN ('admin', 'operator')
        )
        OR EXISTS (
            SELECT 1
            FROM purchase_payment_requests active_request
            WHERE active_request.organization_id = NEW.organization_id
              AND active_request.purchase_obligation_id =
                  NEW.purchase_obligation_id
              AND active_request.status IN ('pending', 'approved')
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La solicitud de pago no conserva obligacion, saldo, origen, importe, autoridad o estado validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_requests_guard_update
BEFORE UPDATE ON purchase_payment_requests
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.purchase_obligation_id <> OLD.purchase_obligation_id
        OR NEW.origin_financial_account_id <>
            OLD.origin_financial_account_id
        OR NEW.beneficiary_business_party_id <>
            OLD.beneficiary_business_party_id
        OR NEW.requested_by_user_id <> OLD.requested_by_user_id
        OR NEW.amount_minor <> OLD.amount_minor
        OR NEW.currency_code <> OLD.currency_code
        OR COALESCE(NEW.request_note, '') <>
            COALESCE(OLD.request_note, '')
        OR NEW.request_idempotency_key <> OLD.request_idempotency_key
        OR NEW.fingerprint <> OLD.fingerprint
        OR NEW.requested_at <> OLD.requested_at
        OR NEW.created_at <> OLD.created_at
        OR OLD.status IN ('rejected', 'cancelled', 'expired', 'executed')
        OR NOT (
            (
                OLD.status = 'pending'
                AND NEW.status = 'approved'
                AND NEW.approved_by_user_id IS NOT NULL
                AND NEW.approved_by_user_id <> NEW.requested_by_user_id
                AND NEW.approval_idempotency_key IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.approval_idempotency_key)
                ) > 0
                AND NEW.approval_fingerprint IS NOT NULL
                AND CHAR_LENGTH(NEW.approval_fingerprint) = 64
                AND NEW.approved_at IS NOT NULL
                AND NEW.resolved_by_user_id IS NULL
                AND NEW.resolution_idempotency_key IS NULL
                AND NEW.resolution_note IS NULL
                AND NEW.resolved_at IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                        NEW.organization_id
                      AND membership.user_id = NEW.approved_by_user_id
                      AND membership.active = 1
                      AND membership.role = 'admin'
                )
                AND EXISTS (
                    SELECT 1
                    FROM financial_accounts account
                    WHERE account.id = NEW.origin_financial_account_id
                      AND account.organization_id = NEW.organization_id
                      AND account.active = 1
                      AND account.currency_code = NEW.currency_code
                )
            )
            OR (
                OLD.status = 'pending'
                AND NEW.status IN ('rejected', 'cancelled', 'expired')
                AND NEW.approved_by_user_id IS NULL
                AND NEW.approval_idempotency_key IS NULL
                AND NEW.approval_fingerprint IS NULL
                AND NEW.approval_note IS NULL
                AND NEW.approved_at IS NULL
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolution_idempotency_key IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.resolution_idempotency_key)
                ) > 0
                AND NEW.resolution_note IS NOT NULL
                AND CHAR_LENGTH(TRIM(NEW.resolution_note)) > 0
                AND NEW.resolved_at IS NOT NULL
                AND (
                    (
                        NEW.status = 'cancelled'
                        AND NEW.resolved_by_user_id =
                            NEW.requested_by_user_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM organization_memberships membership
                        WHERE membership.organization_id =
                            NEW.organization_id
                          AND membership.user_id =
                            NEW.resolved_by_user_id
                          AND membership.active = 1
                          AND membership.role = 'admin'
                          AND (
                              NEW.status = 'cancelled'
                              OR NEW.resolved_by_user_id <>
                                  NEW.requested_by_user_id
                          )
                    )
                )
            )
            OR (
                OLD.status = 'approved'
                AND NEW.status IN ('cancelled', 'expired')
                AND NEW.approved_by_user_id = OLD.approved_by_user_id
                AND NEW.approval_idempotency_key =
                    OLD.approval_idempotency_key
                AND NEW.approval_fingerprint = OLD.approval_fingerprint
                AND COALESCE(NEW.approval_note, '') =
                    COALESCE(OLD.approval_note, '')
                AND NEW.approved_at = OLD.approved_at
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolution_idempotency_key IS NOT NULL
                AND CHAR_LENGTH(
                    TRIM(NEW.resolution_idempotency_key)
                ) > 0
                AND NEW.resolution_note IS NOT NULL
                AND CHAR_LENGTH(TRIM(NEW.resolution_note)) > 0
                AND NEW.resolved_at IS NOT NULL
                AND (
                    (
                        NEW.status = 'cancelled'
                        AND NEW.resolved_by_user_id =
                            NEW.requested_by_user_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM organization_memberships membership
                        WHERE membership.organization_id =
                            NEW.organization_id
                          AND membership.user_id =
                            NEW.resolved_by_user_id
                          AND membership.active = 1
                          AND membership.role = 'admin'
                          AND (
                              NEW.status = 'cancelled'
                              OR NEW.resolved_by_user_id <>
                                  NEW.requested_by_user_id
                          )
                    )
                )
            )
            OR (
                OLD.status = 'approved'
                AND NEW.status = 'executed'
                AND NEW.approved_by_user_id = OLD.approved_by_user_id
                AND NEW.approval_idempotency_key =
                    OLD.approval_idempotency_key
                AND NEW.approval_fingerprint = OLD.approval_fingerprint
                AND COALESCE(NEW.approval_note, '') =
                    COALESCE(OLD.approval_note, '')
                AND NEW.approved_at = OLD.approved_at
                AND NEW.resolved_by_user_id IS NULL
                AND NEW.resolution_idempotency_key IS NULL
                AND NEW.resolution_note IS NULL
                AND NEW.resolved_at IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM purchase_payment_executions execution
                    JOIN cash_movements movement
                      ON movement.purchase_payment_execution_id =
                          execution.id
                    WHERE execution.purchase_payment_request_id = OLD.id
                      AND execution.organization_id = OLD.organization_id
                      AND execution.purchase_obligation_id =
                          OLD.purchase_obligation_id
                      AND execution.origin_financial_account_id =
                          OLD.origin_financial_account_id
                      AND execution.beneficiary_business_party_id =
                          OLD.beneficiary_business_party_id
                      AND execution.amount_minor = OLD.amount_minor
                      AND execution.currency_code = OLD.currency_code
                      AND execution.executed_by_user_id <>
                          OLD.approved_by_user_id
                      AND movement.organization_id = OLD.organization_id
                      AND movement.direction = 'out'
                      AND movement.type = 'purchase_payment'
                      AND movement.amount_minor = OLD.amount_minor
                      AND movement.currency_code = OLD.currency_code
                )
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Transicion o mutacion invalida de solicitud de pago.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.type NOT IN (
            'sale_payment',
            'security_drop',
            'purchase_payment'
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
                OR NEW.purchase_payment_execution_id IS NOT NULL
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
                OR NEW.purchase_payment_execution_id IS NOT NULL
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
            )
        )
        OR (
            NEW.type = 'purchase_payment'
            AND (
                NEW.direction <> 'out'
                OR NEW.commerce_payment_id IS NOT NULL
                OR NEW.destination_financial_account_id IS NOT NULL
                OR NEW.cash_security_drop_request_id IS NOT NULL
                OR NEW.purchase_payment_execution_id IS NULL
                OR NEW.reason_code IS NOT NULL
                OR NEW.note IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM purchase_payment_executions execution
                    WHERE execution.id = NEW.purchase_payment_execution_id
                      AND execution.organization_id = NEW.organization_id
                      AND execution.cash_register_session_id =
                          NEW.cash_register_session_id
                      AND execution.cash_register_id = NEW.cash_register_id
                      AND execution.origin_financial_account_id =
                          NEW.financial_account_id
                      AND execution.amount_minor = NEW.amount_minor
                      AND execution.currency_code = NEW.currency_code
                      AND execution.executed_by_user_id =
                          NEW.recorded_by_user_id
                )
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de efectivo P4F.3 no es valido.';
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
};
