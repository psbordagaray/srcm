<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CREDIT_CROSS_GUARD =
        'supplier_credit_application_advance_cross_guard_insert';

    public function up(): void
    {
        Schema::create(
            'supplier_advance_applications',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'supplier_advance_id'
                )
                    ->constrained('supplier_advances')
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_obligation_id'
                )
                    ->constrained('purchase_obligations')
                    ->restrictOnDelete();
                $table->foreignId('supplier_id')
                    ->constrained('suppliers')
                    ->restrictOnDelete();
                $table->foreignId(
                    'beneficiary_business_party_id'
                )
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('amount_minor');
                $table->text(
                    'application_note'
                )->nullable();
                $table->string(
                    'idempotency_key',
                    100
                );
                $table->char('fingerprint', 64);
                $table->foreignId(
                    'applied_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('applied_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'supplier_advance_application_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'supplier_advance_id',
                        'applied_at',
                    ],
                    'supplier_advance_application_source_index'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_obligation_id',
                        'applied_at',
                    ],
                    'supplier_advance_application_obligation_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'Supplier advance applications are append-only and this migration is intentionally irreversible.'
        );
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();

        DB::unprepared(
            'DROP TRIGGER IF EXISTS '
            .self::CREDIT_CROSS_GUARD
        );

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
            "P9.7g no implementa guards para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_application_guard_insert
BEFORE INSERT ON supplier_advance_applications
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM supplier_advances advance_row
            JOIN suppliers supplier
              ON supplier.id =
                    advance_row.supplier_id
             AND supplier.organization_id =
                    advance_row.organization_id
            JOIN purchase_obligations obligation
              ON obligation.id =
                    NEW.purchase_obligation_id
             AND obligation.organization_id =
                    NEW.organization_id
             AND obligation.supplier_id =
                    NEW.supplier_id
             AND obligation.currency_code =
                    NEW.currency_code
             AND obligation.beneficiary_business_party_id =
                    NEW.beneficiary_business_party_id
            WHERE advance_row.id =
                    NEW.supplier_advance_id
              AND advance_row.organization_id =
                    NEW.organization_id
              AND advance_row.supplier_id =
                    NEW.supplier_id
              AND advance_row.currency_code =
                    NEW.currency_code
              AND supplier.business_party_id =
                    NEW.beneficiary_business_party_id
        )
        THEN RAISE(
            ABORT,
            'supplier_advance_application_relation_invalid'
        )
    END;

    SELECT CASE
        WHEN NEW.amount_minor <= 0
          OR length(trim(NEW.idempotency_key)) = 0
          OR length(NEW.fingerprint) != 64
          OR NOT EXISTS (
              SELECT 1
              FROM organization_memberships membership
              WHERE membership.organization_id =
                    NEW.organization_id
                AND membership.user_id =
                    NEW.applied_by_user_id
                AND membership.active = 1
                AND membership.role = 'admin'
          )
          OR EXISTS (
              SELECT 1
              FROM purchase_payment_requests request
              WHERE request.organization_id =
                    NEW.organization_id
                AND request.purchase_obligation_id =
                    NEW.purchase_obligation_id
                AND request.status IN (
                    'pending',
                    'approved'
                )
          )
        THEN RAISE(
            ABORT,
            'supplier_advance_application_state_invalid'
        )
    END;

    SELECT CASE
        WHEN (
            COALESCE(
                (
                    SELECT SUM(application.amount_minor)
                    FROM supplier_advance_applications application
                    WHERE application.organization_id =
                            NEW.organization_id
                      AND application.supplier_advance_id =
                            NEW.supplier_advance_id
                ),
                0
            ) + NEW.amount_minor
        ) > (
            SELECT advance_row.amount_minor
            FROM supplier_advances advance_row
            WHERE advance_row.id =
                    NEW.supplier_advance_id
        )
        THEN RAISE(
            ABORT,
            'supplier_advance_application_source_overdraw'
        )
    END;

    SELECT CASE
        WHEN (
            COALESCE(
                (
                    SELECT SUM(application.amount_minor)
                    FROM supplier_advance_applications application
                    WHERE application.organization_id =
                            NEW.organization_id
                      AND application.purchase_obligation_id =
                            NEW.purchase_obligation_id
                ),
                0
            )
            + COALESCE(
                (
                    SELECT SUM(application.amount_minor)
                    FROM supplier_credit_applications application
                    WHERE application.organization_id =
                            NEW.organization_id
                      AND application.purchase_obligation_id =
                            NEW.purchase_obligation_id
                ),
                0
            )
            + COALESCE(
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
            + NEW.amount_minor
        ) > (
            SELECT obligation.amount_minor
            FROM purchase_obligations obligation
            WHERE obligation.id =
                    NEW.purchase_obligation_id
        )
        THEN RAISE(
            ABORT,
            'supplier_advance_application_obligation_overdraw'
        )
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_application_guard_update
BEFORE UPDATE ON supplier_advance_applications
FOR EACH ROW
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_application_immutable'
    );
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_application_guard_delete
BEFORE DELETE ON supplier_advance_applications
FOR EACH ROW
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_advance_application_immutable'
    );
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_advance_cross_guard_insert
BEFORE INSERT ON supplier_credit_applications
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN (
            COALESCE(
                (
                    SELECT SUM(application.amount_minor)
                    FROM supplier_advance_applications application
                    WHERE application.organization_id =
                            NEW.organization_id
                      AND application.purchase_obligation_id =
                            NEW.purchase_obligation_id
                ),
                0
            )
            + COALESCE(
                (
                    SELECT SUM(application.amount_minor)
                    FROM supplier_credit_applications application
                    WHERE application.organization_id =
                            NEW.organization_id
                      AND application.purchase_obligation_id =
                            NEW.purchase_obligation_id
                ),
                0
            )
            + COALESCE(
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
            + NEW.amount_minor
        ) > (
            SELECT obligation.amount_minor
            FROM purchase_obligations obligation
            WHERE obligation.id =
                    NEW.purchase_obligation_id
        )
        THEN RAISE(
            ABORT,
            'supplier_credit_application_advance_cross_overdraw'
        )
    END;
END;
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_application_guard_insert
BEFORE INSERT ON supplier_advance_applications
FOR EACH ROW
BEGIN
    DECLARE relation_count BIGINT DEFAULT 0;
    DECLARE member_count BIGINT DEFAULT 0;
    DECLARE active_requests BIGINT DEFAULT 0;
    DECLARE source_total BIGINT DEFAULT NULL;
    DECLARE credit_applied BIGINT DEFAULT 0;
    DECLARE executed_total BIGINT DEFAULT 0;
    DECLARE obligation_total BIGINT DEFAULT NULL;

    SELECT COUNT(*),
           MAX(advance_row.amount_minor),
           MAX(obligation.amount_minor)
      INTO relation_count,
           source_total,
           obligation_total
      FROM supplier_advances advance_row
      JOIN suppliers supplier
        ON supplier.id =
            advance_row.supplier_id
       AND supplier.organization_id =
            advance_row.organization_id
      JOIN purchase_obligations obligation
        ON obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
       AND obligation.supplier_id =
            NEW.supplier_id
       AND BINARY obligation.currency_code =
            BINARY NEW.currency_code
       AND obligation.beneficiary_business_party_id =
            NEW.beneficiary_business_party_id
     WHERE advance_row.id =
            NEW.supplier_advance_id
       AND advance_row.organization_id =
            NEW.organization_id
       AND advance_row.supplier_id =
            NEW.supplier_id
       AND BINARY advance_row.currency_code =
            BINARY NEW.currency_code
       AND supplier.business_party_id =
            NEW.beneficiary_business_party_id;

    SELECT COUNT(*)
      INTO member_count
      FROM organization_memberships membership
     WHERE membership.organization_id =
            NEW.organization_id
       AND membership.user_id =
            NEW.applied_by_user_id
       AND membership.active = 1
       AND membership.role = 'admin';

    SELECT COUNT(*)
      INTO active_requests
      FROM purchase_payment_requests request
     WHERE request.organization_id =
            NEW.organization_id
       AND request.purchase_obligation_id =
            NEW.purchase_obligation_id
       AND request.status IN (
            'pending',
            'approved'
       );

    SELECT COALESCE(SUM(application.amount_minor), 0)
      INTO credit_applied
      FROM supplier_credit_applications application
     WHERE application.organization_id =
            NEW.organization_id
       AND application.purchase_obligation_id =
            NEW.purchase_obligation_id;

    SELECT COALESCE(SUM(execution.amount_minor), 0)
      INTO executed_total
      FROM purchase_payment_executions execution
     WHERE execution.organization_id =
            NEW.organization_id
       AND execution.purchase_obligation_id =
            NEW.purchase_obligation_id;

    IF relation_count <> 1
       OR member_count <> 1
       OR active_requests > 0
       OR source_total IS NULL
       OR obligation_total IS NULL
       OR NEW.amount_minor <= 0
       OR CHAR_LENGTH(TRIM(
            NEW.idempotency_key
       )) = 0
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.amount_minor > source_total
       OR credit_applied
            + executed_total
            + NEW.amount_minor
            > obligation_total
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_advance_application_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_application_guard_update
BEFORE UPDATE ON supplier_advance_applications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_application_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_advance_application_guard_delete
BEFORE DELETE ON supplier_advance_applications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_advance_application_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_advance_cross_guard_insert
BEFORE INSERT ON supplier_credit_applications
FOR EACH ROW
BEGIN
    DECLARE advance_applied BIGINT DEFAULT 0;
    DECLARE executed_total BIGINT DEFAULT 0;
    DECLARE obligation_total BIGINT DEFAULT NULL;

    SELECT COALESCE(SUM(application.amount_minor), 0)
      INTO advance_applied
      FROM supplier_advance_applications application
     WHERE application.organization_id =
            NEW.organization_id
       AND application.purchase_obligation_id =
            NEW.purchase_obligation_id;

    SELECT COALESCE(SUM(execution.amount_minor), 0)
      INTO executed_total
      FROM purchase_payment_executions execution
     WHERE execution.organization_id =
            NEW.organization_id
       AND execution.purchase_obligation_id =
            NEW.purchase_obligation_id;

    SELECT obligation.amount_minor
      INTO obligation_total
      FROM purchase_obligations obligation
     WHERE obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
     LIMIT 1;

    IF obligation_total IS NULL
       OR advance_applied
            + executed_total
            + NEW.amount_minor
            > obligation_total
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_credit_application_advance_cross_overdraw';
    END IF;
END
SQL);
    }
};
