<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLLECTION_UPDATE =
        'customer_collections_guard_update';
    private const COLLECTION_OVERPAYMENT_INSERT =
        'customer_collections_overpayment_guard_insert';
    private const CREDIT_ALLOCATION_INSERT =
        'customer_credit_allocations_guard_insert';
    private const CREDIT_ALLOCATION_UPDATE =
        'customer_credit_allocations_guard_update';
    private const CREDIT_ALLOCATION_DELETE =
        'customer_credit_allocations_guard_delete';

    public function up(): void
    {
        $this->addColumns();
        $this->replaceCollectionConfirmationGuard();
        $this->replaceCreditAllocationGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.6b conserva cobranzas y saldo a favor por sobrepago append-only; no admite rollback automático.'
        );
    }

    private function addColumns(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "customer_collections"
ADD COLUMN "retain_excess_as_credit"
INTEGER NOT NULL DEFAULT 0
SQL);

            DB::statement(<<<'SQL'
ALTER TABLE "customer_credit_consumption_allocations"
ADD COLUMN "customer_collection_id"
INTEGER NULL
REFERENCES "customer_collections" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE INDEX
"customer_credit_allocations_collection_index"
ON "customer_credit_consumption_allocations"
("customer_collection_id")
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
                'customer_collections',
                function (
                    Blueprint $table
                ): void {
                    $table->boolean(
                        'retain_excess_as_credit'
                    )
                        ->default(false)
                        ->after('amount_minor');
                }
            );

            Schema::table(
                'customer_credit_consumption_allocations',
                function (
                    Blueprint $table
                ): void {
                    $table->foreignId(
                        'customer_collection_id'
                    )
                        ->nullable()
                        ->after('customer_advance_id')
                        ->constrained(
                            'customer_collections'
                        )
                        ->restrictOnDelete();

                    $table->index(
                        'customer_collection_id',
                        'customer_credit_allocations_collection_index'
                    );
                }
            );

            return;
        }

        throw new LogicException(
            "P9.6b no implementa extensión de columnas para {$driver}."
        );
    }

    private function replaceCollectionConfirmationGuard():
        void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS '
            .self::COLLECTION_OVERPAYMENT_INSERT
        );
        DB::unprepared(
            'DROP TRIGGER IF EXISTS '
            .self::COLLECTION_UPDATE
        );

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_overpayment_guard_insert
BEFORE INSERT ON customer_collections
WHEN NEW.retain_excess_as_credit NOT IN (0, 1)
BEGIN
    SELECT RAISE(
        ABORT,
        'La confirmación de saldo a favor por excedente no es válida.'
    );
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_update
BEFORE UPDATE ON customer_collections
WHEN OLD.status <> 'building'
    OR NEW.status <> 'confirmed'
    OR NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.business_party_id <> OLD.business_party_id
    OR NEW.financial_account_id <> OLD.financial_account_id
    OR COALESCE(NEW.cash_register_session_id, 0) <>
        COALESCE(OLD.cash_register_session_id, 0)
    OR COALESCE(NEW.cash_register_id, 0) <>
        COALESCE(OLD.cash_register_id, 0)
    OR NEW.method <> OLD.method
    OR NEW.currency_code <> OLD.currency_code
    OR NEW.amount_minor <> OLD.amount_minor
    OR NEW.retain_excess_as_credit <>
        OLD.retain_excess_as_credit
    OR COALESCE(NEW.tendered_amount_minor, 0) <>
        COALESCE(OLD.tendered_amount_minor, 0)
    OR COALESCE(NEW.change_amount_minor, 0) <>
        COALESCE(OLD.change_amount_minor, 0)
    OR COALESCE(NEW.reference, '') <>
        COALESCE(OLD.reference, '')
    OR COALESCE(NEW.notes, '') <>
        COALESCE(OLD.notes, '')
    OR NEW.received_by_user_id <> OLD.received_by_user_id
    OR NEW.collected_at <> OLD.collected_at
    OR NEW.idempotency_key <> OLD.idempotency_key
    OR NEW.fingerprint <> OLD.fingerprint
    OR NEW.created_at <> OLD.created_at
    OR NOT EXISTS (
        SELECT 1
        FROM customer_collection_allocations allocation
        WHERE allocation.customer_collection_id = OLD.id
    )
    OR NEW.amount_minor < COALESCE((
        SELECT SUM(allocation.amount_minor)
        FROM customer_collection_allocations allocation
        WHERE allocation.customer_collection_id = OLD.id
    ), 0)
    OR (
        NEW.amount_minor = COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM customer_collection_allocations allocation
            WHERE allocation.customer_collection_id = OLD.id
        ), 0)
        AND NEW.retain_excess_as_credit <> 0
    )
    OR (
        NEW.amount_minor > COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM customer_collection_allocations allocation
            WHERE allocation.customer_collection_id = OLD.id
        ), 0)
        AND NEW.retain_excess_as_credit <> 1
    )
    OR EXISTS (
        SELECT 1
        FROM customer_collection_allocations current_allocation
        INNER JOIN customer_receivables receivable
            ON receivable.id =
                current_allocation.customer_receivable_id
        WHERE current_allocation.customer_collection_id =
                OLD.id
            AND (
                current_allocation.organization_id <>
                    OLD.organization_id
                OR receivable.organization_id <>
                    OLD.organization_id
                OR receivable.business_party_id <>
                    OLD.business_party_id
                OR receivable.currency_code <>
                    OLD.currency_code
                OR (
                    COALESCE((
                        SELECT SUM(previous.amount_minor)
                        FROM customer_collection_allocations previous
                        INNER JOIN customer_collections previous_collection
                            ON previous_collection.id =
                                previous.customer_collection_id
                        WHERE previous.customer_receivable_id =
                            receivable.id
                            AND previous.organization_id =
                                OLD.organization_id
                            AND previous_collection.status =
                                'confirmed'
                            AND previous_collection.id <> OLD.id
                    ), 0)
                    +
                    COALESCE((
                        SELECT SUM(same_collection.amount_minor)
                        FROM customer_collection_allocations same_collection
                        WHERE same_collection.customer_collection_id =
                            OLD.id
                            AND same_collection.customer_receivable_id =
                                receivable.id
                    ), 0)
                    > receivable.amount_minor
                )
            )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La cobranza no puede confirmarse sin aplicaciones válidas y confirmación explícita del excedente.'
    );
END
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
            DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_overpayment_guard_insert
BEFORE INSERT ON customer_collections
FOR EACH ROW
BEGIN
    IF NEW.retain_excess_as_credit NOT IN (0, 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La confirmacion de saldo a favor por excedente no es valida.';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_update
BEFORE UPDATE ON customer_collections
FOR EACH ROW
BEGIN
    DECLARE v_applied BIGINT UNSIGNED DEFAULT 0;

    SELECT COALESCE(
        SUM(allocation.amount_minor),
        0
    )
      INTO v_applied
      FROM customer_collection_allocations allocation
     WHERE allocation.customer_collection_id =
        OLD.id;

    IF OLD.status <> 'building'
        OR NEW.status <> 'confirmed'
        OR NEW.organization_id <> OLD.organization_id
        OR BINARY NEW.public_id <> BINARY OLD.public_id
        OR NEW.business_party_id <>
            OLD.business_party_id
        OR NEW.financial_account_id <>
            OLD.financial_account_id
        OR COALESCE(NEW.cash_register_session_id, 0) <>
            COALESCE(OLD.cash_register_session_id, 0)
        OR COALESCE(NEW.cash_register_id, 0) <>
            COALESCE(OLD.cash_register_id, 0)
        OR BINARY NEW.method <> BINARY OLD.method
        OR BINARY NEW.currency_code <>
            BINARY OLD.currency_code
        OR NEW.amount_minor <> OLD.amount_minor
        OR NEW.retain_excess_as_credit <>
            OLD.retain_excess_as_credit
        OR COALESCE(NEW.tendered_amount_minor, 0) <>
            COALESCE(OLD.tendered_amount_minor, 0)
        OR COALESCE(NEW.change_amount_minor, 0) <>
            COALESCE(OLD.change_amount_minor, 0)
        OR BINARY COALESCE(NEW.reference, '') <>
            BINARY COALESCE(OLD.reference, '')
        OR BINARY COALESCE(NEW.notes, '') <>
            BINARY COALESCE(OLD.notes, '')
        OR NEW.received_by_user_id <>
            OLD.received_by_user_id
        OR NEW.collected_at <> OLD.collected_at
        OR BINARY NEW.idempotency_key <>
            BINARY OLD.idempotency_key
        OR BINARY NEW.fingerprint <>
            BINARY OLD.fingerprint
        OR NEW.created_at <> OLD.created_at
        OR v_applied <= 0
        OR NEW.amount_minor < v_applied
        OR (
            NEW.amount_minor = v_applied
            AND NEW.retain_excess_as_credit <> 0
        )
        OR (
            NEW.amount_minor > v_applied
            AND NEW.retain_excess_as_credit <> 1
        )
        OR EXISTS (
            SELECT 1
            FROM customer_collection_allocations current_allocation
            INNER JOIN customer_receivables receivable
                ON receivable.id =
                    current_allocation.customer_receivable_id
            WHERE current_allocation.customer_collection_id =
                    OLD.id
                AND (
                    current_allocation.organization_id <>
                        OLD.organization_id
                    OR receivable.organization_id <>
                        OLD.organization_id
                    OR receivable.business_party_id <>
                        OLD.business_party_id
                    OR BINARY receivable.currency_code <>
                        BINARY OLD.currency_code
                    OR (
                        COALESCE((
                            SELECT SUM(previous.amount_minor)
                            FROM customer_collection_allocations previous
                            INNER JOIN customer_collections previous_collection
                                ON previous_collection.id =
                                    previous.customer_collection_id
                            WHERE previous.customer_receivable_id =
                                    receivable.id
                                AND previous.organization_id =
                                    OLD.organization_id
                                AND previous_collection.status =
                                    'confirmed'
                                AND previous_collection.id <>
                                    OLD.id
                        ), 0)
                        +
                        COALESCE((
                            SELECT SUM(same_collection.amount_minor)
                            FROM customer_collection_allocations same_collection
                            WHERE same_collection.customer_collection_id =
                                OLD.id
                                AND same_collection.customer_receivable_id =
                                    receivable.id
                        ), 0)
                        > receivable.amount_minor
                    )
                )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La cobranza no puede confirmarse sin aplicaciones validas y confirmacion explicita del excedente.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "P9.6b no implementa guard de cobranza para {$driver}."
        );
    }

    private function replaceCreditAllocationGuards(): void
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
            "P9.6b no implementa guards de saldo a favor para {$driver}."
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
                + (NEW.customer_collection_id IS NOT NULL)
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
        WHEN NEW.customer_collection_id IS NOT NULL
         AND NOT EXISTS (
            SELECT 1
            FROM customer_collections collection
            JOIN customer_credit_consumptions consumption
              ON consumption.id =
                    NEW.customer_credit_consumption_id
            WHERE collection.id =
                    NEW.customer_collection_id
              AND collection.organization_id =
                    NEW.organization_id
              AND collection.business_party_id =
                    consumption.business_party_id
              AND collection.currency_code =
                    consumption.currency_code
              AND collection.status = 'confirmed'
              AND collection.retain_excess_as_credit = 1
              AND collection.amount_minor > COALESCE((
                    SELECT SUM(debt_allocation.amount_minor)
                    FROM customer_collection_allocations debt_allocation
                    WHERE debt_allocation.customer_collection_id =
                        collection.id
              ), 0)
        )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_collection_source_invalid'
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
        WHEN NEW.customer_collection_id IS NOT NULL
         AND (
            COALESCE((
                SELECT SUM(a.amount_minor)
                FROM customer_credit_consumption_allocations a
                WHERE a.customer_collection_id =
                    NEW.customer_collection_id
            ), 0)
            + NEW.amount_minor
         ) > (
            SELECT
                collection.amount_minor
                - COALESCE((
                    SELECT SUM(debt_allocation.amount_minor)
                    FROM customer_collection_allocations debt_allocation
                    WHERE debt_allocation.customer_collection_id =
                        collection.id
                ), 0)
            FROM customer_collections collection
            WHERE collection.id =
                NEW.customer_collection_id
         )
        THEN RAISE(
            ABORT,
            'customer_credit_allocation_collection_overdraw'
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
            + (NEW.customer_collection_id IS NOT NULL)
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
        SELECT COALESCE(MAX(g.amount_minor), 0)
          INTO v_source_amount
          FROM customer_credit_grants g
         WHERE g.id = NEW.customer_credit_grant_id
           AND g.organization_id = NEW.organization_id
           AND g.business_party_id = v_party
           AND BINARY g.currency_code =
                BINARY v_currency;

        SELECT COALESCE(SUM(a.amount_minor), 0)
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations a
         WHERE a.customer_credit_grant_id =
            NEW.customer_credit_grant_id;

    ELSEIF
        NEW.commerce_post_sale_exchange_credit_grant_id
            IS NOT NULL
    THEN
        SELECT COALESCE(MAX(g.amount_minor), 0)
          INTO v_source_amount
          FROM commerce_post_sale_exchange_credit_grants g
         WHERE g.id =
                NEW.commerce_post_sale_exchange_credit_grant_id
           AND g.organization_id = NEW.organization_id
           AND g.business_party_id = v_party
           AND BINARY g.currency_code =
                BINARY v_currency;

        SELECT COALESCE(SUM(a.amount_minor), 0)
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations a
         WHERE a.commerce_post_sale_exchange_credit_grant_id =
            NEW.commerce_post_sale_exchange_credit_grant_id;

    ELSEIF NEW.customer_advance_id IS NOT NULL THEN
        SELECT COALESCE(MAX(a.amount_minor), 0)
          INTO v_source_amount
          FROM customer_advances a
         WHERE a.id = NEW.customer_advance_id
           AND a.organization_id = NEW.organization_id
           AND a.business_party_id = v_party
           AND BINARY a.currency_code =
                BINARY v_currency
           AND a.status = 'confirmed';

        SELECT COALESCE(SUM(allocation.amount_minor), 0)
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations allocation
         WHERE allocation.customer_advance_id =
            NEW.customer_advance_id;

    ELSE
        SELECT COALESCE(
            collection.amount_minor
            - COALESCE((
                SELECT SUM(debt_allocation.amount_minor)
                FROM customer_collection_allocations debt_allocation
                WHERE debt_allocation.customer_collection_id =
                    collection.id
            ), 0),
            0
        )
          INTO v_source_amount
          FROM customer_collections collection
         WHERE collection.id =
                NEW.customer_collection_id
           AND collection.organization_id =
                NEW.organization_id
           AND collection.business_party_id =
                v_party
           AND BINARY collection.currency_code =
                BINARY v_currency
           AND collection.status = 'confirmed'
           AND collection.retain_excess_as_credit = 1
         LIMIT 1;

        SELECT COALESCE(SUM(allocation.amount_minor), 0)
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations allocation
         WHERE allocation.customer_collection_id =
            NEW.customer_collection_id;
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
};
