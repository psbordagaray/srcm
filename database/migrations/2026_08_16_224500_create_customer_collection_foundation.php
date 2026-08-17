<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLLECTION_INSERT =
        'customer_collections_guard_insert';
    private const COLLECTION_UPDATE =
        'customer_collections_guard_update';
    private const COLLECTION_DELETE =
        'customer_collections_guard_delete';
    private const ALLOCATION_INSERT =
        'customer_collection_allocations_guard_insert';
    private const ALLOCATION_UPDATE =
        'customer_collection_allocations_guard_update';
    private const ALLOCATION_DELETE =
        'customer_collection_allocations_guard_delete';
    private const CASH_MAIN =
        'cash_movements_guard_insert';
    private const CASH_COLLECTION =
        'cash_movements_customer_collection_guard_insert';

    public function up(): void
    {
        Schema::create(
            'customer_collections',
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
                $table->foreignId('cash_register_session_id')
                    ->nullable()
                    ->constrained('cash_register_sessions')
                    ->restrictOnDelete();
                $table->foreignId('cash_register_id')
                    ->nullable()
                    ->constrained('cash_registers')
                    ->restrictOnDelete();
                $table->string('status', 24);
                $table->string('method', 40);
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('amount_minor');
                $table->unsignedBigInteger(
                    'tendered_amount_minor'
                )->nullable();
                $table->unsignedBigInteger(
                    'change_amount_minor'
                )->nullable();
                $table->string('reference', 255)->nullable();
                $table->string('notes', 1000)->nullable();
                $table->foreignId('received_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('collected_at');
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_collections_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'customer_collections_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'collected_at',
                    ],
                    'customer_collections_party_currency_index'
                );
            }
        );

        Schema::create(
            'customer_collection_allocations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger(
                    'customer_collection_id'
                );
                $table->unsignedBigInteger(
                    'customer_receivable_id'
                );
                $table->unsignedInteger('sequence');
                $table->unsignedBigInteger('amount_minor');
                $table->char('fingerprint', 64);
                $table->timestampTz('created_at');

                $table->unique(
                    [
                        'customer_collection_id',
                        'sequence',
                    ],
                    'customer_collection_allocations_sequence_unique'
                );
                $table->unique(
                    [
                        'customer_collection_id',
                        'customer_receivable_id',
                    ],
                    'customer_collection_allocations_receivable_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'customer_receivable_id',
                    ],
                    'customer_collection_allocations_receivable_index'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'customer_collection_id',
                    ],
                    'customer_collection_allocations_collection_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('customer_collections')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'customer_receivable_id',
                    ],
                    'customer_collection_allocations_receivable_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('customer_receivables')
                    ->restrictOnDelete();
            }
        );

        $this->addCashMovementLink();
        $this->extendCashMovementAllowedTypes();
        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.2 conserva cobranzas, aplicaciones y movimientos de caja append-only; no admite rollback automático.'
        );
    }

    private function addCashMovementLink(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "customer_collection_id"
INTEGER NULL
REFERENCES "customer_collections" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX
"cash_movements_customer_collection_unique"
ON "cash_movements" ("customer_collection_id")
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table(
                'cash_movements',
                function (Blueprint $table): void {
                    $table->foreignId(
                        'customer_collection_id'
                    )
                        ->nullable()
                        ->unique(
                            'cash_movements_customer_collection_unique'
                        )
                        ->after(
                            'post_sale_exchange_payment_id'
                        )
                        ->constrained(
                            'customer_collections'
                        )
                        ->restrictOnDelete();
                }
            );

            return;
        }

        throw new LogicException(
            "La extensión de caja P9.2 no está implementada para {$driver}."
        );
    }

    private function extendCashMovementAllowedTypes(): void
    {
        $driver = DB::getDriverName();

        $pattern =
            "/'sale_payment'\s*,\s*'security_drop'\s*,\s*'purchase_payment'\s*,\s*'post_sale_refund'\s*,\s*'post_sale_exchange_difference'/";

        $replacement =
            "'sale_payment', 'security_drop', 'purchase_payment', "
            ."'post_sale_refund', 'post_sale_exchange_difference', "
            ."'customer_collection'";

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
                    'P9.2 no encontró el guard vigente de cash_movements.'
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
                    'P9.2 no pudo extender exactamente una vez el guard SQLite de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::CASH_MAIN
            );
            DB::unprepared($extended);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'cash_movements_guard_insert'
SQL);

            $body = is_object($row)
                && isset($row->action_statement)
                && is_string($row->action_statement)
                    ? $row->action_statement
                    : null;

            if ($body === null) {
                throw new LogicException(
                    'P9.2 no encontró el guard MySQL vigente de cash_movements.'
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
                    'P9.2 no pudo extender exactamente una vez el guard MySQL de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::CASH_MAIN
            );
            DB::unprepared(
                'CREATE TRIGGER '.self::CASH_MAIN
                .' BEFORE INSERT ON cash_movements '
                .'FOR EACH ROW '.$extended
            );

            return;
        }

        throw new LogicException(
            "La extensión del guard de caja P9.2 no está implementada para {$driver}."
        );
    }

    private function createGuards(): void
    {
        foreach ([
            self::CASH_COLLECTION,
            self::ALLOCATION_DELETE,
            self::ALLOCATION_UPDATE,
            self::ALLOCATION_INSERT,
            self::COLLECTION_DELETE,
            self::COLLECTION_UPDATE,
            self::COLLECTION_INSERT,
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

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlGuards();

            return;
        }

        throw new LogicException(
            "La integridad P9.2 no está implementada para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_insert
BEFORE INSERT ON customer_collections
WHEN NEW.status <> 'building'
    OR NEW.amount_minor < 1
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code) <> NEW.currency_code
    OR NEW.method NOT IN (
        'cash',
        'debit_card',
        'credit_card',
        'bank_transfer',
        'digital_wallet',
        'other'
    )
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.collected_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM business_parties party
        INNER JOIN customers customer
            ON customer.business_party_id = party.id
            AND customer.organization_id = NEW.organization_id
            AND customer.active = 1
        WHERE party.id = NEW.business_party_id
            AND party.organization_id = NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id = NEW.organization_id
            AND membership.user_id = NEW.received_by_user_id
            AND membership.active = 1
            AND membership.role IN ('admin', 'operator')
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.active = 1
            AND account.currency_code = NEW.currency_code
    )
    OR (
        NEW.method = 'cash'
        AND (
            NEW.cash_register_session_id IS NULL
            OR NEW.cash_register_id IS NULL
            OR NEW.reference IS NOT NULL
            OR (
                NEW.tendered_amount_minor IS NOT NULL
                AND NEW.tendered_amount_minor < NEW.amount_minor
            )
            OR (
                NEW.tendered_amount_minor IS NULL
                AND NEW.change_amount_minor IS NOT NULL
            )
            OR (
                NEW.tendered_amount_minor IS NOT NULL
                AND NEW.change_amount_minor IS NULL
            )
            OR (
                NEW.tendered_amount_minor IS NOT NULL
                AND NEW.change_amount_minor <>
                    NEW.tendered_amount_minor - NEW.amount_minor
            )
            OR NOT EXISTS (
                SELECT 1
                FROM cash_register_sessions session
                INNER JOIN cash_registers register_row
                    ON register_row.id = session.cash_register_id
                INNER JOIN financial_accounts account
                    ON account.id = register_row.financial_account_id
                WHERE session.id = NEW.cash_register_session_id
                    AND session.organization_id = NEW.organization_id
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
                    AND account.type = 'cash_box'
                    AND account.currency_code =
                        NEW.currency_code
            )
        )
    )
    OR (
        NEW.method <> 'cash'
        AND (
            NEW.cash_register_session_id IS NOT NULL
            OR NEW.cash_register_id IS NOT NULL
            OR NEW.tendered_amount_minor IS NOT NULL
            OR NEW.change_amount_minor IS NOT NULL
            OR TRIM(COALESCE(NEW.reference, '')) = ''
            OR EXISTS (
                SELECT 1
                FROM financial_accounts account
                WHERE account.id = NEW.financial_account_id
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
        'La cobranza no conserva cliente, cuenta, medio, importe o autoridad válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collection_allocations_guard_insert
BEFORE INSERT ON customer_collection_allocations
WHEN NEW.sequence < 1
    OR NEW.amount_minor < 1
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM customer_collections collection
        INNER JOIN customer_receivables receivable
            ON receivable.id = NEW.customer_receivable_id
        WHERE collection.id = NEW.customer_collection_id
            AND collection.organization_id =
                NEW.organization_id
            AND collection.status = 'building'
            AND receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                collection.business_party_id
            AND receivable.currency_code =
                collection.currency_code
    )
    OR (
        COALESCE((
            SELECT SUM(existing.amount_minor)
            FROM customer_collection_allocations existing
            WHERE existing.customer_collection_id =
                NEW.customer_collection_id
        ), 0) + NEW.amount_minor
        >
        (
            SELECT collection.amount_minor
            FROM customer_collections collection
            WHERE collection.id =
                NEW.customer_collection_id
        )
    )
    OR (
        COALESCE((
            SELECT SUM(previous.amount_minor)
            FROM customer_collection_allocations previous
            INNER JOIN customer_collections previous_collection
                ON previous_collection.id =
                    previous.customer_collection_id
            WHERE previous.customer_receivable_id =
                NEW.customer_receivable_id
                AND previous.organization_id =
                    NEW.organization_id
                AND previous_collection.status =
                    'confirmed'
        ), 0)
        +
        COALESCE((
            SELECT SUM(current_allocation.amount_minor)
            FROM customer_collection_allocations current_allocation
            WHERE current_allocation.customer_collection_id =
                NEW.customer_collection_id
                AND current_allocation.customer_receivable_id =
                    NEW.customer_receivable_id
        ), 0)
        + NEW.amount_minor
        >
        (
            SELECT receivable.amount_minor
            FROM customer_receivables receivable
            WHERE receivable.id =
                NEW.customer_receivable_id
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La aplicación de cobranza supera la cobranza o el saldo de la deuda.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collection_allocations_guard_update
BEFORE UPDATE ON customer_collection_allocations
BEGIN
    SELECT RAISE(
        ABORT,
        'Una aplicación de cobranza es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collection_allocations_guard_delete
BEFORE DELETE ON customer_collection_allocations
BEGIN
    SELECT RAISE(
        ABORT,
        'Una aplicación de cobranza no puede eliminarse.'
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
    OR NEW.amount_minor <> COALESCE((
        SELECT SUM(allocation.amount_minor)
        FROM customer_collection_allocations allocation
        WHERE allocation.customer_collection_id = OLD.id
    ), 0)
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
        'La cobranza no puede confirmarse sin aplicaciones exactas y saldos válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_delete
BEFORE DELETE ON customer_collections
BEGIN
    SELECT RAISE(
        ABORT,
        'Una cobranza no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_customer_collection_guard_insert
BEFORE INSERT ON cash_movements
WHEN (
    NEW.type = 'customer_collection'
    AND (
        NEW.direction <> 'in'
    OR NEW.customer_collection_id IS NULL
    OR NEW.commerce_payment_id IS NOT NULL
    OR NEW.destination_financial_account_id IS NOT NULL
    OR NEW.cash_security_drop_request_id IS NOT NULL
    OR NEW.purchase_payment_execution_id IS NOT NULL
    OR NEW.post_sale_cash_refund_execution_id IS NOT NULL
    OR NEW.post_sale_exchange_payment_id IS NOT NULL
    OR NEW.reason_code IS NOT NULL
    OR NEW.note IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM customer_collections collection
        WHERE collection.id = NEW.customer_collection_id
            AND collection.organization_id =
                NEW.organization_id
            AND collection.status = 'confirmed'
            AND collection.method = 'cash'
            AND collection.cash_register_session_id =
                NEW.cash_register_session_id
            AND collection.cash_register_id =
                NEW.cash_register_id
            AND collection.financial_account_id =
                NEW.financial_account_id
            AND collection.amount_minor =
                NEW.amount_minor
            AND collection.currency_code =
                NEW.currency_code
            AND collection.received_by_user_id =
                NEW.recorded_by_user_id
        )
    )
)
OR (
    NEW.type <> 'customer_collection'
    AND NEW.customer_collection_id IS NOT NULL
)
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de caja no conserva la cobranza asociada.'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_insert
BEFORE INSERT ON customer_collections
FOR EACH ROW
BEGIN
    IF NEW.status <> 'building'
        OR NEW.amount_minor < 1
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR BINARY NEW.currency_code <>
            BINARY UPPER(NEW.currency_code)
        OR NEW.method NOT IN (
            'cash',
            'debit_card',
            'credit_card',
            'bank_transfer',
            'digital_wallet',
            'other'
        )
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.idempotency_key) > 180
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.collected_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM business_parties party
            INNER JOIN customers customer
                ON customer.business_party_id = party.id
                AND customer.organization_id =
                    NEW.organization_id
                AND customer.active = 1
            WHERE party.id = NEW.business_party_id
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
            WHERE account.id = NEW.financial_account_id
                AND account.organization_id =
                    NEW.organization_id
                AND account.active = 1
                AND account.currency_code =
                    NEW.currency_code
        )
        OR (
            NEW.method = 'cash'
            AND (
                NEW.cash_register_session_id IS NULL
                OR NEW.cash_register_id IS NULL
                OR NEW.reference IS NOT NULL
                OR (
                    NEW.tendered_amount_minor IS NOT NULL
                    AND NEW.tendered_amount_minor <
                        NEW.amount_minor
                )
                OR (
                    NEW.tendered_amount_minor IS NULL
                    AND NEW.change_amount_minor IS NOT NULL
                )
                OR (
                    NEW.tendered_amount_minor IS NOT NULL
                    AND NEW.change_amount_minor IS NULL
                )
                OR (
                    NEW.tendered_amount_minor IS NOT NULL
                    AND NEW.change_amount_minor <>
                        NEW.tendered_amount_minor -
                        NEW.amount_minor
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
                        AND account.type = 'cash_box'
                        AND account.currency_code =
                            NEW.currency_code
                )
            )
        )
        OR (
            NEW.method <> 'cash'
            AND (
                NEW.cash_register_session_id IS NOT NULL
                OR NEW.cash_register_id IS NOT NULL
                OR NEW.tendered_amount_minor IS NOT NULL
                OR NEW.change_amount_minor IS NOT NULL
                OR CHAR_LENGTH(
                    TRIM(COALESCE(NEW.reference, ''))
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
                'La cobranza no conserva cliente, cuenta, medio, importe o autoridad validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collection_allocations_guard_insert
BEFORE INSERT ON customer_collection_allocations
FOR EACH ROW
BEGIN
    IF NEW.sequence < 1
        OR NEW.amount_minor < 1
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM customer_collections collection
            INNER JOIN customer_receivables receivable
                ON receivable.id =
                    NEW.customer_receivable_id
            WHERE collection.id =
                    NEW.customer_collection_id
                AND collection.organization_id =
                    NEW.organization_id
                AND collection.status = 'building'
                AND receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    collection.business_party_id
                AND receivable.currency_code =
                    collection.currency_code
        )
        OR (
            COALESCE((
                SELECT SUM(existing.amount_minor)
                FROM customer_collection_allocations existing
                WHERE existing.customer_collection_id =
                    NEW.customer_collection_id
            ), 0) + NEW.amount_minor
            >
            (
                SELECT collection.amount_minor
                FROM customer_collections collection
                WHERE collection.id =
                    NEW.customer_collection_id
            )
        )
        OR (
            COALESCE((
                SELECT SUM(previous.amount_minor)
                FROM customer_collection_allocations previous
                INNER JOIN customer_collections previous_collection
                    ON previous_collection.id =
                        previous.customer_collection_id
                WHERE previous.customer_receivable_id =
                        NEW.customer_receivable_id
                    AND previous.organization_id =
                        NEW.organization_id
                    AND previous_collection.status =
                        'confirmed'
            ), 0)
            +
            COALESCE((
                SELECT SUM(current_allocation.amount_minor)
                FROM customer_collection_allocations current_allocation
                WHERE current_allocation.customer_collection_id =
                        NEW.customer_collection_id
                    AND current_allocation.customer_receivable_id =
                        NEW.customer_receivable_id
            ), 0)
            + NEW.amount_minor
            >
            (
                SELECT receivable.amount_minor
                FROM customer_receivables receivable
                WHERE receivable.id =
                    NEW.customer_receivable_id
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La aplicacion de cobranza supera la cobranza o el saldo de la deuda.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collection_allocations_guard_update
BEFORE UPDATE ON customer_collection_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una aplicacion de cobranza es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collection_allocations_guard_delete
BEFORE DELETE ON customer_collection_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una aplicacion de cobranza no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_update
BEFORE UPDATE ON customer_collections
FOR EACH ROW
BEGIN
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
        OR NEW.method <> OLD.method
        OR BINARY NEW.currency_code <>
            BINARY OLD.currency_code
        OR NEW.amount_minor <> OLD.amount_minor
        OR COALESCE(NEW.tendered_amount_minor, 0) <>
            COALESCE(OLD.tendered_amount_minor, 0)
        OR COALESCE(NEW.change_amount_minor, 0) <>
            COALESCE(OLD.change_amount_minor, 0)
        OR COALESCE(NEW.reference, '') <>
            COALESCE(OLD.reference, '')
        OR COALESCE(NEW.notes, '') <>
            COALESCE(OLD.notes, '')
        OR NEW.received_by_user_id <>
            OLD.received_by_user_id
        OR NEW.collected_at <> OLD.collected_at
        OR BINARY NEW.idempotency_key <>
            BINARY OLD.idempotency_key
        OR BINARY NEW.fingerprint <>
            BINARY OLD.fingerprint
        OR NEW.created_at <> OLD.created_at
        OR NOT EXISTS (
            SELECT 1
            FROM customer_collection_allocations allocation
            WHERE allocation.customer_collection_id =
                OLD.id
        )
        OR NEW.amount_minor <> COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM customer_collection_allocations allocation
            WHERE allocation.customer_collection_id =
                OLD.id
        ), 0)
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
                'La cobranza no puede confirmarse sin aplicaciones exactas y saldos validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_collections_guard_delete
BEFORE DELETE ON customer_collections
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una cobranza no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_customer_collection_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF (
        NEW.type = 'customer_collection'
        AND (
            NEW.direction <> 'in'
            OR NEW.customer_collection_id IS NULL
            OR NEW.commerce_payment_id IS NOT NULL
            OR NEW.destination_financial_account_id IS NOT NULL
            OR NEW.cash_security_drop_request_id IS NOT NULL
            OR NEW.purchase_payment_execution_id IS NOT NULL
            OR NEW.post_sale_cash_refund_execution_id IS NOT NULL
            OR NEW.post_sale_exchange_payment_id IS NOT NULL
            OR NEW.reason_code IS NOT NULL
            OR NEW.note IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM customer_collections collection
                WHERE collection.id =
                        NEW.customer_collection_id
                    AND collection.organization_id =
                        NEW.organization_id
                    AND collection.status = 'confirmed'
                    AND collection.method = 'cash'
                    AND collection.cash_register_session_id =
                        NEW.cash_register_session_id
                    AND collection.cash_register_id =
                        NEW.cash_register_id
                    AND collection.financial_account_id =
                        NEW.financial_account_id
                    AND collection.amount_minor =
                        NEW.amount_minor
                    AND BINARY collection.currency_code =
                        BINARY NEW.currency_code
                    AND collection.received_by_user_id =
                        NEW.recorded_by_user_id
            )
        )
    )
    OR (
        NEW.type <> 'customer_collection'
        AND NEW.customer_collection_id IS NOT NULL
    )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de caja no conserva la cobranza asociada.';
    END IF;
END
SQL);
    }
};
