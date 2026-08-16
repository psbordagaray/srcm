<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('business_party_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('commerce_sale_id')
                ->constrained('commerce_sales')
                ->restrictOnDelete();
            $table->unsignedInteger('payment_position');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('consumed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('consumed_at');
            $table->string('idempotency_key', 180);
            $table->char('fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'customer_credit_consumptions_org_idem_unique'
            );
            $table->unique(
                ['commerce_sale_id', 'payment_position'],
                'customer_credit_consumptions_sale_position_unique'
            );
            $table->index(
                [
                    'organization_id',
                    'business_party_id',
                    'currency_code',
                    'consumed_at',
                ],
                'customer_credit_consumptions_party_currency_index'
            );
        });

        Schema::create(
            'customer_credit_consumption_allocations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->foreignId('customer_credit_consumption_id')
                    ->constrained('customer_credit_consumptions')
                    ->restrictOnDelete();
                $table->unsignedInteger('sequence');
                $table->foreignId('customer_credit_grant_id')
                    ->nullable()
                    ->constrained('customer_credit_grants')
                    ->restrictOnDelete();
                $table->foreignId('commerce_post_sale_exchange_credit_grant_id')
                    ->nullable()
                    ->constrained('commerce_post_sale_exchange_credit_grants')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('amount_minor');
                $table->char('fingerprint', 64);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['customer_credit_consumption_id', 'sequence'],
                    'customer_credit_allocations_consumption_sequence_unique'
                );
                $table->index(
                    'customer_credit_grant_id',
                    'customer_credit_allocations_standard_grant_index'
                );
                $table->index(
                    'commerce_post_sale_exchange_credit_grant_id',
                    'customer_credit_allocations_exchange_grant_index'
                );
            }
        );

        $this->installGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'El ledger de consumo de saldos a favor es append-only y no admite rollback destructivo.'
        );
    }

    private function installGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installMysqlGuards();

            return;
        }

        throw new LogicException(
            'El ledger de consumo de saldos a favor requiere guards SQL explícitos para este motor.'
        );
    }

    private function installSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_consumptions_guard_insert
BEFORE INSERT ON customer_credit_consumptions
BEGIN
    SELECT CASE
        WHEN NEW.amount_minor <= 0
        THEN RAISE(ABORT, 'customer_credit_consumption_amount_invalid')
    END;

    SELECT CASE
        WHEN NEW.payment_position <= 0
        THEN RAISE(ABORT, 'customer_credit_consumption_position_invalid')
    END;

    SELECT CASE
        WHEN NEW.currency_code <> UPPER(NEW.currency_code)
             OR LENGTH(NEW.currency_code) <> 3
        THEN RAISE(ABORT, 'customer_credit_consumption_currency_invalid')
    END;

    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM commerce_sales s
            WHERE s.id = NEW.commerce_sale_id
              AND s.organization_id = NEW.organization_id
              AND s.customer_business_party_id = NEW.business_party_id
              AND s.currency_code = NEW.currency_code
              AND s.status = 'building'
        )
        THEN RAISE(ABORT, 'customer_credit_consumption_sale_invalid')
    END;

    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM business_parties p
            WHERE p.id = NEW.business_party_id
              AND p.organization_id = NEW.organization_id
        )
        THEN RAISE(ABORT, 'customer_credit_consumption_party_invalid')
    END;

    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM organization_memberships m
            WHERE m.organization_id = NEW.organization_id
              AND m.user_id = NEW.consumed_by_user_id
              AND m.active = 1
              AND m.role IN ('admin', 'operator')
        )
        THEN RAISE(ABORT, 'customer_credit_consumption_actor_invalid')
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_consumptions_guard_update
BEFORE UPDATE ON customer_credit_consumptions
BEGIN
    SELECT RAISE(ABORT, 'customer_credit_consumption_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_consumptions_guard_delete
BEFORE DELETE ON customer_credit_consumptions
BEGIN
    SELECT RAISE(ABORT, 'customer_credit_consumption_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_insert
BEFORE INSERT ON customer_credit_consumption_allocations
BEGIN
    SELECT CASE
        WHEN NEW.amount_minor <= 0 OR NEW.sequence <= 0
        THEN RAISE(ABORT, 'customer_credit_allocation_shape_invalid')
    END;

    SELECT CASE
        WHEN (
            (NEW.customer_credit_grant_id IS NULL)
            =
            (NEW.commerce_post_sale_exchange_credit_grant_id IS NULL)
        )
        THEN RAISE(ABORT, 'customer_credit_allocation_source_invalid')
    END;

    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM customer_credit_consumptions c
            WHERE c.id = NEW.customer_credit_consumption_id
              AND c.organization_id = NEW.organization_id
        )
        THEN RAISE(ABORT, 'customer_credit_allocation_consumption_invalid')
    END;

    SELECT CASE
        WHEN NEW.customer_credit_grant_id IS NOT NULL
         AND NOT EXISTS (
            SELECT 1
            FROM customer_credit_grants g
            JOIN customer_credit_consumptions c
              ON c.id = NEW.customer_credit_consumption_id
            WHERE g.id = NEW.customer_credit_grant_id
              AND g.organization_id = NEW.organization_id
              AND g.business_party_id = c.business_party_id
              AND g.currency_code = c.currency_code
        )
        THEN RAISE(ABORT, 'customer_credit_allocation_standard_source_invalid')
    END;

    SELECT CASE
        WHEN NEW.commerce_post_sale_exchange_credit_grant_id IS NOT NULL
         AND NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_credit_grants g
            JOIN customer_credit_consumptions c
              ON c.id = NEW.customer_credit_consumption_id
            WHERE g.id = NEW.commerce_post_sale_exchange_credit_grant_id
              AND g.organization_id = NEW.organization_id
              AND g.business_party_id = c.business_party_id
              AND g.currency_code = c.currency_code
        )
        THEN RAISE(ABORT, 'customer_credit_allocation_exchange_source_invalid')
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
            WHERE g.id = NEW.customer_credit_grant_id
         )
        THEN RAISE(ABORT, 'customer_credit_allocation_standard_overdraw')
    END;

    SELECT CASE
        WHEN NEW.commerce_post_sale_exchange_credit_grant_id IS NOT NULL
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
        THEN RAISE(ABORT, 'customer_credit_allocation_exchange_overdraw')
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
            WHERE c.id = NEW.customer_credit_consumption_id
        )
        THEN RAISE(ABORT, 'customer_credit_allocation_consumption_overdraw')
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_update
BEFORE UPDATE ON customer_credit_consumption_allocations
BEGIN
    SELECT RAISE(ABORT, 'customer_credit_allocation_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_delete
BEFORE DELETE ON customer_credit_consumption_allocations
BEGIN
    SELECT RAISE(ABORT, 'customer_credit_allocation_immutable');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_account_credit_guard_insert
BEFORE INSERT ON commerce_payments
WHEN NEW.method = 'account_credit'
BEGIN
    SELECT CASE
        WHEN NEW.financial_account_id IS NOT NULL
          OR NEW.tendered_amount_minor IS NOT NULL
          OR NEW.change_amount_minor IS NOT NULL
          OR NEW.card_brand IS NOT NULL
          OR NEW.card_network IS NOT NULL
          OR NEW.card_last4 IS NOT NULL
          OR NEW.installments IS NOT NULL
          OR NEW.processor IS NOT NULL
          OR NEW.external_operation_id IS NOT NULL
          OR NEW.authorization_code IS NOT NULL
          OR NEW.provider_status IS NOT NULL
        THEN RAISE(ABORT, 'account_credit_payment_shape_invalid')
    END;

    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM customer_credit_consumptions c
            WHERE c.organization_id = NEW.organization_id
              AND c.commerce_sale_id = NEW.commerce_sale_id
              AND c.payment_position = NEW.position
              AND c.public_id = NEW.reference
              AND c.amount_minor = NEW.amount_minor
              AND (
                  SELECT COALESCE(SUM(a.amount_minor), 0)
                  FROM customer_credit_consumption_allocations a
                  WHERE a.customer_credit_consumption_id = c.id
              ) = c.amount_minor
        )
        THEN RAISE(ABORT, 'account_credit_payment_unbacked')
    END;
END;
SQL);
    }

    private function installMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_consumptions_guard_insert
BEFORE INSERT ON customer_credit_consumptions
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
       OR NEW.payment_position <= 0
       OR NEW.currency_code <> UPPER(NEW.currency_code)
       OR CHAR_LENGTH(NEW.currency_code) <> 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_consumption_shape_invalid';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM commerce_sales s
        WHERE s.id = NEW.commerce_sale_id
          AND s.organization_id = NEW.organization_id
          AND s.customer_business_party_id = NEW.business_party_id
          AND s.currency_code = NEW.currency_code
          AND s.status = 'building'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_consumption_sale_invalid';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM organization_memberships m
        WHERE m.organization_id = NEW.organization_id
          AND m.user_id = NEW.consumed_by_user_id
          AND m.active = 1
          AND m.role IN ('admin', 'operator')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_consumption_actor_invalid';
    END IF;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_consumptions_guard_update
BEFORE UPDATE ON customer_credit_consumptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'customer_credit_consumption_immutable';
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_consumptions_guard_delete
BEFORE DELETE ON customer_credit_consumptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'customer_credit_consumption_immutable';
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_insert
BEFORE INSERT ON customer_credit_consumption_allocations
FOR EACH ROW
BEGIN
    DECLARE v_consumption_amount BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_consumed BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_source_amount BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_source_consumed BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_party BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_currency CHAR(3);

    IF NEW.amount_minor <= 0
       OR NEW.sequence <= 0
       OR (
            (NEW.customer_credit_grant_id IS NULL)
            =
            (NEW.commerce_post_sale_exchange_credit_grant_id IS NULL)
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_allocation_shape_invalid';
    END IF;

    SELECT c.amount_minor, c.business_party_id, c.currency_code
      INTO v_consumption_amount, v_party, v_currency
      FROM customer_credit_consumptions c
     WHERE c.id = NEW.customer_credit_consumption_id
       AND c.organization_id = NEW.organization_id
     LIMIT 1;

    IF v_consumption_amount = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_allocation_consumption_invalid';
    END IF;

    SELECT COALESCE(SUM(a.amount_minor), 0)
      INTO v_consumed
      FROM customer_credit_consumption_allocations a
     WHERE a.customer_credit_consumption_id =
        NEW.customer_credit_consumption_id;

    IF v_consumed + NEW.amount_minor > v_consumption_amount THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_allocation_consumption_overdraw';
    END IF;

    IF NEW.customer_credit_grant_id IS NOT NULL THEN
        SELECT COALESCE(MAX(g.amount_minor), 0)
          INTO v_source_amount
          FROM customer_credit_grants g
         WHERE g.id = NEW.customer_credit_grant_id
           AND g.organization_id = NEW.organization_id
           AND g.business_party_id = v_party
           AND g.currency_code = v_currency;

        SELECT COALESCE(SUM(a.amount_minor), 0)
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations a
         WHERE a.customer_credit_grant_id =
            NEW.customer_credit_grant_id;
    ELSE
        SELECT COALESCE(MAX(g.amount_minor), 0)
          INTO v_source_amount
          FROM commerce_post_sale_exchange_credit_grants g
         WHERE g.id =
            NEW.commerce_post_sale_exchange_credit_grant_id
           AND g.organization_id = NEW.organization_id
           AND g.business_party_id = v_party
           AND g.currency_code = v_currency;

        SELECT COALESCE(SUM(a.amount_minor), 0)
          INTO v_source_consumed
          FROM customer_credit_consumption_allocations a
         WHERE a.commerce_post_sale_exchange_credit_grant_id =
            NEW.commerce_post_sale_exchange_credit_grant_id;
    END IF;

    IF v_source_amount = 0
       OR v_source_consumed + NEW.amount_minor > v_source_amount THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'customer_credit_allocation_source_overdraw';
    END IF;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_update
BEFORE UPDATE ON customer_credit_consumption_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'customer_credit_allocation_immutable';
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_allocations_guard_delete
BEFORE DELETE ON customer_credit_consumption_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'customer_credit_allocation_immutable';
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_account_credit_guard_insert
BEFORE INSERT ON commerce_payments
FOR EACH ROW
BEGIN
    DECLARE v_consumption_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_consumption_amount BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_allocated BIGINT UNSIGNED DEFAULT 0;

    IF NEW.method = 'account_credit' THEN
        IF NEW.financial_account_id IS NOT NULL
           OR NEW.tendered_amount_minor IS NOT NULL
           OR NEW.change_amount_minor IS NOT NULL
           OR NEW.card_brand IS NOT NULL
           OR NEW.card_network IS NOT NULL
           OR NEW.card_last4 IS NOT NULL
           OR NEW.installments IS NOT NULL
           OR NEW.processor IS NOT NULL
           OR NEW.external_operation_id IS NOT NULL
           OR NEW.authorization_code IS NOT NULL
           OR NEW.provider_status IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'account_credit_payment_shape_invalid';
        END IF;

        SELECT COALESCE(MAX(c.id), 0),
               COALESCE(MAX(c.amount_minor), 0)
          INTO v_consumption_id, v_consumption_amount
          FROM customer_credit_consumptions c
         WHERE c.organization_id = NEW.organization_id
           AND c.commerce_sale_id = NEW.commerce_sale_id
           AND c.payment_position = NEW.position
           AND c.public_id = NEW.reference
           AND c.amount_minor = NEW.amount_minor;

        IF v_consumption_id = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'account_credit_payment_unbacked';
        END IF;

        SELECT COALESCE(SUM(a.amount_minor), 0)
          INTO v_allocated
          FROM customer_credit_consumption_allocations a
         WHERE a.customer_credit_consumption_id =
            v_consumption_id;

        IF v_allocated <> v_consumption_amount THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'account_credit_payment_unbacked';
        END IF;
    END IF;
END;
SQL);
    }
};
