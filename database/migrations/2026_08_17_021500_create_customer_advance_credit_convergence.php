<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADVANCE_INSERT =
        'customer_advances_guard_insert';
    private const ADVANCE_UPDATE =
        'customer_advances_guard_update';
    private const ADVANCE_DELETE =
        'customer_advances_guard_delete';
    private const CASH_MAIN =
        'cash_movements_guard_insert';
    private const CASH_ADVANCE =
        'cash_movements_customer_advance_guard_insert';
    private const CREDIT_ALLOCATION_INSERT =
        'customer_credit_allocations_guard_insert';
    private const CREDIT_ALLOCATION_UPDATE =
        'customer_credit_allocations_guard_update';
    private const CREDIT_ALLOCATION_DELETE =
        'customer_credit_allocations_guard_delete';

    public function up(): void
    {
        Schema::create(
            'customer_advances',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('business_party_id')
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->foreignId('financial_account_id')
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
                $table->foreignId('cash_register_id')
                    ->nullable()
                    ->constrained('cash_registers')
                    ->restrictOnDelete();
                $table->string('status', 24);
                $table->string('method', 40);
                $table->char('currency_code', 3);
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->unsignedBigInteger(
                    'tendered_amount_minor'
                )->nullable();
                $table->unsignedBigInteger(
                    'change_amount_minor'
                )->nullable();
                $table->string(
                    'reference',
                    255
                )->nullable();
                $table->string(
                    'notes',
                    1000
                )->nullable();
                $table->foreignId(
                    'received_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('received_at');
                $table->string(
                    'idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_advances_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'customer_advances_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'received_at',
                    ],
                    'customer_advances_party_currency_index'
                );
            }
        );

        $this->addAdvanceLinks();
        $this->extendCashMovementAllowedTypes();
        $this->replaceCreditAllocationGuards();
        $this->createAdvanceGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.6a conserva anticipos y su convergencia de saldo a favor append-only; no admite rollback automático.'
        );
    }

    private function addAdvanceLinks(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "customer_advance_id"
INTEGER NULL
REFERENCES "customer_advances" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX
"cash_movements_customer_advance_unique"
ON "cash_movements" ("customer_advance_id")
SQL);

            DB::statement(<<<'SQL'
ALTER TABLE "customer_credit_consumption_allocations"
ADD COLUMN "customer_advance_id"
INTEGER NULL
REFERENCES "customer_advances" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE INDEX
"customer_credit_allocations_advance_index"
ON "customer_credit_consumption_allocations"
("customer_advance_id")
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
                function (
                    Blueprint $table
                ): void {
                    $table->foreignId(
                        'customer_advance_id'
                    )
                        ->nullable()
                        ->unique(
                            'cash_movements_customer_advance_unique'
                        )
                        ->after(
                            'customer_collection_id'
                        )
                        ->constrained(
                            'customer_advances'
                        )
                        ->restrictOnDelete();
                }
            );

            Schema::table(
                'customer_credit_consumption_allocations',
                function (
                    Blueprint $table
                ): void {
                    $table->foreignId(
                        'customer_advance_id'
                    )
                        ->nullable()
                        ->after(
                            'commerce_post_sale_exchange_credit_grant_id'
                        )
                        ->constrained(
                            'customer_advances'
                        )
                        ->restrictOnDelete();

                    $table->index(
                        'customer_advance_id',
                        'customer_credit_allocations_advance_index'
                    );
                }
            );

            return;
        }

        throw new LogicException(
            "P9.6a no implementa extensión de vínculos para {$driver}."
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
            ."'customer_collection'/";

        $replacement =
            "'sale_payment', 'security_drop', "
            ."'purchase_payment', "
            ."'post_sale_refund', "
            ."'post_sale_exchange_difference', "
            ."'customer_collection', "
            ."'customer_advance'";

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
                    'P9.6a no encontró el guard SQLite vigente de cash_movements.'
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
                    'P9.6a no pudo extender exactamente una vez el guard SQLite de caja.'
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

            $body = is_object($row)
                && isset($row->action_statement)
                && is_string(
                    $row->action_statement
                )
                    ? $row->action_statement
                    : null;

            if ($body === null) {
                throw new LogicException(
                    'P9.6a no encontró el guard MySQL vigente de cash_movements.'
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
                    'P9.6a no pudo extender exactamente una vez el guard MySQL de caja.'
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
            "P9.6a no implementa guard de caja para {$driver}."
        );
    }

    private function replaceCreditAllocationGuards():
        void
    {
        foreach ([
            self::CREDIT_ALLOCATION_DELETE,
            self::CREDIT_ALLOCATION_UPDATE,
            self::CREDIT_ALLOCATION_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteCreditAllocationGuards();

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlCreditAllocationGuards();

            return;
        }

        throw new LogicException(
            "P9.6a no implementa guards de convergencia para {$driver}."
        );
    }

    private function createSqliteCreditAllocationGuards():
        void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_insert
BEFORE INSERT ON customer_credit_consumption_allocations
BEGIN
    SELECT CASE
        WHEN NEW.amount_minor <= 0
          OR NEW.sequence <= 0
          OR (
                (NEW.customer_credit_grant_id IS NOT NULL)
                + (
                    NEW.commerce_post_sale_exchange_credit_grant_id
                    IS NOT NULL
                )
                + (NEW.customer_advance_id IS NOT NULL)
             ) <> 1
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_shape_invalid'
        )
    END;

    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM customer_credit_consumptions c
            WHERE c.id =
                    NEW.customer_credit_consumption_id
              AND c.organization_id =
                    NEW.organization_id
        )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_consumption_invalid'
        )
    END;

    SELECT CASE
        WHEN NEW.customer_credit_grant_id IS NOT NULL
         AND NOT EXISTS (
            SELECT 1
            FROM customer_credit_grants g
            JOIN customer_credit_consumptions c
              ON c.id =
                    NEW.customer_credit_consumption_id
            WHERE g.id =
                    NEW.customer_credit_grant_id
              AND g.organization_id =
                    NEW.organization_id
              AND g.business_party_id =
                    c.business_party_id
              AND g.currency_code =
                    c.currency_code
        )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_standard_source_invalid'
        )
    END;

    SELECT CASE
        WHEN NEW.commerce_post_sale_exchange_credit_grant_id
                IS NOT NULL
         AND NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_credit_grants g
            JOIN customer_credit_consumptions c
              ON c.id =
                    NEW.customer_credit_consumption_id
            WHERE g.id =
                    NEW.commerce_post_sale_exchange_credit_grant_id
              AND g.organization_id =
                    NEW.organization_id
              AND g.business_party_id =
                    c.business_party_id
              AND g.currency_code =
                    c.currency_code
        )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_exchange_source_invalid'
        )
    END;

    SELECT CASE
        WHEN NEW.customer_advance_id IS NOT NULL
         AND NOT EXISTS (
            SELECT 1
            FROM customer_advances a
            JOIN customer_credit_consumptions c
              ON c.id =
                    NEW.customer_credit_consumption_id
            WHERE a.id =
                    NEW.customer_advance_id
              AND a.organization_id =
                    NEW.organization_id
              AND a.business_party_id =
                    c.business_party_id
              AND a.currency_code =
                    c.currency_code
              AND a.status = 'confirmed'
        )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_advance_source_invalid'
        )
    END;

    SELECT CASE
        WHEN NEW.customer_credit_grant_id IS NOT NULL
         AND (
            COALESCE((
                SELECT SUM(a.amount_minor)
                FROM customer_credit_consumption_allocations a
                WHERE a.customer_credit_grant_id =
                    NEW.customer_credit_grant_id
            ), 0)
            + NEW.amount_minor
         ) > (
            SELECT g.amount_minor
            FROM customer_credit_grants g
            WHERE g.id =
                NEW.customer_credit_grant_id
         )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_standard_overdraw'
        )
    END;

    SELECT CASE
        WHEN NEW.commerce_post_sale_exchange_credit_grant_id
                IS NOT NULL
         AND (
            COALESCE((
                SELECT SUM(a.amount_minor)
                FROM customer_credit_consumption_allocations a
                WHERE a.commerce_post_sale_exchange_credit_grant_id =
                    NEW.commerce_post_sale_exchange_credit_grant_id
            ), 0)
            + NEW.amount_minor
         ) > (
            SELECT g.amount_minor
            FROM commerce_post_sale_exchange_credit_grants g
            WHERE g.id =
                NEW.commerce_post_sale_exchange_credit_grant_id
         )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_exchange_overdraw'
        )
    END;

    SELECT CASE
        WHEN NEW.customer_advance_id IS NOT NULL
         AND (
            COALESCE((
                SELECT SUM(a.amount_minor)
                FROM customer_credit_consumption_allocations a
                WHERE a.customer_advance_id =
                    NEW.customer_advance_id
            ), 0)
            + NEW.amount_minor
         ) > (
            SELECT a.amount_minor
            FROM customer_advances a
            WHERE a.id =
                NEW.customer_advance_id
         )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_advance_overdraw'
        )
    END;

    SELECT CASE
        WHEN (
            COALESCE((
                SELECT SUM(a.amount_minor)
                FROM customer_credit_consumption_allocations a
                WHERE a.customer_credit_consumption_id =
                    NEW.customer_credit_consumption_id
            ), 0)
            + NEW.amount_minor
        ) > (
            SELECT c.amount_minor
            FROM customer_credit_consumptions c
            WHERE c.id =
                NEW.customer_credit_consumption_id
        )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_consumption_overdraw'
        )
    END;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_update
BEFORE UPDATE ON customer_credit_consumption_allocations
BEGIN
    SELECT RAISE(
        ABORT,
        'customer_credit_allocation_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_delete
BEFORE DELETE ON customer_credit_consumption_allocations
BEGIN
    SELECT RAISE(
        ABORT,
        'customer_credit_allocation_immutable'
    );
END
SQL);
    }

    private function createMysqlCreditAllocationGuards():
        void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_insert
BEFORE INSERT ON customer_credit_consumption_allocations
FOR EACH ROW
BEGIN
    DECLARE v_consumption_amount
        BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_consumed
        BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_source_amount
        BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_source_consumed
        BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_party
        BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_currency CHAR(3);

    IF NEW.amount_minor <= 0
       OR NEW.sequence <= 0
       OR (
            (NEW.customer_credit_grant_id IS NOT NULL)
            + (
                NEW.commerce_post_sale_exchange_credit_grant_id
                IS NOT NULL
            )
            + (NEW.customer_advance_id IS NOT NULL)
       ) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'customer_credit_allocation_shape_invalid';
    END IF;

    SELECT
        c.amount_minor,
        c.business_party_id,
        c.currency_code
      INTO
        v_consumption_amount,
        v_party,
        v_currency
      FROM customer_credit_consumptions c
     WHERE c.id =
            NEW.customer_credit_consumption_id
       AND c.organization_id =
            NEW.organization_id
     LIMIT 1;

    IF v_consumption_amount = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'customer_credit_allocation_consumption_invalid';
    END IF;

    SELECT COALESCE(
        SUM(a.amount_minor),
        0
    )
      INTO v_consumed
      FROM customer_credit_consumption_allocations a
     WHERE a.customer_credit_consumption_id =
        NEW.customer_credit_consumption_id;

    IF v_consumed + NEW.amount_minor
        > v_consumption_amount THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'customer_credit_allocation_consumption_overdraw';
    END IF;

    IF NEW.customer_credit_grant_id IS NOT NULL THEN
        SELECT COALESCE(
            MAX(g.amount_minor),
            0
        )
          INTO v_source_amount
          FROM customer_credit_grants g
         WHERE g.id =
                NEW.customer_credit_grant_id
           AND g.organization_id =
                NEW.organization_id
           AND g.business_party_id =
                v_party
           AND BINARY g.currency_code =
                BINARY v_currency;

        SELECT COALESCE(
            SUM(a.amount_minor),
            0
        )
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations a
         WHERE a.customer_credit_grant_id =
            NEW.customer_credit_grant_id;

    ELSEIF
        NEW.commerce_post_sale_exchange_credit_grant_id
            IS NOT NULL
    THEN
        SELECT COALESCE(
            MAX(g.amount_minor),
            0
        )
          INTO v_source_amount
          FROM commerce_post_sale_exchange_credit_grants g
         WHERE g.id =
                NEW.commerce_post_sale_exchange_credit_grant_id
           AND g.organization_id =
                NEW.organization_id
           AND g.business_party_id =
                v_party
           AND BINARY g.currency_code =
                BINARY v_currency;

        SELECT COALESCE(
            SUM(a.amount_minor),
            0
        )
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations a
         WHERE a.commerce_post_sale_exchange_credit_grant_id =
            NEW.commerce_post_sale_exchange_credit_grant_id;

    ELSE
        SELECT COALESCE(
            MAX(a.amount_minor),
            0
        )
          INTO v_source_amount
          FROM customer_advances a
         WHERE a.id =
                NEW.customer_advance_id
           AND a.organization_id =
                NEW.organization_id
           AND a.business_party_id =
                v_party
           AND BINARY a.currency_code =
                BINARY v_currency
           AND a.status = 'confirmed';

        SELECT COALESCE(
            SUM(allocation.amount_minor),
            0
        )
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations allocation
         WHERE allocation.customer_advance_id =
            NEW.customer_advance_id;
    END IF;

    IF v_source_amount = 0
       OR v_source_consumed
            + NEW.amount_minor
            > v_source_amount
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'customer_credit_allocation_source_overdraw';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_update
BEFORE UPDATE ON customer_credit_consumption_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'customer_credit_allocation_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_delete
BEFORE DELETE ON customer_credit_consumption_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'customer_credit_allocation_immutable';
END
SQL);
    }

    private function createAdvanceGuards(): void
    {
        foreach ([
            self::CASH_ADVANCE,
            self::ADVANCE_DELETE,
            self::ADVANCE_UPDATE,
            self::ADVANCE_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteAdvanceGuards();

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlAdvanceGuards();

            return;
        }

        throw new LogicException(
            "P9.6a no implementa guards de anticipo para {$driver}."
        );
    }

    private function createSqliteAdvanceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_advances_guard_insert
BEFORE INSERT ON customer_advances
WHEN NEW.status <> 'building'
    OR NEW.amount_minor < 1
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code)
        <> NEW.currency_code
    OR NEW.method NOT IN (
        'cash',
        'debit_card',
        'credit_card',
        'bank_transfer',
        'digital_wallet',
        'other'
    )
    OR LENGTH(
        TRIM(NEW.idempotency_key)
    ) = 0
    OR LENGTH(NEW.idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.received_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM business_parties party
        INNER JOIN customers customer
            ON customer.business_party_id =
                party.id
            AND customer.organization_id =
                NEW.organization_id
            AND customer.active = 1
        WHERE party.id =
                NEW.business_party_id
            AND party.organization_id =
                NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
            AND membership.user_id =
                NEW.received_by_user_id
            AND membership.active = 1
            AND membership.role IN (
                'admin',
                'operator'
            )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id =
                NEW.financial_account_id
            AND account.organization_id =
                NEW.organization_id
            AND account.active = 1
            AND account.currency_code =
                NEW.currency_code
    )
    OR (
        NEW.method = 'cash'
        AND (
            NEW.cash_register_session_id
                IS NULL
            OR NEW.cash_register_id IS NULL
            OR NEW.reference IS NOT NULL
            OR (
                NEW.tendered_amount_minor
                    IS NOT NULL
                AND NEW.tendered_amount_minor
                    < NEW.amount_minor
            )
            OR (
                NEW.tendered_amount_minor
                    IS NULL
                AND NEW.change_amount_minor
                    IS NOT NULL
            )
            OR (
                NEW.tendered_amount_minor
                    IS NOT NULL
                AND NEW.change_amount_minor
                    IS NULL
            )
            OR (
                NEW.tendered_amount_minor
                    IS NOT NULL
                AND NEW.change_amount_minor
                    <> NEW.tendered_amount_minor
                        - NEW.amount_minor
            )
            OR NOT EXISTS (
                SELECT 1
                FROM cash_register_sessions session
                INNER JOIN cash_registers register_row
                    ON register_row.id =
                        session.cash_register_id
                INNER JOIN financial_accounts account
                    ON account.id =
                        register_row.financial_account_id
                WHERE session.id =
                        NEW.cash_register_session_id
                    AND session.organization_id =
                        NEW.organization_id
                    AND session.status = 'open'
                    AND session.opened_by_user_id =
                        NEW.received_by_user_id
                    AND session.cash_register_id =
                        NEW.cash_register_id
                    AND session.currency_code =
                        NEW.currency_code
                    AND register_row.organization_id =
                        NEW.organization_id
                    AND register_row.active = 1
                    AND register_row.financial_account_id =
                        NEW.financial_account_id
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
        NEW.method <> 'cash'
        AND (
            NEW.cash_register_session_id
                IS NOT NULL
            OR NEW.cash_register_id
                IS NOT NULL
            OR NEW.tendered_amount_minor
                IS NOT NULL
            OR NEW.change_amount_minor
                IS NOT NULL
            OR TRIM(
                COALESCE(
                    NEW.reference,
                    ''
                )
            ) = ''
            OR EXISTS (
                SELECT 1
                FROM financial_accounts account
                WHERE account.id =
                        NEW.financial_account_id
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
        'El anticipo no conserva cliente, cuenta, medio, importe o autoridad válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_advances_guard_update
BEFORE UPDATE ON customer_advances
WHEN OLD.status <> 'building'
    OR NEW.status <> 'confirmed'
    OR NEW.organization_id
        <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.business_party_id
        <> OLD.business_party_id
    OR NEW.financial_account_id
        <> OLD.financial_account_id
    OR COALESCE(
        NEW.cash_register_session_id,
        0
    ) <> COALESCE(
        OLD.cash_register_session_id,
        0
    )
    OR COALESCE(
        NEW.cash_register_id,
        0
    ) <> COALESCE(
        OLD.cash_register_id,
        0
    )
    OR NEW.method <> OLD.method
    OR NEW.currency_code
        <> OLD.currency_code
    OR NEW.amount_minor
        <> OLD.amount_minor
    OR COALESCE(
        NEW.tendered_amount_minor,
        0
    ) <> COALESCE(
        OLD.tendered_amount_minor,
        0
    )
    OR COALESCE(
        NEW.change_amount_minor,
        0
    ) <> COALESCE(
        OLD.change_amount_minor,
        0
    )
    OR COALESCE(
        NEW.reference,
        ''
    ) <> COALESCE(
        OLD.reference,
        ''
    )
    OR COALESCE(
        NEW.notes,
        ''
    ) <> COALESCE(
        OLD.notes,
        ''
    )
    OR NEW.received_by_user_id
        <> OLD.received_by_user_id
    OR NEW.received_at
        <> OLD.received_at
    OR NEW.idempotency_key
        <> OLD.idempotency_key
    OR NEW.fingerprint
        <> OLD.fingerprint
    OR NEW.created_at
        <> OLD.created_at
BEGIN
    SELECT RAISE(
        ABORT,
        'Un anticipo sólo puede confirmarse sin reescribir sus datos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_advances_guard_delete
BEFORE DELETE ON customer_advances
BEGIN
    SELECT RAISE(
        ABORT,
        'Un anticipo no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_customer_advance_guard_insert
BEFORE INSERT ON cash_movements
WHEN (
    NEW.type = 'customer_advance'
    AND (
        NEW.direction <> 'in'
        OR NEW.customer_advance_id IS NULL
        OR NEW.commerce_payment_id
            IS NOT NULL
        OR NEW.customer_collection_id
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
            FROM customer_advances advance_row
            WHERE advance_row.id =
                    NEW.customer_advance_id
                AND advance_row.organization_id =
                    NEW.organization_id
                AND advance_row.status =
                    'confirmed'
                AND advance_row.method = 'cash'
                AND advance_row.cash_register_session_id =
                    NEW.cash_register_session_id
                AND advance_row.cash_register_id =
                    NEW.cash_register_id
                AND advance_row.financial_account_id =
                    NEW.financial_account_id
                AND advance_row.amount_minor =
                    NEW.amount_minor
                AND advance_row.currency_code =
                    NEW.currency_code
                AND advance_row.received_by_user_id =
                    NEW.recorded_by_user_id
        )
    )
)
OR (
    NEW.type <> 'customer_advance'
    AND NEW.customer_advance_id IS NOT NULL
)
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de caja no conserva el anticipo asociado.'
    );
END
SQL);
    }

    private function createMysqlAdvanceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_advances_guard_insert
BEFORE INSERT ON customer_advances
FOR EACH ROW
BEGIN
    IF NEW.status <> 'building'
        OR NEW.amount_minor < 1
        OR CHAR_LENGTH(
            NEW.currency_code
        ) <> 3
        OR BINARY NEW.currency_code
            <> BINARY UPPER(
                NEW.currency_code
            )
        OR NEW.method NOT IN (
            'cash',
            'debit_card',
            'credit_card',
            'bank_transfer',
            'digital_wallet',
            'other'
        )
        OR CHAR_LENGTH(
            TRIM(
                NEW.idempotency_key
            )
        ) = 0
        OR CHAR_LENGTH(
            NEW.idempotency_key
        ) > 180
        OR CHAR_LENGTH(
            NEW.fingerprint
        ) <> 64
        OR NEW.received_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM business_parties party
            INNER JOIN customers customer
                ON customer.business_party_id =
                    party.id
                AND customer.organization_id =
                    NEW.organization_id
                AND customer.active = 1
            WHERE party.id =
                    NEW.business_party_id
                AND party.organization_id =
                    NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
                AND membership.user_id =
                    NEW.received_by_user_id
                AND membership.active = 1
                AND membership.role IN (
                    'admin',
                    'operator'
                )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id =
                    NEW.financial_account_id
                AND account.organization_id =
                    NEW.organization_id
                AND account.active = 1
                AND BINARY account.currency_code =
                    BINARY NEW.currency_code
        )
        OR (
            NEW.method = 'cash'
            AND (
                NEW.cash_register_session_id
                    IS NULL
                OR NEW.cash_register_id
                    IS NULL
                OR NEW.reference IS NOT NULL
                OR (
                    NEW.tendered_amount_minor
                        IS NOT NULL
                    AND NEW.tendered_amount_minor
                        < NEW.amount_minor
                )
                OR (
                    NEW.tendered_amount_minor
                        IS NULL
                    AND NEW.change_amount_minor
                        IS NOT NULL
                )
                OR (
                    NEW.tendered_amount_minor
                        IS NOT NULL
                    AND NEW.change_amount_minor
                        IS NULL
                )
                OR (
                    NEW.tendered_amount_minor
                        IS NOT NULL
                    AND NEW.change_amount_minor
                        <> NEW.tendered_amount_minor
                            - NEW.amount_minor
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM cash_register_sessions session
                    INNER JOIN cash_registers register_row
                        ON register_row.id =
                            session.cash_register_id
                    INNER JOIN financial_accounts account
                        ON account.id =
                            register_row.financial_account_id
                    WHERE session.id =
                            NEW.cash_register_session_id
                        AND session.organization_id =
                            NEW.organization_id
                        AND session.status = 'open'
                        AND session.opened_by_user_id =
                            NEW.received_by_user_id
                        AND session.cash_register_id =
                            NEW.cash_register_id
                        AND BINARY session.currency_code =
                            BINARY NEW.currency_code
                        AND register_row.organization_id =
                            NEW.organization_id
                        AND register_row.active = 1
                        AND register_row.financial_account_id =
                            NEW.financial_account_id
                        AND account.organization_id =
                            NEW.organization_id
                        AND account.active = 1
                        AND account.type =
                            'cash_box'
                        AND BINARY account.currency_code =
                            BINARY NEW.currency_code
                )
            )
        )
        OR (
            NEW.method <> 'cash'
            AND (
                NEW.cash_register_session_id
                    IS NOT NULL
                OR NEW.cash_register_id
                    IS NOT NULL
                OR NEW.tendered_amount_minor
                    IS NOT NULL
                OR NEW.change_amount_minor
                    IS NOT NULL
                OR CHAR_LENGTH(
                    TRIM(
                        COALESCE(
                            NEW.reference,
                            ''
                        )
                    )
                ) = 0
                OR EXISTS (
                    SELECT 1
                    FROM financial_accounts account
                    WHERE account.id =
                            NEW.financial_account_id
                        AND account.type IN (
                            'cash_box',
                            'cash_reserve'
                        )
                )
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El anticipo no conserva cliente, cuenta, medio, importe o autoridad validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_advances_guard_update
BEFORE UPDATE ON customer_advances
FOR EACH ROW
BEGIN
    IF OLD.status <> 'building'
        OR NEW.status <> 'confirmed'
        OR NEW.organization_id
            <> OLD.organization_id
        OR BINARY NEW.public_id
            <> BINARY OLD.public_id
        OR NEW.business_party_id
            <> OLD.business_party_id
        OR NEW.financial_account_id
            <> OLD.financial_account_id
        OR COALESCE(
            NEW.cash_register_session_id,
            0
        ) <> COALESCE(
            OLD.cash_register_session_id,
            0
        )
        OR COALESCE(
            NEW.cash_register_id,
            0
        ) <> COALESCE(
            OLD.cash_register_id,
            0
        )
        OR BINARY NEW.method
            <> BINARY OLD.method
        OR BINARY NEW.currency_code
            <> BINARY OLD.currency_code
        OR NEW.amount_minor
            <> OLD.amount_minor
        OR COALESCE(
            NEW.tendered_amount_minor,
            0
        ) <> COALESCE(
            OLD.tendered_amount_minor,
            0
        )
        OR COALESCE(
            NEW.change_amount_minor,
            0
        ) <> COALESCE(
            OLD.change_amount_minor,
            0
        )
        OR BINARY COALESCE(
            NEW.reference,
            ''
        ) <> BINARY COALESCE(
            OLD.reference,
            ''
        )
        OR BINARY COALESCE(
            NEW.notes,
            ''
        ) <> BINARY COALESCE(
            OLD.notes,
            ''
        )
        OR NEW.received_by_user_id
            <> OLD.received_by_user_id
        OR NEW.received_at
            <> OLD.received_at
        OR BINARY NEW.idempotency_key
            <> BINARY OLD.idempotency_key
        OR BINARY NEW.fingerprint
            <> BINARY OLD.fingerprint
        OR NEW.created_at
            <> OLD.created_at
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Un anticipo solo puede confirmarse sin reescribir sus datos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_advances_guard_delete
BEFORE DELETE ON customer_advances
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un anticipo no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_customer_advance_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF (
        NEW.type = 'customer_advance'
        AND (
            NEW.direction <> 'in'
            OR NEW.customer_advance_id
                IS NULL
            OR NEW.commerce_payment_id
                IS NOT NULL
            OR NEW.customer_collection_id
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
                FROM customer_advances advance_row
                WHERE advance_row.id =
                        NEW.customer_advance_id
                    AND advance_row.organization_id =
                        NEW.organization_id
                    AND advance_row.status =
                        'confirmed'
                    AND advance_row.method =
                        'cash'
                    AND advance_row.cash_register_session_id =
                        NEW.cash_register_session_id
                    AND advance_row.cash_register_id =
                        NEW.cash_register_id
                    AND advance_row.financial_account_id =
                        NEW.financial_account_id
                    AND advance_row.amount_minor =
                        NEW.amount_minor
                    AND BINARY advance_row.currency_code =
                        BINARY NEW.currency_code
                    AND advance_row.received_by_user_id =
                        NEW.recorded_by_user_id
            )
        )
    )
    OR (
        NEW.type <> 'customer_advance'
        AND NEW.customer_advance_id
            IS NOT NULL
    )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de caja no conserva el anticipo asociado.';
    END IF;
END
SQL);
    }
};
