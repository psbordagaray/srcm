<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POLICY_INSERT =
        'customer_credit_policies_guard_insert';
    private const POLICY_UPDATE =
        'customer_credit_policies_guard_update';
    private const POLICY_DELETE =
        'customer_credit_policies_guard_delete';
    private const OVERRIDE_INSERT =
        'customer_credit_overrides_guard_insert';
    private const OVERRIDE_UPDATE =
        'customer_credit_overrides_guard_update';
    private const OVERRIDE_DELETE =
        'customer_credit_overrides_guard_delete';
    private const RECEIVABLE_CREDIT_INSERT =
        'customer_receivables_credit_policy_guard_insert';

    public function up(): void
    {
        Schema::create(
            'customer_credit_policies',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('business_party_id')
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->char('currency_code', 3);
                $table->unsignedInteger('version');
                $table->unsignedBigInteger('limit_minor');
                $table->string('reason', 2000);
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->foreignId('set_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('set_at');
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_credit_policies_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'version',
                    ],
                    'customer_credit_policies_version_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'customer_credit_policies_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'set_at',
                    ],
                    'customer_credit_policies_current_index'
                );
            }
        );

        Schema::create(
            'customer_credit_overrides',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('business_party_id')
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'commerce_sale_id'
                );
                $table->unsignedBigInteger(
                    'customer_credit_policy_id'
                )->nullable();
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('amount_minor');
                $table->unsignedBigInteger(
                    'exposure_before_minor'
                );
                $table->unsignedBigInteger(
                    'projected_exposure_minor'
                );
                $table->unsignedBigInteger(
                    'overdue_minor'
                );
                $table->unsignedInteger(
                    'oldest_days_overdue'
                );
                $table->unsignedBigInteger(
                    'limit_minor'
                )->nullable();
                $table->boolean('over_limit');
                $table->boolean('overdue');
                $table->char(
                    'snapshot_fingerprint',
                    64
                );
                $table->string('reason', 2000);
                $table->foreignId(
                    'approved_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('approved_at');
                $table->char('fingerprint', 64);
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_credit_overrides_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'commerce_sale_id',
                    ],
                    'customer_credit_overrides_sale_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'approved_at',
                    ],
                    'customer_credit_overrides_party_index'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'commerce_sale_id',
                    ],
                    'customer_credit_overrides_sale_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('commerce_sales')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'organization_id',
                        'customer_credit_policy_id',
                    ],
                    'customer_credit_overrides_policy_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('customer_credit_policies')
                    ->restrictOnDelete();
            }
        );

        Schema::table(
            'customer_receivables',
            function (Blueprint $table): void {
                $table->unsignedBigInteger(
                    'customer_credit_policy_id'
                )->nullable();
                $table->unsignedBigInteger(
                    'customer_credit_override_id'
                )->nullable();
                $table->string(
                    'credit_decision',
                    32
                )->nullable();
                $table->unsignedBigInteger(
                    'credit_limit_minor'
                )->nullable();
                $table->unsignedBigInteger(
                    'credit_exposure_before_minor'
                )->nullable();
                $table->unsignedBigInteger(
                    'credit_projected_exposure_minor'
                )->nullable();
                $table->unsignedBigInteger(
                    'credit_overdue_minor'
                )->nullable();
                $table->unsignedInteger(
                    'credit_oldest_days_overdue'
                )->nullable();
                $table->char(
                    'credit_snapshot_fingerprint',
                    64
                )->nullable();

                $table->index(
                    'customer_credit_policy_id',
                    'customer_receivables_credit_policy_index'
                );
                $table->index(
                    'customer_credit_override_id',
                    'customer_receivables_credit_override_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.4 conserva políticas versionadas y excepciones de crédito append-only; no admite rollback automático.'
        );
    }

    private function createGuards(): void
    {
        foreach ([
            self::RECEIVABLE_CREDIT_INSERT,
            self::OVERRIDE_DELETE,
            self::OVERRIDE_UPDATE,
            self::OVERRIDE_INSERT,
            self::POLICY_DELETE,
            self::POLICY_UPDATE,
            self::POLICY_INSERT,
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
            "La integridad P9.4 no está implementada para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_policies_guard_insert
BEFORE INSERT ON customer_credit_policies
WHEN NEW.version < 1
    OR NEW.limit_minor < 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR TRIM(NEW.reason) = ''
    OR LENGTH(NEW.reason) > 2000
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.set_at IS NULL
    OR NEW.created_at IS NULL
    OR NEW.version <> COALESCE((
        SELECT MAX(policy.version)
        FROM customer_credit_policies policy
        WHERE policy.organization_id =
                NEW.organization_id
            AND policy.business_party_id =
                NEW.business_party_id
            AND policy.currency_code =
                NEW.currency_code
    ), 0) + 1
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
                NEW.set_by_user_id
            AND membership.active = 1
            AND membership.role = 'admin'
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La politica de credito no conserva cliente, version, limite y Administrador validos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_policies_guard_update
BEFORE UPDATE ON customer_credit_policies
BEGIN
    SELECT RAISE(
        ABORT,
        'Una version de politica de credito es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_policies_guard_delete
BEFORE DELETE ON customer_credit_policies
BEGIN
    SELECT RAISE(
        ABORT,
        'Una version de politica de credito no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_overrides_guard_insert
BEFORE INSERT ON customer_credit_overrides
WHEN NEW.amount_minor < 1
    OR NEW.exposure_before_minor < 0
    OR NEW.projected_exposure_minor <>
        NEW.exposure_before_minor + NEW.amount_minor
    OR NEW.overdue_minor < 0
    OR NEW.oldest_days_overdue < 0
    OR NEW.over_limit NOT IN (0, 1)
    OR NEW.overdue NOT IN (0, 1)
    OR (NEW.over_limit = 0 AND NEW.overdue = 0)
    OR LENGTH(NEW.snapshot_fingerprint) <> 64
    OR TRIM(NEW.reason) = ''
    OR LENGTH(NEW.reason) > 2000
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.approved_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        WHERE sale.id = NEW.commerce_sale_id
            AND sale.organization_id =
                NEW.organization_id
            AND sale.status = 'building'
            AND sale.customer_business_party_id =
                NEW.business_party_id
            AND sale.currency_code =
                NEW.currency_code
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
            AND membership.user_id =
                NEW.approved_by_user_id
            AND membership.active = 1
            AND membership.role = 'admin'
    )
    OR NEW.exposure_before_minor <> COALESCE((
        SELECT SUM(
            receivable.amount_minor - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM customer_collection_allocations allocation
                INNER JOIN customer_collections collection
                    ON collection.id =
                        allocation.customer_collection_id
                WHERE allocation.organization_id =
                        NEW.organization_id
                    AND allocation.customer_receivable_id =
                        receivable.id
                    AND collection.organization_id =
                        NEW.organization_id
                    AND collection.status = 'confirmed'
            ), 0)
        )
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                NEW.business_party_id
            AND receivable.currency_code =
                NEW.currency_code
    ), 0)
    OR NEW.overdue_minor <> COALESCE((
        SELECT SUM(
            receivable.amount_minor - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM customer_collection_allocations allocation
                INNER JOIN customer_collections collection
                    ON collection.id =
                        allocation.customer_collection_id
                WHERE allocation.organization_id =
                        NEW.organization_id
                    AND allocation.customer_receivable_id =
                        receivable.id
                    AND collection.organization_id =
                        NEW.organization_id
                    AND collection.status = 'confirmed'
            ), 0)
        )
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                NEW.business_party_id
            AND receivable.currency_code =
                NEW.currency_code
            AND receivable.due_on IS NOT NULL
            AND DATE(receivable.due_on) < DATE((
                SELECT sale.sold_at
                FROM commerce_sales sale
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id =
                        NEW.organization_id
            ))
            AND (
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status = 'confirmed'
                ), 0)
            ) > 0
    ), 0)
    OR NEW.oldest_days_overdue <> COALESCE((
        SELECT MAX(
            CAST(
                julianday(DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id = NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                )))
                - julianday(DATE(receivable.due_on))
                AS INTEGER
            )
        )
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                NEW.business_party_id
            AND receivable.currency_code =
                NEW.currency_code
            AND receivable.due_on IS NOT NULL
            AND DATE(receivable.due_on) < DATE((
                SELECT sale.sold_at
                FROM commerce_sales sale
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id =
                        NEW.organization_id
            ))
            AND (
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status = 'confirmed'
                ), 0)
            ) > 0
    ), 0)
    OR NEW.overdue <> CASE
        WHEN NEW.overdue_minor > 0 THEN 1
        ELSE 0
    END
    OR (
        NEW.customer_credit_policy_id IS NULL
        AND (
            NEW.limit_minor IS NOT NULL
            OR NEW.over_limit <> 0
            OR EXISTS (
                SELECT 1
                FROM customer_credit_policies policy
                WHERE policy.organization_id =
                        NEW.organization_id
                    AND policy.business_party_id =
                        NEW.business_party_id
                    AND policy.currency_code =
                        NEW.currency_code
            )
        )
    )
    OR (
        NEW.customer_credit_policy_id IS NOT NULL
        AND (
            NEW.limit_minor IS NULL
            OR NOT EXISTS (
                SELECT 1
                FROM customer_credit_policies policy
                WHERE policy.id =
                        NEW.customer_credit_policy_id
                    AND policy.organization_id =
                        NEW.organization_id
                    AND policy.business_party_id =
                        NEW.business_party_id
                    AND policy.currency_code =
                        NEW.currency_code
                    AND policy.limit_minor =
                        NEW.limit_minor
                    AND policy.version = (
                        SELECT MAX(current_policy.version)
                        FROM customer_credit_policies current_policy
                        WHERE current_policy.organization_id =
                                NEW.organization_id
                            AND current_policy.business_party_id =
                                NEW.business_party_id
                            AND current_policy.currency_code =
                                NEW.currency_code
                    )
            )
            OR NEW.over_limit <> CASE
                WHEN NEW.projected_exposure_minor >
                    NEW.limit_minor
                THEN 1
                ELSE 0
            END
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La excepcion de credito no conserva el riesgo y la politica autorizados.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_overrides_guard_update
BEFORE UPDATE ON customer_credit_overrides
BEGIN
    SELECT RAISE(
        ABORT,
        'Una excepcion de credito autorizada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_overrides_guard_delete
BEFORE DELETE ON customer_credit_overrides
BEGIN
    SELECT RAISE(
        ABORT,
        'Una excepcion de credito autorizada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_credit_policy_guard_insert
BEFORE INSERT ON customer_receivables
WHEN NEW.credit_decision NOT IN (
        'legacy_admin',
        'within_policy',
        'admin_override'
    )
    OR NEW.credit_exposure_before_minor IS NULL
    OR NEW.credit_projected_exposure_minor IS NULL
    OR NEW.credit_overdue_minor IS NULL
    OR NEW.credit_oldest_days_overdue IS NULL
    OR LENGTH(NEW.credit_snapshot_fingerprint) <> 64
    OR NEW.credit_projected_exposure_minor <>
        NEW.credit_exposure_before_minor + NEW.amount_minor
    OR (
        NEW.credit_decision <> 'legacy_admin'
        AND NEW.credit_exposure_before_minor <> COALESCE((
        SELECT SUM(
            receivable.amount_minor - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM customer_collection_allocations allocation
                INNER JOIN customer_collections collection
                    ON collection.id =
                        allocation.customer_collection_id
                WHERE allocation.organization_id =
                        NEW.organization_id
                    AND allocation.customer_receivable_id =
                        receivable.id
                    AND collection.organization_id =
                        NEW.organization_id
                    AND collection.status = 'confirmed'
            ), 0)
        )
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                NEW.business_party_id
            AND receivable.currency_code =
                NEW.currency_code
    ), 0)
    )
    OR (
        NEW.credit_decision <> 'legacy_admin'
        AND NEW.credit_overdue_minor <> COALESCE((
        SELECT SUM(
            receivable.amount_minor - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM customer_collection_allocations allocation
                INNER JOIN customer_collections collection
                    ON collection.id =
                        allocation.customer_collection_id
                WHERE allocation.organization_id =
                        NEW.organization_id
                    AND allocation.customer_receivable_id =
                        receivable.id
                    AND collection.organization_id =
                        NEW.organization_id
                    AND collection.status = 'confirmed'
            ), 0)
        )
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                NEW.business_party_id
            AND receivable.currency_code =
                NEW.currency_code
            AND receivable.due_on IS NOT NULL
            AND DATE(receivable.due_on) < DATE((
                SELECT sale.sold_at
                FROM commerce_sales sale
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id =
                        NEW.organization_id
            ))
            AND (
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status = 'confirmed'
                ), 0)
            ) > 0
    ), 0)
    )
    OR (
        NEW.credit_decision <> 'legacy_admin'
        AND NEW.credit_oldest_days_overdue <> COALESCE((
        SELECT MAX(
            CAST(
                julianday(DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id = NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                )))
                - julianday(DATE(receivable.due_on))
                AS INTEGER
            )
        )
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.business_party_id =
                NEW.business_party_id
            AND receivable.currency_code =
                NEW.currency_code
            AND receivable.due_on IS NOT NULL
            AND DATE(receivable.due_on) < DATE((
                SELECT sale.sold_at
                FROM commerce_sales sale
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id =
                        NEW.organization_id
            ))
            AND (
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status = 'confirmed'
                ), 0)
            ) > 0
    ), 0)
    )
    OR (
        NEW.credit_decision = 'legacy_admin'
        AND (
            NEW.customer_credit_policy_id IS NOT NULL
            OR NEW.customer_credit_override_id IS NOT NULL
            OR NEW.credit_limit_minor IS NOT NULL
            OR EXISTS (
                SELECT 1
                FROM customer_credit_policies policy
                WHERE policy.organization_id =
                        NEW.organization_id
                    AND policy.business_party_id =
                        NEW.business_party_id
                    AND policy.currency_code =
                        NEW.currency_code
            )
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id =
                        NEW.organization_id
                    AND membership.user_id =
                        NEW.recognized_by_user_id
                    AND membership.active = 1
                    AND membership.role = 'admin'
            )
        )
    )
    OR (
        NEW.credit_decision = 'within_policy'
        AND (
            NEW.customer_credit_policy_id IS NULL
            OR NEW.customer_credit_override_id IS NOT NULL
            OR NEW.credit_limit_minor IS NULL
            OR NEW.credit_overdue_minor <> 0
            OR NEW.credit_oldest_days_overdue <> 0
            OR NEW.credit_projected_exposure_minor >
                NEW.credit_limit_minor
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id =
                        NEW.organization_id
                    AND membership.user_id =
                        NEW.recognized_by_user_id
                    AND membership.active = 1
                    AND membership.role IN (
                        'admin',
                        'operator'
                    )
            )
            OR NOT EXISTS (
                SELECT 1
                FROM customer_credit_policies policy
                WHERE policy.id =
                        NEW.customer_credit_policy_id
                    AND policy.organization_id =
                        NEW.organization_id
                    AND policy.business_party_id =
                        NEW.business_party_id
                    AND policy.currency_code =
                        NEW.currency_code
                    AND policy.limit_minor =
                        NEW.credit_limit_minor
                    AND policy.version = (
                        SELECT MAX(current_policy.version)
                        FROM customer_credit_policies current_policy
                        WHERE current_policy.organization_id =
                                NEW.organization_id
                            AND current_policy.business_party_id =
                                NEW.business_party_id
                            AND current_policy.currency_code =
                                NEW.currency_code
                    )
            )
        )
    )
    OR (
        NEW.credit_decision = 'admin_override'
        AND (
            NEW.customer_credit_override_id IS NULL
            OR NOT EXISTS (
                SELECT 1
                FROM customer_credit_overrides override_row
                WHERE override_row.id =
                        NEW.customer_credit_override_id
                    AND override_row.organization_id =
                        NEW.organization_id
                    AND override_row.business_party_id =
                        NEW.business_party_id
                    AND override_row.commerce_sale_id =
                        NEW.commerce_sale_id
                    AND override_row.currency_code =
                        NEW.currency_code
                    AND override_row.amount_minor =
                        NEW.amount_minor
                    AND COALESCE(
                        override_row.customer_credit_policy_id,
                        0
                    ) = COALESCE(
                        NEW.customer_credit_policy_id,
                        0
                    )
                    AND COALESCE(
                        override_row.limit_minor,
                        -1
                    ) = COALESCE(
                        NEW.credit_limit_minor,
                        -1
                    )
                    AND override_row.exposure_before_minor =
                        NEW.credit_exposure_before_minor
                    AND override_row.projected_exposure_minor =
                        NEW.credit_projected_exposure_minor
                    AND override_row.overdue_minor =
                        NEW.credit_overdue_minor
                    AND override_row.oldest_days_overdue =
                        NEW.credit_oldest_days_overdue
                    AND override_row.snapshot_fingerprint =
                        NEW.credit_snapshot_fingerprint
                    AND override_row.approved_by_user_id =
                        NEW.recognized_by_user_id
            )
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id =
                        NEW.organization_id
                    AND membership.user_id =
                        NEW.recognized_by_user_id
                    AND membership.active = 1
                    AND membership.role = 'admin'
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La cuenta por cobrar no conserva una decision de credito valida.'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_policies_guard_insert
BEFORE INSERT ON customer_credit_policies
FOR EACH ROW
BEGIN
    IF NEW.version < 1
        OR NEW.limit_minor < 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR BINARY NEW.currency_code <>
            BINARY UPPER(NEW.currency_code)
        OR CHAR_LENGTH(TRIM(NEW.reason)) = 0
        OR CHAR_LENGTH(NEW.reason) > 2000
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.idempotency_key) > 180
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.set_at IS NULL
        OR NEW.created_at IS NULL
        OR NEW.version <> COALESCE((
            SELECT MAX(policy.version)
            FROM customer_credit_policies policy
            WHERE policy.organization_id =
                    NEW.organization_id
                AND policy.business_party_id =
                    NEW.business_party_id
                AND BINARY policy.currency_code =
                    BINARY NEW.currency_code
        ), 0) + 1
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
                    NEW.set_by_user_id
                AND membership.active = 1
                AND membership.role = 'admin'
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La politica de credito no conserva cliente, version, limite y Administrador validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_policies_guard_update
BEFORE UPDATE ON customer_credit_policies
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una version de politica de credito es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_policies_guard_delete
BEFORE DELETE ON customer_credit_policies
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una version de politica de credito no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_overrides_guard_insert
BEFORE INSERT ON customer_credit_overrides
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.exposure_before_minor < 0
        OR NEW.projected_exposure_minor <>
            NEW.exposure_before_minor + NEW.amount_minor
        OR NEW.overdue_minor < 0
        OR NEW.oldest_days_overdue < 0
        OR NEW.over_limit NOT IN (0, 1)
        OR NEW.overdue NOT IN (0, 1)
        OR (
            NEW.over_limit = 0
            AND NEW.overdue = 0
        )
        OR CHAR_LENGTH(
            NEW.snapshot_fingerprint
        ) <> 64
        OR CHAR_LENGTH(TRIM(NEW.reason)) = 0
        OR CHAR_LENGTH(NEW.reason) > 2000
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.approved_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            WHERE sale.id = NEW.commerce_sale_id
                AND sale.organization_id =
                    NEW.organization_id
                AND sale.status = 'building'
                AND sale.customer_business_party_id =
                    NEW.business_party_id
                AND BINARY sale.currency_code =
                    BINARY NEW.currency_code
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
                AND membership.user_id =
                    NEW.approved_by_user_id
                AND membership.active = 1
                AND membership.role = 'admin'
        )
        OR NEW.exposure_before_minor <> COALESCE((
            SELECT SUM(
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status =
                            'confirmed'
                ), 0)
            )
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    NEW.business_party_id
                AND BINARY receivable.currency_code =
                    BINARY NEW.currency_code
        ), 0)
        OR NEW.overdue_minor <> COALESCE((
            SELECT SUM(
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status =
                            'confirmed'
                ), 0)
            )
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    NEW.business_party_id
                AND BINARY receivable.currency_code =
                    BINARY NEW.currency_code
                AND receivable.due_on IS NOT NULL
                AND DATE(receivable.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id =
                            NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND (
                    receivable.amount_minor - COALESCE((
                        SELECT SUM(allocation.amount_minor)
                        FROM customer_collection_allocations allocation
                        INNER JOIN customer_collections collection
                            ON collection.id =
                                allocation.customer_collection_id
                        WHERE allocation.organization_id =
                                NEW.organization_id
                            AND allocation.customer_receivable_id =
                                receivable.id
                            AND collection.organization_id =
                                NEW.organization_id
                            AND collection.status =
                                'confirmed'
                    ), 0)
                ) > 0
        ), 0)
        OR NEW.oldest_days_overdue <> COALESCE((
            SELECT MAX(
                DATEDIFF(
                    DATE((
                        SELECT sale.sold_at
                        FROM commerce_sales sale
                        WHERE sale.id =
                                NEW.commerce_sale_id
                            AND sale.organization_id =
                                NEW.organization_id
                    )),
                    DATE(receivable.due_on)
                )
            )
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    NEW.business_party_id
                AND BINARY receivable.currency_code =
                    BINARY NEW.currency_code
                AND receivable.due_on IS NOT NULL
                AND DATE(receivable.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id =
                            NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND (
                    receivable.amount_minor - COALESCE((
                        SELECT SUM(allocation.amount_minor)
                        FROM customer_collection_allocations allocation
                        INNER JOIN customer_collections collection
                            ON collection.id =
                                allocation.customer_collection_id
                        WHERE allocation.organization_id =
                                NEW.organization_id
                            AND allocation.customer_receivable_id =
                                receivable.id
                            AND collection.organization_id =
                                NEW.organization_id
                            AND collection.status =
                                'confirmed'
                    ), 0)
                ) > 0
        ), 0)
        OR NEW.overdue <> CASE
            WHEN NEW.overdue_minor > 0 THEN 1
            ELSE 0
        END
        OR (
            NEW.customer_credit_policy_id IS NULL
            AND (
                NEW.limit_minor IS NOT NULL
                OR NEW.over_limit <> 0
                OR EXISTS (
                    SELECT 1
                    FROM customer_credit_policies policy
                    WHERE policy.organization_id =
                            NEW.organization_id
                        AND policy.business_party_id =
                            NEW.business_party_id
                        AND BINARY policy.currency_code =
                            BINARY NEW.currency_code
                )
            )
        )
        OR (
            NEW.customer_credit_policy_id IS NOT NULL
            AND (
                NEW.limit_minor IS NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM customer_credit_policies policy
                    WHERE policy.id =
                            NEW.customer_credit_policy_id
                        AND policy.organization_id =
                            NEW.organization_id
                        AND policy.business_party_id =
                            NEW.business_party_id
                        AND BINARY policy.currency_code =
                            BINARY NEW.currency_code
                        AND policy.limit_minor =
                            NEW.limit_minor
                        AND policy.version = (
                            SELECT MAX(
                                current_policy.version
                            )
                            FROM customer_credit_policies current_policy
                            WHERE current_policy.organization_id =
                                    NEW.organization_id
                                AND current_policy.business_party_id =
                                    NEW.business_party_id
                                AND BINARY current_policy.currency_code =
                                    BINARY NEW.currency_code
                        )
                )
                OR NEW.over_limit <> CASE
                    WHEN NEW.projected_exposure_minor >
                        NEW.limit_minor
                    THEN 1
                    ELSE 0
                END
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La excepcion de credito no conserva el riesgo y la politica autorizados.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_overrides_guard_update
BEFORE UPDATE ON customer_credit_overrides
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una excepcion de credito autorizada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_overrides_guard_delete
BEFORE DELETE ON customer_credit_overrides
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una excepcion de credito autorizada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_credit_policy_guard_insert
BEFORE INSERT ON customer_receivables
FOR EACH ROW
BEGIN
    IF NEW.credit_decision NOT IN (
            'legacy_admin',
            'within_policy',
            'admin_override'
        )
        OR NEW.credit_exposure_before_minor IS NULL
        OR NEW.credit_projected_exposure_minor IS NULL
        OR NEW.credit_overdue_minor IS NULL
        OR NEW.credit_oldest_days_overdue IS NULL
        OR CHAR_LENGTH(
            NEW.credit_snapshot_fingerprint
        ) <> 64
        OR NEW.credit_projected_exposure_minor <>
            NEW.credit_exposure_before_minor
                + NEW.amount_minor
        OR (
            NEW.credit_decision <> 'legacy_admin'
            AND NEW.credit_exposure_before_minor <> COALESCE((
            SELECT SUM(
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status =
                            'confirmed'
                ), 0)
            )
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    NEW.business_party_id
                AND BINARY receivable.currency_code =
                    BINARY NEW.currency_code
        ), 0)
        )
        OR (
            NEW.credit_decision <> 'legacy_admin'
            AND NEW.credit_overdue_minor <> COALESCE((
            SELECT SUM(
                receivable.amount_minor - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM customer_collection_allocations allocation
                    INNER JOIN customer_collections collection
                        ON collection.id =
                            allocation.customer_collection_id
                    WHERE allocation.organization_id =
                            NEW.organization_id
                        AND allocation.customer_receivable_id =
                            receivable.id
                        AND collection.organization_id =
                            NEW.organization_id
                        AND collection.status =
                            'confirmed'
                ), 0)
            )
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    NEW.business_party_id
                AND BINARY receivable.currency_code =
                    BINARY NEW.currency_code
                AND receivable.due_on IS NOT NULL
                AND DATE(receivable.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id =
                            NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND (
                    receivable.amount_minor - COALESCE((
                        SELECT SUM(allocation.amount_minor)
                        FROM customer_collection_allocations allocation
                        INNER JOIN customer_collections collection
                            ON collection.id =
                                allocation.customer_collection_id
                        WHERE allocation.organization_id =
                                NEW.organization_id
                            AND allocation.customer_receivable_id =
                                receivable.id
                            AND collection.organization_id =
                                NEW.organization_id
                            AND collection.status =
                                'confirmed'
                    ), 0)
                ) > 0
        ), 0)
        )
        OR (
            NEW.credit_decision <> 'legacy_admin'
            AND NEW.credit_oldest_days_overdue <> COALESCE((
            SELECT MAX(
                DATEDIFF(
                    DATE((
                        SELECT sale.sold_at
                        FROM commerce_sales sale
                        WHERE sale.id =
                                NEW.commerce_sale_id
                            AND sale.organization_id =
                                NEW.organization_id
                    )),
                    DATE(receivable.due_on)
                )
            )
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.business_party_id =
                    NEW.business_party_id
                AND BINARY receivable.currency_code =
                    BINARY NEW.currency_code
                AND receivable.due_on IS NOT NULL
                AND DATE(receivable.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id =
                            NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND (
                    receivable.amount_minor - COALESCE((
                        SELECT SUM(allocation.amount_minor)
                        FROM customer_collection_allocations allocation
                        INNER JOIN customer_collections collection
                            ON collection.id =
                                allocation.customer_collection_id
                        WHERE allocation.organization_id =
                                NEW.organization_id
                            AND allocation.customer_receivable_id =
                                receivable.id
                            AND collection.organization_id =
                                NEW.organization_id
                            AND collection.status =
                                'confirmed'
                    ), 0)
                ) > 0
        ), 0)
        )
        OR (
            NEW.credit_decision = 'legacy_admin'
            AND (
                NEW.customer_credit_policy_id IS NOT NULL
                OR NEW.customer_credit_override_id IS NOT NULL
                OR NEW.credit_limit_minor IS NOT NULL
                OR EXISTS (
                    SELECT 1
                    FROM customer_credit_policies policy
                    WHERE policy.organization_id =
                            NEW.organization_id
                        AND policy.business_party_id =
                            NEW.business_party_id
                        AND BINARY policy.currency_code =
                            BINARY NEW.currency_code
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                            NEW.organization_id
                        AND membership.user_id =
                            NEW.recognized_by_user_id
                        AND membership.active = 1
                        AND membership.role = 'admin'
                )
            )
        )
        OR (
            NEW.credit_decision = 'within_policy'
            AND (
                NEW.customer_credit_policy_id IS NULL
                OR NEW.customer_credit_override_id IS NOT NULL
                OR NEW.credit_limit_minor IS NULL
                OR NEW.credit_overdue_minor <> 0
                OR NEW.credit_oldest_days_overdue <> 0
                OR NEW.credit_projected_exposure_minor >
                    NEW.credit_limit_minor
                OR NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                            NEW.organization_id
                        AND membership.user_id =
                            NEW.recognized_by_user_id
                        AND membership.active = 1
                        AND membership.role IN (
                            'admin',
                            'operator'
                        )
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM customer_credit_policies policy
                    WHERE policy.id =
                            NEW.customer_credit_policy_id
                        AND policy.organization_id =
                            NEW.organization_id
                        AND policy.business_party_id =
                            NEW.business_party_id
                        AND BINARY policy.currency_code =
                            BINARY NEW.currency_code
                        AND policy.limit_minor =
                            NEW.credit_limit_minor
                        AND policy.version = (
                            SELECT MAX(
                                current_policy.version
                            )
                            FROM customer_credit_policies current_policy
                            WHERE current_policy.organization_id =
                                    NEW.organization_id
                                AND current_policy.business_party_id =
                                    NEW.business_party_id
                                AND BINARY current_policy.currency_code =
                                    BINARY NEW.currency_code
                        )
                )
            )
        )
        OR (
            NEW.credit_decision = 'admin_override'
            AND (
                NEW.customer_credit_override_id IS NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM customer_credit_overrides override_row
                    WHERE override_row.id =
                            NEW.customer_credit_override_id
                        AND override_row.organization_id =
                            NEW.organization_id
                        AND override_row.business_party_id =
                            NEW.business_party_id
                        AND override_row.commerce_sale_id =
                            NEW.commerce_sale_id
                        AND BINARY override_row.currency_code =
                            BINARY NEW.currency_code
                        AND override_row.amount_minor =
                            NEW.amount_minor
                        AND COALESCE(
                            override_row.customer_credit_policy_id,
                            0
                        ) = COALESCE(
                            NEW.customer_credit_policy_id,
                            0
                        )
                        AND COALESCE(
                            override_row.limit_minor,
                            -1
                        ) = COALESCE(
                            NEW.credit_limit_minor,
                            -1
                        )
                        AND override_row.exposure_before_minor =
                            NEW.credit_exposure_before_minor
                        AND override_row.projected_exposure_minor =
                            NEW.credit_projected_exposure_minor
                        AND override_row.overdue_minor =
                            NEW.credit_overdue_minor
                        AND override_row.oldest_days_overdue =
                            NEW.credit_oldest_days_overdue
                        AND BINARY override_row.snapshot_fingerprint =
                            BINARY NEW.credit_snapshot_fingerprint
                        AND override_row.approved_by_user_id =
                            NEW.recognized_by_user_id
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id =
                            NEW.organization_id
                        AND membership.user_id =
                            NEW.recognized_by_user_id
                        AND membership.active = 1
                        AND membership.role = 'admin'
                )
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La cuenta por cobrar no conserva una decision de credito valida.';
    END IF;
END
SQL);
    }
};
