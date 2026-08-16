<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXEC_INSERT =
        'post_sale_cash_refund_executions_guard_insert';

    private const EXEC_UPDATE =
        'post_sale_cash_refund_executions_guard_update';

    private const EXEC_DELETE =
        'post_sale_cash_refund_executions_guard_delete';

    private const CASH_MAIN =
        'cash_movements_guard_insert';

    private const CASH_REFUND =
        'cash_movements_post_sale_refund_guard_insert';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_cash_refund_executions',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'organization_id'
                );

                $table->uuid(
                    'public_id'
                );

                $table->foreignId(
                    'commerce_post_sale_resolution_id'
                );

                $table->foreignId(
                    'original_commerce_payment_id'
                );

                $table->foreignId(
                    'origin_financial_account_id'
                );

                $table->foreignId(
                    'cash_register_session_id'
                );

                $table->foreignId(
                    'cash_register_id'
                );

                $table->foreignId(
                    'executed_by_user_id'
                );

                $table->unsignedBigInteger(
                    'amount_minor'
                );

                $table->char(
                    'currency_code',
                    3
                );

                $table->string(
                    'execution_reference',
                    180
                )->nullable();

                $table->string(
                    'execution_note',
                    1000
                )->nullable();

                $table->string(
                    'idempotency_key',
                    180
                );

                $table->char(
                    'fingerprint',
                    64
                );

                $table->timestamp(
                    'executed_at'
                );

                $table->timestamp(
                    'created_at'
                );

                $table->unique(
                    'public_id',
                    'post_sale_cash_refund_public_id_unique'
                );

                $table->unique(
                    'commerce_post_sale_resolution_id',
                    'post_sale_cash_refund_resolution_unique'
                );

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'post_sale_cash_refund_org_idem_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'original_commerce_payment_id',
                        'executed_at',
                    ],
                    'post_sale_cash_refund_payment_index'
                );

                $table->foreign(
                    'organization_id',
                    'post_sale_cash_refund_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'commerce_post_sale_resolution_id',
                    'post_sale_cash_refund_resolution_fk'
                )
                    ->references('id')
                    ->on(
                        'commerce_post_sale_resolutions'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'original_commerce_payment_id',
                    'post_sale_cash_refund_payment_fk'
                )
                    ->references('id')
                    ->on('commerce_payments')
                    ->restrictOnDelete();

                $table->foreign(
                    'origin_financial_account_id',
                    'post_sale_cash_refund_account_fk'
                )
                    ->references('id')
                    ->on('financial_accounts')
                    ->restrictOnDelete();

                $table->foreign(
                    'cash_register_session_id',
                    'post_sale_cash_refund_session_fk'
                )
                    ->references('id')
                    ->on('cash_register_sessions')
                    ->restrictOnDelete();

                $table->foreign(
                    'cash_register_id',
                    'post_sale_cash_refund_register_fk'
                )
                    ->references('id')
                    ->on('cash_registers')
                    ->restrictOnDelete();

                $table->foreign(
                    'executed_by_user_id',
                    'post_sale_cash_refund_executor_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            }
        );

        $this->addCashMovementLink();
        $this->extendExistingCashInsertGuard();
        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P8.4.2 conserva reembolsos y movimientos de caja append-only; no admite rollback automático.'
        );
    }

    private function addCashMovementLink(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "post_sale_cash_refund_execution_id"
INTEGER NULL
REFERENCES "commerce_post_sale_cash_refund_executions" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX
"cash_movements_post_sale_cash_refund_execution_unique"
ON "cash_movements" ("post_sale_cash_refund_execution_id")
SQL);

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            Schema::table(
                'cash_movements',
                function (Blueprint $table): void {
                    $table->foreignId(
                        'post_sale_cash_refund_execution_id'
                    )
                        ->nullable()
                        ->unique(
                            'cash_movements_post_sale_cash_refund_execution_unique'
                        )
                        ->after(
                            'purchase_payment_execution_id'
                        )
                        ->constrained(
                            'commerce_post_sale_cash_refund_executions'
                        )
                        ->restrictOnDelete();
                }
            );

            return;
        }

        throw new LogicException(
            'La extensión de caja P8.4.2 no está implementada para '
            .$driver.'.'
        );
    }

    private function extendExistingCashInsertGuard(): void
    {
        $driver = DB::getDriverName();
        $pattern =
            "/'sale_payment'\s*,\s*'security_drop'\s*,\s*'purchase_payment'/";
        $replacement =
            "'sale_payment', 'security_drop', 'purchase_payment', 'post_sale_refund'";

        if ($driver === 'sqlite') {
            $row = DB::selectOne(<<<'SQL'
SELECT sql
FROM sqlite_master
WHERE type = 'trigger'
  AND name = 'cash_movements_guard_insert'
SQL);

            $sql =
                is_object($row)
                    && isset($row->sql)
                    && is_string($row->sql)
                    ? $row->sql
                    : null;

            if ($sql === null) {
                throw new LogicException(
                    'P8.4.2 no encontró el guard actual de cash_movements.'
                );
            }

            $extended =
                preg_replace(
                    $pattern,
                    $replacement,
                    $sql,
                    1,
                    $count
                );

            if (
                $extended === null
                || $count !== 1
            ) {
                throw new LogicException(
                    'P8.4.2 no pudo extender exactamente una vez el guard de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .self::CASH_MAIN
            );

            DB::unprepared($extended);

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'cash_movements_guard_insert'
SQL);

            $body =
                is_object($row)
                    && isset(
                        $row->action_statement
                    )
                    && is_string(
                        $row->action_statement
                    )
                    ? $row->action_statement
                    : null;

            if ($body === null) {
                throw new LogicException(
                    'P8.4.2 no encontró el guard MySQL actual de cash_movements.'
                );
            }

            $extendedBody =
                preg_replace(
                    $pattern,
                    $replacement,
                    $body,
                    1,
                    $count
                );

            if (
                $extendedBody === null
                || $count !== 1
            ) {
                throw new LogicException(
                    'P8.4.2 no pudo extender exactamente una vez el guard MySQL de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .self::CASH_MAIN
            );

            DB::unprepared(
                'CREATE TRIGGER '
                .self::CASH_MAIN
                .' BEFORE INSERT ON cash_movements '
                .'FOR EACH ROW '
                .$extendedBody
            );

            return;
        }

        throw new LogicException(
            'La extensión del guard de caja P8.4.2 no está implementada para '
            .$driver.'.'
        );
    }

    private function createGuards(): void
    {
        foreach ([
            self::CASH_REFUND,
            self::EXEC_DELETE,
            self::EXEC_UPDATE,
            self::EXEC_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteGuards();

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlGuards();

            return;
        }

        throw new LogicException(
            'La integridad P8.4.2 no está implementada para '
            .$driver.'.'
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_cash_refund_executions_guard_insert
BEFORE INSERT ON commerce_post_sale_cash_refund_executions
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.executed_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_resolutions resolution
        INNER JOIN commerce_post_sale_requests request_row
            ON request_row.id =
                resolution.commerce_post_sale_request_id
        INNER JOIN commerce_sales sale
            ON sale.id =
                request_row.commerce_sale_id
        INNER JOIN commerce_payments payment
            ON payment.id =
                NEW.original_commerce_payment_id
        INNER JOIN financial_accounts account
            ON account.id =
                NEW.origin_financial_account_id
        INNER JOIN cash_register_sessions session
            ON session.id =
                NEW.cash_register_session_id
        INNER JOIN cash_registers register_row
            ON register_row.id =
                NEW.cash_register_id
        WHERE resolution.id =
                NEW.commerce_post_sale_resolution_id
          AND resolution.organization_id =
                NEW.organization_id
          AND resolution.outcome = 'refund'
          AND resolution.preferred_original_payment_id =
                payment.id
          AND resolution.currency_code =
                NEW.currency_code
          AND resolution.resolved_by_user_id IS NOT NULL
          AND resolution.resolved_by_user_id <>
                NEW.executed_by_user_id
          AND request_row.organization_id =
                NEW.organization_id
          AND sale.id =
                request_row.commerce_sale_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.status = 'confirmed'
          AND sale.currency_code =
                NEW.currency_code
          AND payment.organization_id =
                NEW.organization_id
          AND payment.commerce_sale_id =
                sale.id
          AND payment.method = 'cash'
          AND payment.financial_account_id =
                account.id
          AND payment.amount_minor > 0
          AND account.organization_id =
                NEW.organization_id
          AND account.active = 1
          AND account.type = 'cash_box'
          AND account.currency_code =
                NEW.currency_code
          AND session.organization_id =
                NEW.organization_id
          AND session.status = 'open'
          AND session.opened_by_user_id =
                NEW.executed_by_user_id
          AND session.cash_register_id =
                register_row.id
          AND session.currency_code =
                NEW.currency_code
          AND register_row.organization_id =
                NEW.organization_id
          AND register_row.active = 1
          AND register_row.financial_account_id =
                account.id
          AND NEW.amount_minor = (
              SELECT COALESCE(
                  SUM(
                      resolution_line.recognized_amount_minor
                  ),
                  0
              )
              FROM commerce_post_sale_resolution_lines resolution_line
              WHERE resolution_line.organization_id =
                    NEW.organization_id
                AND resolution_line.commerce_post_sale_resolution_id =
                    resolution.id
          )
          AND NEW.amount_minor <=
              payment.amount_minor - COALESCE(
                  (
                      SELECT SUM(
                          previous.amount_minor
                      )
                      FROM commerce_post_sale_cash_refund_executions previous
                      WHERE previous.organization_id =
                            NEW.organization_id
                        AND previous.original_commerce_payment_id =
                            payment.id
                  ),
                  0
              )
          AND NEW.amount_minor <= (
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
          )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.executed_by_user_id
          AND membership.active = 1
          AND membership.role IN (
              'admin',
              'operator'
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El reembolso efectivo P8.4.2 no conserva resolución, medio original, segregación, caja o saldo válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_cash_refund_executions_guard_update
BEFORE UPDATE ON commerce_post_sale_cash_refund_executions
BEGIN
    SELECT RAISE(
        ABORT,
        'Un reembolso efectivo ejecutado es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_cash_refund_executions_guard_delete
BEFORE DELETE ON commerce_post_sale_cash_refund_executions
BEGIN
    SELECT RAISE(
        ABORT,
        'Un reembolso efectivo ejecutado no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_post_sale_refund_guard_insert
BEFORE INSERT ON cash_movements
WHEN (
        NEW.type = 'post_sale_refund'
        AND (
            NEW.direction <> 'out'
            OR NEW.commerce_payment_id IS NOT NULL
            OR NEW.destination_financial_account_id IS NOT NULL
            OR NEW.cash_security_drop_request_id IS NOT NULL
            OR NEW.purchase_payment_execution_id IS NOT NULL
            OR NEW.post_sale_cash_refund_execution_id IS NULL
            OR NEW.reason_code IS NOT NULL
            OR NEW.note IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM commerce_post_sale_cash_refund_executions execution
                WHERE execution.id =
                        NEW.post_sale_cash_refund_execution_id
                  AND execution.organization_id =
                        NEW.organization_id
                  AND execution.cash_register_session_id =
                        NEW.cash_register_session_id
                  AND execution.cash_register_id =
                        NEW.cash_register_id
                  AND execution.origin_financial_account_id =
                        NEW.financial_account_id
                  AND execution.executed_by_user_id =
                        NEW.recorded_by_user_id
                  AND execution.amount_minor =
                        NEW.amount_minor
                  AND execution.currency_code =
                        NEW.currency_code
            )
        )
    )
    OR (
        NEW.type <> 'post_sale_refund'
        AND NEW.post_sale_cash_refund_execution_id
            IS NOT NULL
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de caja P8.4.2 no conserva su ejecución de reembolso.'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_cash_refund_executions_guard_insert
BEFORE INSERT ON commerce_post_sale_cash_refund_executions
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.idempotency_key) > 180
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.executed_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_resolutions resolution
            INNER JOIN commerce_post_sale_requests request_row
                ON request_row.id =
                    resolution.commerce_post_sale_request_id
            INNER JOIN commerce_sales sale
                ON sale.id =
                    request_row.commerce_sale_id
            INNER JOIN commerce_payments payment
                ON payment.id =
                    NEW.original_commerce_payment_id
            INNER JOIN financial_accounts account
                ON account.id =
                    NEW.origin_financial_account_id
            INNER JOIN cash_register_sessions session
                ON session.id =
                    NEW.cash_register_session_id
            INNER JOIN cash_registers register_row
                ON register_row.id =
                    NEW.cash_register_id
            WHERE resolution.id =
                    NEW.commerce_post_sale_resolution_id
              AND resolution.organization_id =
                    NEW.organization_id
              AND resolution.outcome = 'refund'
              AND resolution.preferred_original_payment_id =
                    payment.id
              AND resolution.currency_code =
                    NEW.currency_code
              AND resolution.resolved_by_user_id IS NOT NULL
              AND resolution.resolved_by_user_id <>
                    NEW.executed_by_user_id
              AND request_row.organization_id =
                    NEW.organization_id
              AND sale.id =
                    request_row.commerce_sale_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.status = 'confirmed'
              AND sale.currency_code =
                    NEW.currency_code
              AND payment.organization_id =
                    NEW.organization_id
              AND payment.commerce_sale_id =
                    sale.id
              AND payment.method = 'cash'
              AND payment.financial_account_id =
                    account.id
              AND payment.amount_minor > 0
              AND account.organization_id =
                    NEW.organization_id
              AND account.active = 1
              AND account.type = 'cash_box'
              AND account.currency_code =
                    NEW.currency_code
              AND session.organization_id =
                    NEW.organization_id
              AND session.status = 'open'
              AND session.opened_by_user_id =
                    NEW.executed_by_user_id
              AND session.cash_register_id =
                    register_row.id
              AND session.currency_code =
                    NEW.currency_code
              AND register_row.organization_id =
                    NEW.organization_id
              AND register_row.active = 1
              AND register_row.financial_account_id =
                    account.id
              AND NEW.amount_minor = (
                  SELECT COALESCE(
                      SUM(
                          resolution_line.recognized_amount_minor
                      ),
                      0
                  )
                  FROM commerce_post_sale_resolution_lines resolution_line
                  WHERE resolution_line.organization_id =
                        NEW.organization_id
                    AND resolution_line.commerce_post_sale_resolution_id =
                        resolution.id
              )
              AND NEW.amount_minor <=
                  payment.amount_minor - COALESCE(
                      (
                          SELECT SUM(
                              previous.amount_minor
                          )
                          FROM commerce_post_sale_cash_refund_executions previous
                          WHERE previous.organization_id =
                                NEW.organization_id
                            AND previous.original_commerce_payment_id =
                                payment.id
                      ),
                      0
                  )
              AND NEW.amount_minor <= (
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
              )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.executed_by_user_id
              AND membership.active = 1
              AND membership.role IN (
                  'admin',
                  'operator'
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El reembolso efectivo P8.4.2 no conserva resolución, medio original, segregación, caja o saldo válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_cash_refund_executions_guard_update
BEFORE UPDATE ON commerce_post_sale_cash_refund_executions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un reembolso efectivo ejecutado es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_cash_refund_executions_guard_delete
BEFORE DELETE ON commerce_post_sale_cash_refund_executions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un reembolso efectivo ejecutado no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_post_sale_refund_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF (
            NEW.type = 'post_sale_refund'
            AND (
                NEW.direction <> 'out'
                OR NEW.commerce_payment_id IS NOT NULL
                OR NEW.destination_financial_account_id IS NOT NULL
                OR NEW.cash_security_drop_request_id IS NOT NULL
                OR NEW.purchase_payment_execution_id IS NOT NULL
                OR NEW.post_sale_cash_refund_execution_id IS NULL
                OR NEW.reason_code IS NOT NULL
                OR NEW.note IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM commerce_post_sale_cash_refund_executions execution
                    WHERE execution.id =
                            NEW.post_sale_cash_refund_execution_id
                      AND execution.organization_id =
                            NEW.organization_id
                      AND execution.cash_register_session_id =
                            NEW.cash_register_session_id
                      AND execution.cash_register_id =
                            NEW.cash_register_id
                      AND execution.origin_financial_account_id =
                            NEW.financial_account_id
                      AND execution.executed_by_user_id =
                            NEW.recorded_by_user_id
                      AND execution.amount_minor =
                            NEW.amount_minor
                      AND execution.currency_code =
                            NEW.currency_code
                )
            )
        )
        OR (
            NEW.type <> 'post_sale_refund'
            AND NEW.post_sale_cash_refund_execution_id
                IS NOT NULL
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de caja P8.4.2 no conserva su ejecución de reembolso.';
    END IF;
END
SQL);
    }
};
