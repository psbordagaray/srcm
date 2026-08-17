<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CASH_MAIN =
        'cash_movements_guard_insert';

    private const CASH_ADVANCE =
        'cash_movements_supplier_advance_guard_insert';

    public function up(): void
    {
        Schema::create(
            'supplier_advance_requests',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('supplier_id')
                    ->constrained('suppliers')
                    ->restrictOnDelete();
                $table->foreignId(
                    'origin_financial_account_id'
                )
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId(
                    'requested_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->char('currency_code', 3);
                $table->text(
                    'request_note'
                )->nullable();
                $table->string(
                    'request_idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestamp('requested_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'request_idempotency_key',
                    ],
                    'supplier_adv_req_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'supplier_id',
                        'requested_at',
                    ],
                    'supplier_adv_req_supplier_index'
                );
            }
        );

        Schema::create(
            'supplier_advance_decisions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'supplier_advance_request_id'
                )
                    ->unique(
                        'supplier_adv_dec_request_unique'
                    )
                    ->constrained(
                        'supplier_advance_requests'
                    )
                    ->restrictOnDelete();
                $table->string('decision', 24);
                $table->text(
                    'decision_note'
                )->nullable();
                $table->foreignId(
                    'decided_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string(
                    'idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestamp('decided_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'supplier_adv_dec_org_idem_unique'
                );
            }
        );

        Schema::create(
            'supplier_advances',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'supplier_advance_request_id'
                )
                    ->unique(
                        'supplier_adv_request_unique'
                    )
                    ->constrained(
                        'supplier_advance_requests'
                    )
                    ->restrictOnDelete();
                $table->foreignId('supplier_id')
                    ->constrained('suppliers')
                    ->restrictOnDelete();
                $table->foreignId(
                    'origin_financial_account_id'
                )
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId(
                    'cash_register_session_id'
                )
                    ->nullable()
                    ->constrained(
                        'cash_register_sessions'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'cash_register_id'
                )
                    ->nullable()
                    ->constrained('cash_registers')
                    ->restrictOnDelete();
                $table->foreignId(
                    'executed_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('channel', 16);
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->char('currency_code', 3);
                $table->string(
                    'execution_reference',
                    255
                )->nullable();
                $table->text(
                    'execution_note'
                )->nullable();
                $table->string(
                    'idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestamp('executed_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'supplier_adv_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'supplier_id',
                        'currency_code',
                        'executed_at',
                    ],
                    'supplier_adv_supplier_currency_index'
                );
            }
        );

        $this->addCashMovementLink();
        $this->extendCashMovementAllowedTypes();
        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.7f conserva solicitudes, decisiones y anticipos ejecutados append-only; no admite rollback automático.'
        );
    }

    private function addCashMovementLink(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "supplier_advance_id"
INTEGER NULL
REFERENCES "supplier_advances" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX
"cash_movements_supplier_advance_unique"
ON "cash_movements" ("supplier_advance_id")
SQL);

            return;
        }

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            Schema::table(
                'cash_movements',
                function (Blueprint $table): void {
                    $table->foreignId(
                        'supplier_advance_id'
                    )
                        ->nullable()
                        ->unique(
                            'cash_movements_supplier_advance_unique'
                        )
                        ->after(
                            'customer_advance_id'
                        )
                        ->constrained(
                            'supplier_advances'
                        )
                        ->restrictOnDelete();
                }
            );

            return;
        }

        throw new LogicException(
            "P9.7f no implementa vínculo de caja para {$driver}."
        );
    }

    private function extendCashMovementAllowedTypes():
        void
    {
        $driver = DB::getDriverName();

        $pattern =
            "/'sale_payment'\\s*,\\s*"
            ."'security_drop'\\s*,\\s*"
            ."'purchase_payment'\\s*,\\s*"
            ."'post_sale_refund'\\s*,\\s*"
            ."'post_sale_exchange_difference'\\s*,\\s*"
            ."'customer_collection'\\s*,\\s*"
            ."'customer_advance'/";

        $replacement =
            "'sale_payment', 'security_drop', "
            ."'purchase_payment', "
            ."'post_sale_refund', "
            ."'post_sale_exchange_difference', "
            ."'customer_collection', "
            ."'customer_advance', "
            ."'supplier_advance'";

        if ($driver === 'sqlite') {
            $row = DB::selectOne(<<<'SQL'
SELECT sql
FROM sqlite_master
WHERE type = 'trigger'
  AND name = 'cash_movements_guard_insert'
SQL);

            $sql = is_object($row)
                && isset($row->sql)
                && is_string($row->sql)
                    ? $row->sql
                    : null;

            if ($sql === null) {
                throw new LogicException(
                    'P9.7f no encontró el guard SQLite vigente de cash_movements.'
                );
            }

            $extended = preg_replace(
                $pattern,
                $replacement,
                $sql,
                1,
                $count
            );

            if (
                ! is_string($extended)
                || $count !== 1
            ) {
                throw new LogicException(
                    'P9.7f no pudo extender exactamente una vez el guard SQLite de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .self::CASH_MAIN
            );
            DB::unprepared($extended);

            return;
        }

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'cash_movements_guard_insert'
SQL);

            $body = is_object($row)
                && isset($row->action_statement)
                && is_string(
                    $row->action_statement
                )
                    ? $row->action_statement
                    : null;

            if ($body === null) {
                throw new LogicException(
                    'P9.7f no encontró el guard MySQL vigente de cash_movements.'
                );
            }

            $extended = preg_replace(
                $pattern,
                $replacement,
                $body,
                1,
                $count
            );

            if (
                ! is_string($extended)
                || $count !== 1
            ) {
                throw new LogicException(
                    'P9.7f no pudo extender exactamente una vez el guard MySQL de caja.'
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
                .$extended
            );

            return;
        }

        throw new LogicException(
            "P9.7f no implementa guard de caja para {$driver}."
        );
    }

    private function createGuards(): void
    {
        foreach ([
            self::CASH_ADVANCE,
            'supplier_advances_guard_delete',
            'supplier_advances_guard_update',
            'supplier_advances_guard_insert',
            'supplier_advance_decisions_guard_delete',
            'supplier_advance_decisions_guard_update',
            'supplier_advance_decisions_guard_insert',
            'supplier_advance_requests_guard_delete',
            'supplier_advance_requests_guard_update',
            'supplier_advance_requests_guard_insert',
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

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            $this->createMysqlGuards();

            return;
        }

        throw new LogicException(
            "P9.7f no implementa guards para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_requests_guard_insert
BEFORE INSERT ON supplier_advance_requests
WHEN NEW.amount_minor < 1
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code)
        <> NEW.currency_code
    OR LENGTH(TRIM(
        NEW.request_idempotency_key
    )) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.requested_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM suppliers supplier
        JOIN business_parties party
          ON party.id =
                supplier.business_party_id
         AND party.organization_id =
                supplier.organization_id
        WHERE supplier.id =
                NEW.supplier_id
          AND supplier.organization_id =
                NEW.organization_id
          AND supplier.active = 1
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id =
                NEW.origin_financial_account_id
          AND account.organization_id =
                NEW.organization_id
          AND account.active = 1
          AND account.currency_code =
                NEW.currency_code
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.requested_by_user_id
          AND membership.active = 1
          AND membership.role IN (
                'admin',
                'operator'
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_request_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_requests_guard_update
BEFORE UPDATE ON supplier_advance_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_request_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_requests_guard_delete
BEFORE DELETE ON supplier_advance_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_request_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_decisions_guard_insert
BEFORE INSERT ON supplier_advance_decisions
WHEN NEW.decision NOT IN (
        'approved',
        'rejected'
    )
    OR (
        NEW.decision = 'rejected'
        AND LENGTH(TRIM(
            COALESCE(
                NEW.decision_note,
                ''
            )
        )) = 0
    )
    OR LENGTH(TRIM(
        NEW.idempotency_key
    )) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.decided_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM supplier_advance_requests request
        WHERE request.id =
                NEW.supplier_advance_request_id
          AND request.organization_id =
                NEW.organization_id
          AND request.requested_by_user_id
                <> NEW.decided_by_user_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.decided_by_user_id
          AND membership.active = 1
          AND membership.role = 'admin'
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_decision_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_decisions_guard_update
BEFORE UPDATE ON supplier_advance_decisions
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_decision_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_decisions_guard_delete
BEFORE DELETE ON supplier_advance_decisions
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_decision_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advances_guard_insert
BEFORE INSERT ON supplier_advances
WHEN NEW.channel NOT IN (
        'cash',
        'noncash'
    )
    OR NEW.amount_minor < 1
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code)
        <> NEW.currency_code
    OR LENGTH(TRIM(
        NEW.idempotency_key
    )) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.executed_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM supplier_advance_requests request
        JOIN supplier_advance_decisions decision
          ON decision.supplier_advance_request_id =
                request.id
         AND decision.organization_id =
                request.organization_id
         AND decision.decision = 'approved'
        JOIN suppliers supplier
          ON supplier.id =
                request.supplier_id
         AND supplier.organization_id =
                request.organization_id
         AND supplier.active = 1
        JOIN financial_accounts account
          ON account.id =
                request.origin_financial_account_id
         AND account.organization_id =
                request.organization_id
         AND account.active = 1
         AND account.currency_code =
                request.currency_code
        WHERE request.id =
                NEW.supplier_advance_request_id
          AND request.organization_id =
                NEW.organization_id
          AND request.supplier_id =
                NEW.supplier_id
          AND request.origin_financial_account_id =
                NEW.origin_financial_account_id
          AND request.amount_minor =
                NEW.amount_minor
          AND request.currency_code =
                NEW.currency_code
          AND decision.decided_by_user_id
                <> NEW.executed_by_user_id
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
    OR (
        NEW.channel = 'cash'
        AND (
            NEW.cash_register_session_id
                IS NULL
            OR NEW.cash_register_id
                IS NULL
            OR NOT EXISTS (
                SELECT 1
                FROM cash_register_sessions session
                JOIN cash_registers register_row
                  ON register_row.id =
                        session.cash_register_id
                JOIN financial_accounts account
                  ON account.id =
                        register_row
                            .financial_account_id
                WHERE session.id =
                        NEW.cash_register_session_id
                  AND session.organization_id =
                        NEW.organization_id
                  AND session.status = 'open'
                  AND session.opened_by_user_id =
                        NEW.executed_by_user_id
                  AND session.cash_register_id =
                        NEW.cash_register_id
                  AND session.currency_code =
                        NEW.currency_code
                  AND register_row
                        .organization_id =
                        NEW.organization_id
                  AND register_row.active = 1
                  AND register_row
                        .financial_account_id =
                        NEW.origin_financial_account_id
                  AND account.organization_id =
                        NEW.organization_id
                  AND account.active = 1
                  AND account.type =
                        'cash_box'
                  AND account.currency_code =
                        NEW.currency_code
            )
        )
    )
    OR (
        NEW.channel = 'noncash'
        AND (
            NEW.cash_register_session_id
                IS NOT NULL
            OR NEW.cash_register_id
                IS NOT NULL
            OR LENGTH(TRIM(
                COALESCE(
                    NEW.execution_reference,
                    ''
                )
            )) = 0
            OR EXISTS (
                SELECT 1
                FROM financial_accounts account
                WHERE account.id =
                        NEW.origin_financial_account_id
                  AND account.type IN (
                        'cash_box',
                        'cash_reserve'
                  )
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_execution_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advances_guard_update
BEFORE UPDATE ON supplier_advances
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advances_guard_delete
BEFORE DELETE ON supplier_advances
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_supplier_advance_guard_insert
BEFORE INSERT ON cash_movements
WHEN (
    NEW.type = 'supplier_advance'
    AND (
        NEW.direction <> 'out'
        OR NEW.supplier_advance_id
            IS NULL
        OR NEW.commerce_payment_id
            IS NOT NULL
        OR NEW.customer_collection_id
            IS NOT NULL
        OR NEW.customer_advance_id
            IS NOT NULL
        OR NEW.destination_financial_account_id
            IS NOT NULL
        OR NEW.cash_security_drop_request_id
            IS NOT NULL
        OR NEW.purchase_payment_execution_id
            IS NOT NULL
        OR NEW.post_sale_cash_refund_execution_id
            IS NOT NULL
        OR NEW.post_sale_exchange_payment_id
            IS NOT NULL
        OR NEW.reason_code IS NOT NULL
        OR NEW.note IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM supplier_advances advance_row
            WHERE advance_row.id =
                    NEW.supplier_advance_id
              AND advance_row.organization_id =
                    NEW.organization_id
              AND advance_row.channel =
                    'cash'
              AND advance_row
                    .cash_register_session_id =
                    NEW.cash_register_session_id
              AND advance_row
                    .cash_register_id =
                    NEW.cash_register_id
              AND advance_row
                    .origin_financial_account_id =
                    NEW.financial_account_id
              AND advance_row.amount_minor =
                    NEW.amount_minor
              AND advance_row.currency_code =
                    NEW.currency_code
              AND advance_row.executed_by_user_id =
                    NEW.recorded_by_user_id
        )
    )
)
OR (
    NEW.type <> 'supplier_advance'
    AND NEW.supplier_advance_id
        IS NOT NULL
)
BEGIN
    SELECT RAISE(
        ABORT,
        'cash_supplier_advance_invalid'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_requests_guard_insert
BEFORE INSERT ON supplier_advance_requests
FOR EACH ROW
BEGIN
    DECLARE supplier_count BIGINT DEFAULT 0;
    DECLARE account_count BIGINT DEFAULT 0;
    DECLARE member_count BIGINT DEFAULT 0;

    SELECT COUNT(*)
      INTO supplier_count
      FROM suppliers supplier
      JOIN business_parties party
        ON party.id =
            supplier.business_party_id
       AND party.organization_id =
            supplier.organization_id
     WHERE supplier.id = NEW.supplier_id
       AND supplier.organization_id =
            NEW.organization_id
       AND supplier.active = 1;

    SELECT COUNT(*)
      INTO account_count
      FROM financial_accounts account
     WHERE account.id =
            NEW.origin_financial_account_id
       AND account.organization_id =
            NEW.organization_id
       AND account.active = 1
       AND BINARY account.currency_code =
            BINARY NEW.currency_code;

    SELECT COUNT(*)
      INTO member_count
      FROM organization_memberships membership
     WHERE membership.organization_id =
            NEW.organization_id
       AND membership.user_id =
            NEW.requested_by_user_id
       AND membership.active = 1
       AND membership.role IN (
            'admin',
            'operator'
       );

    IF NEW.amount_minor < 1
       OR CHAR_LENGTH(
            NEW.currency_code
       ) <> 3
       OR BINARY NEW.currency_code
            <> BINARY UPPER(
                NEW.currency_code
            )
       OR CHAR_LENGTH(TRIM(
            NEW.request_idempotency_key
       )) = 0
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.requested_at IS NULL
       OR NEW.created_at IS NULL
       OR supplier_count <> 1
       OR account_count <> 1
       OR member_count <> 1
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_advance_request_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_requests_guard_update
BEFORE UPDATE ON supplier_advance_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_request_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_requests_guard_delete
BEFORE DELETE ON supplier_advance_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_request_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_decisions_guard_insert
BEFORE INSERT ON supplier_advance_decisions
FOR EACH ROW
BEGIN
    DECLARE request_count BIGINT DEFAULT 0;
    DECLARE member_count BIGINT DEFAULT 0;

    SELECT COUNT(*)
      INTO request_count
      FROM supplier_advance_requests request
     WHERE request.id =
            NEW.supplier_advance_request_id
       AND request.organization_id =
            NEW.organization_id
       AND request.requested_by_user_id
            <> NEW.decided_by_user_id;

    SELECT COUNT(*)
      INTO member_count
      FROM organization_memberships membership
     WHERE membership.organization_id =
            NEW.organization_id
       AND membership.user_id =
            NEW.decided_by_user_id
       AND membership.active = 1
       AND membership.role = 'admin';

    IF NEW.decision NOT IN (
            'approved',
            'rejected'
       )
       OR (
            NEW.decision = 'rejected'
            AND CHAR_LENGTH(TRIM(
                COALESCE(
                    NEW.decision_note,
                    ''
                )
            )) = 0
       )
       OR CHAR_LENGTH(TRIM(
            NEW.idempotency_key
       )) = 0
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.decided_at IS NULL
       OR NEW.created_at IS NULL
       OR request_count <> 1
       OR member_count <> 1
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_advance_decision_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_decisions_guard_update
BEFORE UPDATE ON supplier_advance_decisions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_decision_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_decisions_guard_delete
BEFORE DELETE ON supplier_advance_decisions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_decision_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advances_guard_insert
BEFORE INSERT ON supplier_advances
FOR EACH ROW
BEGIN
    DECLARE request_count BIGINT DEFAULT 0;
    DECLARE member_count BIGINT DEFAULT 0;
    DECLARE cash_context_count BIGINT DEFAULT 0;
    DECLARE origin_type VARCHAR(40) DEFAULT NULL;

    SELECT COUNT(*)
      INTO request_count
      FROM supplier_advance_requests request
      JOIN supplier_advance_decisions decision
        ON decision.supplier_advance_request_id =
            request.id
       AND decision.organization_id =
            request.organization_id
       AND decision.decision = 'approved'
      JOIN suppliers supplier
        ON supplier.id = request.supplier_id
       AND supplier.organization_id =
            request.organization_id
       AND supplier.active = 1
      JOIN financial_accounts account
        ON account.id =
            request.origin_financial_account_id
       AND account.organization_id =
            request.organization_id
       AND account.active = 1
       AND BINARY account.currency_code =
            BINARY request.currency_code
     WHERE request.id =
            NEW.supplier_advance_request_id
       AND request.organization_id =
            NEW.organization_id
       AND request.supplier_id =
            NEW.supplier_id
       AND request.origin_financial_account_id =
            NEW.origin_financial_account_id
       AND request.amount_minor =
            NEW.amount_minor
       AND BINARY request.currency_code =
            BINARY NEW.currency_code
       AND decision.decided_by_user_id
            <> NEW.executed_by_user_id;

    SELECT COUNT(*)
      INTO member_count
      FROM organization_memberships membership
     WHERE membership.organization_id =
            NEW.organization_id
       AND membership.user_id =
            NEW.executed_by_user_id
       AND membership.active = 1
       AND membership.role IN (
            'admin',
            'operator'
       );

    SELECT account.type
      INTO origin_type
      FROM financial_accounts account
     WHERE account.id =
            NEW.origin_financial_account_id
       AND account.organization_id =
            NEW.organization_id
       AND account.active = 1
     LIMIT 1;

    IF NEW.channel = 'cash' THEN
        SELECT COUNT(*)
          INTO cash_context_count
          FROM cash_register_sessions session
          JOIN cash_registers register_row
            ON register_row.id =
                session.cash_register_id
          JOIN financial_accounts account
            ON account.id =
                register_row
                    .financial_account_id
         WHERE session.id =
                NEW.cash_register_session_id
           AND session.organization_id =
                NEW.organization_id
           AND session.status = 'open'
           AND session.opened_by_user_id =
                NEW.executed_by_user_id
           AND session.cash_register_id =
                NEW.cash_register_id
           AND BINARY session.currency_code =
                BINARY NEW.currency_code
           AND register_row.organization_id =
                NEW.organization_id
           AND register_row.active = 1
           AND register_row.financial_account_id =
                NEW.origin_financial_account_id
           AND account.organization_id =
                NEW.organization_id
           AND account.active = 1
           AND account.type = 'cash_box'
           AND BINARY account.currency_code =
                BINARY NEW.currency_code;
    END IF;

    IF NEW.channel NOT IN (
            'cash',
            'noncash'
       )
       OR NEW.amount_minor < 1
       OR CHAR_LENGTH(
            NEW.currency_code
       ) <> 3
       OR BINARY NEW.currency_code
            <> BINARY UPPER(
                NEW.currency_code
            )
       OR CHAR_LENGTH(TRIM(
            NEW.idempotency_key
       )) = 0
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.executed_at IS NULL
       OR NEW.created_at IS NULL
       OR request_count <> 1
       OR member_count <> 1
       OR (
            NEW.channel = 'cash'
            AND (
                NEW.cash_register_session_id
                    IS NULL
                OR NEW.cash_register_id
                    IS NULL
                OR origin_type <> 'cash_box'
                OR cash_context_count <> 1
            )
       )
       OR (
            NEW.channel = 'noncash'
            AND (
                NEW.cash_register_session_id
                    IS NOT NULL
                OR NEW.cash_register_id
                    IS NOT NULL
                OR CHAR_LENGTH(TRIM(
                    COALESCE(
                        NEW.execution_reference,
                        ''
                    )
                )) = 0
                OR origin_type IN (
                    'cash_box',
                    'cash_reserve'
                )
            )
       )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_advance_execution_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advances_guard_update
BEFORE UPDATE ON supplier_advances
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advances_guard_delete
BEFORE DELETE ON supplier_advances
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_supplier_advance_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    DECLARE relation_count BIGINT DEFAULT 0;

    IF NEW.type = 'supplier_advance' THEN
        SELECT COUNT(*)
          INTO relation_count
          FROM supplier_advances advance_row
         WHERE advance_row.id =
                NEW.supplier_advance_id
           AND advance_row.organization_id =
                NEW.organization_id
           AND advance_row.channel = 'cash'
           AND advance_row.cash_register_session_id =
                NEW.cash_register_session_id
           AND advance_row.cash_register_id =
                NEW.cash_register_id
           AND advance_row.origin_financial_account_id =
                NEW.financial_account_id
           AND advance_row.amount_minor =
                NEW.amount_minor
           AND BINARY advance_row.currency_code =
                BINARY NEW.currency_code
           AND advance_row.executed_by_user_id =
                NEW.recorded_by_user_id;

        IF NEW.direction <> 'out'
           OR NEW.supplier_advance_id IS NULL
           OR NEW.commerce_payment_id IS NOT NULL
           OR NEW.customer_collection_id IS NOT NULL
           OR NEW.customer_advance_id IS NOT NULL
           OR NEW.destination_financial_account_id
                IS NOT NULL
           OR NEW.cash_security_drop_request_id
                IS NOT NULL
           OR NEW.purchase_payment_execution_id
                IS NOT NULL
           OR NEW.post_sale_cash_refund_execution_id
                IS NOT NULL
           OR NEW.post_sale_exchange_payment_id
                IS NOT NULL
           OR NEW.reason_code IS NOT NULL
           OR NEW.note IS NOT NULL
           OR relation_count <> 1
        THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'cash_supplier_advance_invalid';
        END IF;
    ELSEIF NEW.supplier_advance_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'cash_supplier_advance_invalid';
    END IF;
END
SQL);
    }
};
