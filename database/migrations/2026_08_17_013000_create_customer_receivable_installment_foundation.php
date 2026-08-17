<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLLECTION_TOTALS_VIEW =
        'customer_receivable_collection_totals';
    private const INSTALLMENT_BALANCES_VIEW =
        'customer_receivable_installment_balances';

    private const INSTALLMENT_INSERT =
        'customer_receivable_installments_guard_insert';
    private const INSTALLMENT_UPDATE =
        'customer_receivable_installments_guard_update';
    private const INSTALLMENT_DELETE =
        'customer_receivable_installments_guard_delete';
    private const PLAN_INSERT =
        'customer_receivable_installment_plans_guard_insert';
    private const PLAN_UPDATE =
        'customer_receivable_installment_plans_guard_update';
    private const PLAN_DELETE =
        'customer_receivable_installment_plans_guard_delete';
    private const SALE_CONFIRM =
        'commerce_sales_installment_schedule_guard_update';

    private const CREDIT_OVERRIDE_INSERT =
        'customer_credit_overrides_guard_insert';
    private const RECEIVABLE_CREDIT_INSERT =
        'customer_receivables_credit_policy_guard_insert';

    public function up(): void
    {
        Schema::create(
            'customer_receivable_installments',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger(
                    'customer_receivable_id'
                );
                $table->unsignedSmallInteger('sequence');
                $table->date('due_on');
                $table->unsignedBigInteger('amount_minor');
                $table->char('fingerprint', 64);
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_receivable_installments_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'customer_receivable_id',
                        'sequence',
                    ],
                    'customer_receivable_installments_sequence_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'customer_receivable_id',
                        'due_on',
                    ],
                    'customer_receivable_installments_due_index'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'customer_receivable_id',
                    ],
                    'customer_receivable_installments_receivable_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('customer_receivables')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'customer_receivable_installment_plans',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger(
                    'customer_receivable_id'
                );
                $table->unsignedSmallInteger(
                    'installment_count'
                );
                $table->date('first_due_on');
                $table->string('strategy', 40);
                $table->char('fingerprint', 64);
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_receivable_installment_plans_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'customer_receivable_id',
                    ],
                    'customer_receivable_installment_plans_receivable_unique'
                );

                $table->foreign(
                    [
                        'organization_id',
                        'customer_receivable_id',
                    ],
                    'customer_receivable_installment_plans_receivable_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('customer_receivables')
                    ->restrictOnDelete();
            }
        );

        $this->createViews();
        $this->createScheduleGuards();
        $this->replaceCreditPolicyGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.5 conserva cronogramas de cuotas propias append-only y no admite rollback automático.'
        );
    }

    private function createViews(): void
    {
        DB::unprepared(
            'DROP VIEW IF EXISTS '
            .self::INSTALLMENT_BALANCES_VIEW
        );
        DB::unprepared(
            'DROP VIEW IF EXISTS '
            .self::COLLECTION_TOTALS_VIEW
        );

        DB::unprepared(<<<'SQL'
CREATE VIEW customer_receivable_collection_totals AS
SELECT
    receivable.organization_id,
    receivable.id AS customer_receivable_id,
    COALESCE(
        SUM(
            CASE
                WHEN collection.status = 'confirmed'
                THEN allocation.amount_minor
                ELSE 0
            END
        ),
        0
    ) AS collected_minor
FROM customer_receivables receivable
LEFT JOIN customer_collection_allocations allocation
    ON allocation.customer_receivable_id = receivable.id
    AND allocation.organization_id =
        receivable.organization_id
LEFT JOIN customer_collections collection
    ON collection.id =
        allocation.customer_collection_id
    AND collection.organization_id =
        receivable.organization_id
GROUP BY
    receivable.organization_id,
    receivable.id
SQL);

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE VIEW customer_receivable_installment_balances AS
SELECT
    receivable.organization_id,
    receivable.id AS customer_receivable_id,
    receivable.business_party_id,
    receivable.currency_code,
    NULL AS installment_id,
    NULL AS installment_public_id,
    1 AS sequence,
    1 AS installment_count,
    receivable.due_on,
    receivable.amount_minor,
    MIN(
        receivable.amount_minor,
        totals.collected_minor
    ) AS collected_minor,
    MAX(
        0,
        receivable.amount_minor
            - totals.collected_minor
    ) AS outstanding_minor,
    0 AS planned
FROM customer_receivables receivable
INNER JOIN customer_receivable_collection_totals totals
    ON totals.organization_id =
        receivable.organization_id
    AND totals.customer_receivable_id =
        receivable.id
WHERE NOT EXISTS (
    SELECT 1
    FROM customer_receivable_installment_plans plan
    WHERE plan.organization_id =
            receivable.organization_id
        AND plan.customer_receivable_id =
            receivable.id
)

UNION ALL

SELECT
    receivable.organization_id,
    receivable.id AS customer_receivable_id,
    receivable.business_party_id,
    receivable.currency_code,
    installment.id AS installment_id,
    installment.public_id AS installment_public_id,
    installment.sequence,
    plan.installment_count,
    installment.due_on,
    installment.amount_minor,
    MIN(
        installment.amount_minor,
        MAX(
            0,
            totals.collected_minor
                - COALESCE((
                    SELECT SUM(prior.amount_minor)
                    FROM customer_receivable_installments prior
                    WHERE prior.organization_id =
                            installment.organization_id
                        AND prior.customer_receivable_id =
                            installment.customer_receivable_id
                        AND prior.sequence <
                            installment.sequence
                ), 0)
        )
    ) AS collected_minor,
    MAX(
        0,
        installment.amount_minor
            - MIN(
                installment.amount_minor,
                MAX(
                    0,
                    totals.collected_minor
                        - COALESCE((
                            SELECT SUM(prior.amount_minor)
                            FROM customer_receivable_installments prior
                            WHERE prior.organization_id =
                                    installment.organization_id
                                AND prior.customer_receivable_id =
                                    installment.customer_receivable_id
                                AND prior.sequence <
                                    installment.sequence
                        ), 0)
                )
            )
    ) AS outstanding_minor,
    1 AS planned
FROM customer_receivables receivable
INNER JOIN customer_receivable_installment_plans plan
    ON plan.organization_id =
        receivable.organization_id
    AND plan.customer_receivable_id =
        receivable.id
INNER JOIN customer_receivable_installments installment
    ON installment.organization_id =
        receivable.organization_id
    AND installment.customer_receivable_id =
        receivable.id
INNER JOIN customer_receivable_collection_totals totals
    ON totals.organization_id =
        receivable.organization_id
    AND totals.customer_receivable_id =
        receivable.id
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE VIEW customer_receivable_installment_balances AS
SELECT
    receivable.organization_id,
    receivable.id AS customer_receivable_id,
    receivable.business_party_id,
    receivable.currency_code,
    NULL AS installment_id,
    NULL AS installment_public_id,
    1 AS sequence,
    1 AS installment_count,
    receivable.due_on,
    receivable.amount_minor,
    LEAST(
        receivable.amount_minor,
        totals.collected_minor
    ) AS collected_minor,
    GREATEST(
        0,
        receivable.amount_minor
            - totals.collected_minor
    ) AS outstanding_minor,
    0 AS planned
FROM customer_receivables receivable
INNER JOIN customer_receivable_collection_totals totals
    ON totals.organization_id =
        receivable.organization_id
    AND totals.customer_receivable_id =
        receivable.id
WHERE NOT EXISTS (
    SELECT 1
    FROM customer_receivable_installment_plans plan
    WHERE plan.organization_id =
            receivable.organization_id
        AND plan.customer_receivable_id =
            receivable.id
)

UNION ALL

SELECT
    receivable.organization_id,
    receivable.id AS customer_receivable_id,
    receivable.business_party_id,
    receivable.currency_code,
    installment.id AS installment_id,
    installment.public_id AS installment_public_id,
    installment.sequence,
    plan.installment_count,
    installment.due_on,
    installment.amount_minor,
    LEAST(
        installment.amount_minor,
        GREATEST(
            0,
            totals.collected_minor
                - COALESCE((
                    SELECT SUM(prior.amount_minor)
                    FROM customer_receivable_installments prior
                    WHERE prior.organization_id =
                            installment.organization_id
                        AND prior.customer_receivable_id =
                            installment.customer_receivable_id
                        AND prior.sequence <
                            installment.sequence
                ), 0)
        )
    ) AS collected_minor,
    GREATEST(
        0,
        installment.amount_minor
            - LEAST(
                installment.amount_minor,
                GREATEST(
                    0,
                    totals.collected_minor
                        - COALESCE((
                            SELECT SUM(prior.amount_minor)
                            FROM customer_receivable_installments prior
                            WHERE prior.organization_id =
                                    installment.organization_id
                                AND prior.customer_receivable_id =
                                    installment.customer_receivable_id
                                AND prior.sequence <
                                    installment.sequence
                        ), 0)
                )
            )
    ) AS outstanding_minor,
    1 AS planned
FROM customer_receivables receivable
INNER JOIN customer_receivable_installment_plans plan
    ON plan.organization_id =
        receivable.organization_id
    AND plan.customer_receivable_id =
        receivable.id
INNER JOIN customer_receivable_installments installment
    ON installment.organization_id =
        receivable.organization_id
    AND installment.customer_receivable_id =
        receivable.id
INNER JOIN customer_receivable_collection_totals totals
    ON totals.organization_id =
        receivable.organization_id
    AND totals.customer_receivable_id =
        receivable.id
SQL);

            return;
        }

        throw new LogicException(
            "La derivación P9.5 no está implementada para {$driver}."
        );
    }

    private function createScheduleGuards(): void
    {
        foreach ([
            self::SALE_CONFIRM,
            self::PLAN_DELETE,
            self::PLAN_UPDATE,
            self::PLAN_INSERT,
            self::INSTALLMENT_DELETE,
            self::INSTALLMENT_UPDATE,
            self::INSTALLMENT_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteScheduleGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlScheduleGuards();

            return;
        }

        throw new LogicException(
            "Las guardas P9.5 no están implementadas para {$driver}."
        );
    }

    private function createSqliteScheduleGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installments_guard_insert
BEFORE INSERT ON customer_receivable_installments
WHEN NEW.sequence < 1
    OR NEW.sequence > 120
    OR NEW.amount_minor < 1
    OR NEW.due_on IS NULL
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM customer_receivables receivable
        INNER JOIN commerce_sales sale
            ON sale.id =
                receivable.commerce_sale_id
            AND sale.organization_id =
                NEW.organization_id
        WHERE receivable.id =
                NEW.customer_receivable_id
            AND receivable.organization_id =
                NEW.organization_id
            AND sale.status = 'building'
            AND DATE(NEW.due_on) >=
                DATE(sale.sold_at)
    )
    OR EXISTS (
        SELECT 1
        FROM customer_receivable_installment_plans plan
        WHERE plan.organization_id =
                NEW.organization_id
            AND plan.customer_receivable_id =
                NEW.customer_receivable_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La cuota propia no pertenece a una deuda programable en preparacion.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installments_guard_update
BEFORE UPDATE ON customer_receivable_installments
BEGIN
    SELECT RAISE(
        ABORT,
        'Una cuota propia programada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installments_guard_delete
BEFORE DELETE ON customer_receivable_installments
BEGIN
    SELECT RAISE(
        ABORT,
        'Una cuota propia programada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installment_plans_guard_insert
BEFORE INSERT ON customer_receivable_installment_plans
WHEN NEW.installment_count < 2
    OR NEW.installment_count > 120
    OR NEW.strategy <> 'equal_monthly_fifo_v1'
    OR NEW.first_due_on IS NULL
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM customer_receivables receivable
        INNER JOIN commerce_sales sale
            ON sale.id =
                receivable.commerce_sale_id
            AND sale.organization_id =
                NEW.organization_id
        WHERE receivable.id =
                NEW.customer_receivable_id
            AND receivable.organization_id =
                NEW.organization_id
            AND sale.status = 'building'
            AND receivable.due_on IS NOT NULL
            AND DATE(receivable.due_on) =
                DATE(NEW.first_due_on)
            AND receivable.recognized_by_user_id =
                NEW.created_by_user_id
            AND receivable.amount_minor >=
                NEW.installment_count
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
            AND membership.user_id =
                NEW.created_by_user_id
            AND membership.active = 1
            AND membership.role IN (
                'admin',
                'operator'
            )
    )
    OR (
        SELECT COUNT(*)
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
    ) <> NEW.installment_count
    OR (
        SELECT COALESCE(
            SUM(installment.amount_minor),
            0
        )
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
    ) <> (
        SELECT receivable.amount_minor
        FROM customer_receivables receivable
        WHERE receivable.id =
                NEW.customer_receivable_id
            AND receivable.organization_id =
                NEW.organization_id
    )
    OR (
        SELECT MIN(installment.sequence)
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
    ) <> 1
    OR (
        SELECT MAX(installment.sequence)
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
    ) <> NEW.installment_count
    OR NOT EXISTS (
        SELECT 1
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
            AND installment.sequence = 1
            AND DATE(installment.due_on) =
                DATE(NEW.first_due_on)
    )
    OR EXISTS (
        SELECT 1
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
            AND installment.sequence <
                NEW.installment_count
            AND installment.amount_minor <> (
                SELECT
                    receivable.amount_minor
                    / NEW.installment_count
                FROM customer_receivables receivable
                WHERE receivable.id =
                        NEW.customer_receivable_id
                    AND receivable.organization_id =
                        NEW.organization_id
            )
    )
    OR EXISTS (
        SELECT 1
        FROM customer_receivable_installments installment
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
            AND installment.sequence =
                NEW.installment_count
            AND installment.amount_minor <> (
                SELECT
                    receivable.amount_minor
                    - (
                        (
                            receivable.amount_minor
                            / NEW.installment_count
                        )
                        * (
                            NEW.installment_count
                            - 1
                        )
                    )
                FROM customer_receivables receivable
                WHERE receivable.id =
                        NEW.customer_receivable_id
                    AND receivable.organization_id =
                        NEW.organization_id
            )
    )
    OR EXISTS (
        SELECT 1
        FROM customer_receivable_installments installment
        INNER JOIN customer_receivable_installments previous
            ON previous.organization_id =
                installment.organization_id
            AND previous.customer_receivable_id =
                installment.customer_receivable_id
            AND previous.sequence =
                installment.sequence - 1
        WHERE installment.organization_id =
                NEW.organization_id
            AND installment.customer_receivable_id =
                NEW.customer_receivable_id
            AND installment.sequence > 1
            AND DATE(installment.due_on) <=
                DATE(previous.due_on)
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El cronograma de cuotas propias no conserva cantidad, importes, orden y autoridad validos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installment_plans_guard_update
BEFORE UPDATE ON customer_receivable_installment_plans
BEGIN
    SELECT RAISE(
        ABORT,
        'Un cronograma de cuotas propias reconocido es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installment_plans_guard_delete
BEFORE DELETE ON customer_receivable_installment_plans
BEGIN
    SELECT RAISE(
        ABORT,
        'Un cronograma de cuotas propias reconocido no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sales_installment_schedule_guard_update
BEFORE UPDATE OF status ON commerce_sales
WHEN OLD.status = 'building'
    AND NEW.status = 'confirmed'
    AND EXISTS (
        SELECT 1
        FROM customer_receivables receivable
        WHERE receivable.organization_id =
                NEW.organization_id
            AND receivable.commerce_sale_id =
                NEW.id
            AND (
                (
                    EXISTS (
                        SELECT 1
                        FROM customer_receivable_installments installment
                        WHERE installment.organization_id =
                                receivable.organization_id
                            AND installment.customer_receivable_id =
                                receivable.id
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM customer_receivable_installment_plans plan
                        WHERE plan.organization_id =
                                receivable.organization_id
                            AND plan.customer_receivable_id =
                                receivable.id
                    )
                )
                OR EXISTS (
                    SELECT 1
                    FROM customer_receivable_installment_plans plan
                    WHERE plan.organization_id =
                            receivable.organization_id
                        AND plan.customer_receivable_id =
                            receivable.id
                        AND (
                            (
                                SELECT COUNT(*)
                                FROM customer_receivable_installments installment
                                WHERE installment.organization_id =
                                        receivable.organization_id
                                    AND installment.customer_receivable_id =
                                        receivable.id
                            ) <> plan.installment_count
                            OR (
                                SELECT COALESCE(
                                    SUM(installment.amount_minor),
                                    0
                                )
                                FROM customer_receivable_installments installment
                                WHERE installment.organization_id =
                                        receivable.organization_id
                                    AND installment.customer_receivable_id =
                                        receivable.id
                            ) <> receivable.amount_minor
                        )
                )
            )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La venta no puede confirmarse con un cronograma de cuotas incompleto.'
    );
END
SQL);
    }

    private function createMysqlScheduleGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installments_guard_insert
BEFORE INSERT ON customer_receivable_installments
FOR EACH ROW
BEGIN
    IF NEW.sequence < 1
        OR NEW.sequence > 120
        OR NEW.amount_minor < 1
        OR NEW.due_on IS NULL
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM customer_receivables receivable
            INNER JOIN commerce_sales sale
                ON sale.id =
                    receivable.commerce_sale_id
                AND sale.organization_id =
                    NEW.organization_id
            WHERE receivable.id =
                    NEW.customer_receivable_id
                AND receivable.organization_id =
                    NEW.organization_id
                AND sale.status = 'building'
                AND DATE(NEW.due_on) >=
                    DATE(sale.sold_at)
        )
        OR EXISTS (
            SELECT 1
            FROM customer_receivable_installment_plans plan
            WHERE plan.organization_id =
                    NEW.organization_id
                AND plan.customer_receivable_id =
                    NEW.customer_receivable_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La cuota propia no pertenece a una deuda programable en preparacion.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installments_guard_update
BEFORE UPDATE ON customer_receivable_installments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una cuota propia programada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installments_guard_delete
BEFORE DELETE ON customer_receivable_installments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una cuota propia programada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installment_plans_guard_insert
BEFORE INSERT ON customer_receivable_installment_plans
FOR EACH ROW
BEGIN
    IF NEW.installment_count < 2
        OR NEW.installment_count > 120
        OR BINARY NEW.strategy <>
            BINARY 'equal_monthly_fifo_v1'
        OR NEW.first_due_on IS NULL
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM customer_receivables receivable
            INNER JOIN commerce_sales sale
                ON sale.id =
                    receivable.commerce_sale_id
                AND sale.organization_id =
                    NEW.organization_id
            WHERE receivable.id =
                    NEW.customer_receivable_id
                AND receivable.organization_id =
                    NEW.organization_id
                AND sale.status = 'building'
                AND receivable.due_on IS NOT NULL
                AND DATE(receivable.due_on) =
                    DATE(NEW.first_due_on)
                AND receivable.recognized_by_user_id =
                    NEW.created_by_user_id
                AND receivable.amount_minor >=
                    NEW.installment_count
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
                AND membership.user_id =
                    NEW.created_by_user_id
                AND membership.active = 1
                AND membership.role IN (
                    'admin',
                    'operator'
                )
        )
        OR (
            SELECT COUNT(*)
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
        ) <> NEW.installment_count
        OR (
            SELECT COALESCE(
                SUM(installment.amount_minor),
                0
            )
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
        ) <> (
            SELECT receivable.amount_minor
            FROM customer_receivables receivable
            WHERE receivable.id =
                    NEW.customer_receivable_id
                AND receivable.organization_id =
                    NEW.organization_id
        )
        OR (
            SELECT MIN(installment.sequence)
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
        ) <> 1
        OR (
            SELECT MAX(installment.sequence)
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
        ) <> NEW.installment_count
        OR NOT EXISTS (
            SELECT 1
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
                AND installment.sequence = 1
                AND DATE(installment.due_on) =
                    DATE(NEW.first_due_on)
        )
        OR EXISTS (
            SELECT 1
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
                AND installment.sequence <
                    NEW.installment_count
                AND installment.amount_minor <> (
                    SELECT
                        receivable.amount_minor
                        DIV NEW.installment_count
                    FROM customer_receivables receivable
                    WHERE receivable.id =
                            NEW.customer_receivable_id
                        AND receivable.organization_id =
                            NEW.organization_id
                )
        )
        OR EXISTS (
            SELECT 1
            FROM customer_receivable_installments installment
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
                AND installment.sequence =
                    NEW.installment_count
                AND installment.amount_minor <> (
                    SELECT
                        receivable.amount_minor
                        - (
                            (
                                receivable.amount_minor
                                DIV NEW.installment_count
                            )
                            * (
                                NEW.installment_count
                                - 1
                            )
                        )
                    FROM customer_receivables receivable
                    WHERE receivable.id =
                            NEW.customer_receivable_id
                        AND receivable.organization_id =
                            NEW.organization_id
                )
        )
        OR EXISTS (
            SELECT 1
            FROM customer_receivable_installments installment
            INNER JOIN customer_receivable_installments previous
                ON previous.organization_id =
                    installment.organization_id
                AND previous.customer_receivable_id =
                    installment.customer_receivable_id
                AND previous.sequence =
                    installment.sequence - 1
            WHERE installment.organization_id =
                    NEW.organization_id
                AND installment.customer_receivable_id =
                    NEW.customer_receivable_id
                AND installment.sequence > 1
                AND DATE(installment.due_on) <=
                    DATE(previous.due_on)
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El cronograma de cuotas propias no conserva cantidad, importes, orden y autoridad validos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installment_plans_guard_update
BEFORE UPDATE ON customer_receivable_installment_plans
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un cronograma de cuotas propias reconocido es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivable_installment_plans_guard_delete
BEFORE DELETE ON customer_receivable_installment_plans
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un cronograma de cuotas propias reconocido no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_sales_installment_schedule_guard_update
BEFORE UPDATE ON commerce_sales
FOR EACH ROW
BEGIN
    IF OLD.status = 'building'
        AND NEW.status = 'confirmed'
        AND EXISTS (
            SELECT 1
            FROM customer_receivables receivable
            WHERE receivable.organization_id =
                    NEW.organization_id
                AND receivable.commerce_sale_id =
                    NEW.id
                AND (
                    (
                        EXISTS (
                            SELECT 1
                            FROM customer_receivable_installments installment
                            WHERE installment.organization_id =
                                    receivable.organization_id
                                AND installment.customer_receivable_id =
                                    receivable.id
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM customer_receivable_installment_plans plan
                            WHERE plan.organization_id =
                                    receivable.organization_id
                                AND plan.customer_receivable_id =
                                    receivable.id
                        )
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM customer_receivable_installment_plans plan
                        WHERE plan.organization_id =
                                receivable.organization_id
                            AND plan.customer_receivable_id =
                                receivable.id
                            AND (
                                (
                                    SELECT COUNT(*)
                                    FROM customer_receivable_installments installment
                                    WHERE installment.organization_id =
                                            receivable.organization_id
                                        AND installment.customer_receivable_id =
                                            receivable.id
                                ) <> plan.installment_count
                                OR (
                                    SELECT COALESCE(
                                        SUM(installment.amount_minor),
                                        0
                                    )
                                    FROM customer_receivable_installments installment
                                    WHERE installment.organization_id =
                                            receivable.organization_id
                                        AND installment.customer_receivable_id =
                                            receivable.id
                                ) <> receivable.amount_minor
                            )
                    )
                )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La venta no puede confirmarse con un cronograma de cuotas incompleto.';
    END IF;
END
SQL);
    }

    private function replaceCreditPolicyGuards(): void
    {
        foreach ([
            self::RECEIVABLE_CREDIT_INSERT,
            self::CREDIT_OVERRIDE_INSERT,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteCreditPolicyGuards();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlCreditPolicyGuards();

            return;
        }

        throw new LogicException(
            "Las guardas de política P9.5 no están implementadas para {$driver}."
        );
    }

    private function createSqliteCreditPolicyGuards(): void
    {
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
        SELECT SUM(balance.outstanding_minor)
        FROM customer_receivable_installment_balances balance
        WHERE balance.organization_id =
                NEW.organization_id
            AND balance.business_party_id =
                NEW.business_party_id
            AND balance.currency_code =
                NEW.currency_code
    ), 0)
    OR NEW.overdue_minor <> COALESCE((
        SELECT SUM(balance.outstanding_minor)
        FROM customer_receivable_installment_balances balance
        WHERE balance.organization_id =
                NEW.organization_id
            AND balance.business_party_id =
                NEW.business_party_id
            AND balance.currency_code =
                NEW.currency_code
            AND balance.due_on IS NOT NULL
            AND DATE(balance.due_on) < DATE((
                SELECT sale.sold_at
                FROM commerce_sales sale
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id =
                        NEW.organization_id
            ))
            AND balance.outstanding_minor > 0
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
                - julianday(DATE(balance.due_on))
                AS INTEGER
            )
        )
        FROM customer_receivable_installment_balances balance
        WHERE balance.organization_id =
                NEW.organization_id
            AND balance.business_party_id =
                NEW.business_party_id
            AND balance.currency_code =
                NEW.currency_code
            AND balance.due_on IS NOT NULL
            AND DATE(balance.due_on) < DATE((
                SELECT sale.sold_at
                FROM commerce_sales sale
                WHERE sale.id = NEW.commerce_sale_id
                    AND sale.organization_id =
                        NEW.organization_id
            ))
            AND balance.outstanding_minor > 0
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
            SELECT SUM(balance.outstanding_minor)
            FROM customer_receivable_installment_balances balance
            WHERE balance.organization_id =
                    NEW.organization_id
                AND balance.business_party_id =
                    NEW.business_party_id
                AND balance.currency_code =
                    NEW.currency_code
        ), 0)
    )
    OR (
        NEW.credit_decision <> 'legacy_admin'
        AND NEW.credit_overdue_minor <> COALESCE((
            SELECT SUM(balance.outstanding_minor)
            FROM customer_receivable_installment_balances balance
            WHERE balance.organization_id =
                    NEW.organization_id
                AND balance.business_party_id =
                    NEW.business_party_id
                AND balance.currency_code =
                    NEW.currency_code
                AND balance.due_on IS NOT NULL
                AND DATE(balance.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id = NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND balance.outstanding_minor > 0
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
                    - julianday(DATE(balance.due_on))
                    AS INTEGER
                )
            )
            FROM customer_receivable_installment_balances balance
            WHERE balance.organization_id =
                    NEW.organization_id
                AND balance.business_party_id =
                    NEW.business_party_id
                AND balance.currency_code =
                    NEW.currency_code
                AND balance.due_on IS NOT NULL
                AND DATE(balance.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id = NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND balance.outstanding_minor > 0
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

    private function createMysqlCreditPolicyGuards(): void
    {
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
            SELECT SUM(balance.outstanding_minor)
            FROM customer_receivable_installment_balances balance
            WHERE balance.organization_id =
                    NEW.organization_id
                AND balance.business_party_id =
                    NEW.business_party_id
                AND BINARY balance.currency_code =
                    BINARY NEW.currency_code
        ), 0)
        OR NEW.overdue_minor <> COALESCE((
            SELECT SUM(balance.outstanding_minor)
            FROM customer_receivable_installment_balances balance
            WHERE balance.organization_id =
                    NEW.organization_id
                AND balance.business_party_id =
                    NEW.business_party_id
                AND BINARY balance.currency_code =
                    BINARY NEW.currency_code
                AND balance.due_on IS NOT NULL
                AND DATE(balance.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id =
                            NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND balance.outstanding_minor > 0
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
                    DATE(balance.due_on)
                )
            )
            FROM customer_receivable_installment_balances balance
            WHERE balance.organization_id =
                    NEW.organization_id
                AND balance.business_party_id =
                    NEW.business_party_id
                AND BINARY balance.currency_code =
                    BINARY NEW.currency_code
                AND balance.due_on IS NOT NULL
                AND DATE(balance.due_on) < DATE((
                    SELECT sale.sold_at
                    FROM commerce_sales sale
                    WHERE sale.id =
                            NEW.commerce_sale_id
                        AND sale.organization_id =
                            NEW.organization_id
                ))
                AND balance.outstanding_minor > 0
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
                SELECT SUM(balance.outstanding_minor)
                FROM customer_receivable_installment_balances balance
                WHERE balance.organization_id =
                        NEW.organization_id
                    AND balance.business_party_id =
                        NEW.business_party_id
                    AND BINARY balance.currency_code =
                        BINARY NEW.currency_code
            ), 0)
        )
        OR (
            NEW.credit_decision <> 'legacy_admin'
            AND NEW.credit_overdue_minor <> COALESCE((
                SELECT SUM(balance.outstanding_minor)
                FROM customer_receivable_installment_balances balance
                WHERE balance.organization_id =
                        NEW.organization_id
                    AND balance.business_party_id =
                        NEW.business_party_id
                    AND BINARY balance.currency_code =
                        BINARY NEW.currency_code
                    AND balance.due_on IS NOT NULL
                    AND DATE(balance.due_on) < DATE((
                        SELECT sale.sold_at
                        FROM commerce_sales sale
                        WHERE sale.id =
                                NEW.commerce_sale_id
                            AND sale.organization_id =
                                NEW.organization_id
                    ))
                    AND balance.outstanding_minor > 0
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
                        DATE(balance.due_on)
                    )
                )
                FROM customer_receivable_installment_balances balance
                WHERE balance.organization_id =
                        NEW.organization_id
                    AND balance.business_party_id =
                        NEW.business_party_id
                    AND BINARY balance.currency_code =
                        BINARY NEW.currency_code
                    AND balance.due_on IS NOT NULL
                    AND DATE(balance.due_on) < DATE((
                        SELECT sale.sold_at
                        FROM commerce_sales sale
                        WHERE sale.id =
                                NEW.commerce_sale_id
                            AND sale.organization_id =
                                NEW.organization_id
                    ))
                    AND balance.outstanding_minor > 0
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
