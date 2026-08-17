<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'supplier_credit_applications',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('supplier_credit_note_id')
                    ->constrained('supplier_credit_notes')
                    ->restrictOnDelete();
                $table->foreignId('purchase_obligation_id')
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
                $table->text('application_note')->nullable();
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->foreignId('applied_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('applied_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'supplier_credit_application_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'supplier_credit_note_id',
                        'applied_at',
                    ],
                    'supplier_credit_application_note_index'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_obligation_id',
                        'applied_at',
                    ],
                    'supplier_credit_application_obligation_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'Supplier credit applications are append-only and this migration is intentionally irreversible.'
        );
    }

    private function createGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteGuards();

            return;
        }

        if (in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            $this->createMysqlGuards();
        }
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_guard_insert
BEFORE INSERT ON supplier_credit_applications
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM supplier_credit_notes n
            JOIN suppliers s
              ON s.id = n.supplier_id
             AND s.organization_id = n.organization_id
            JOIN purchase_obligations o
              ON o.id = NEW.purchase_obligation_id
             AND o.organization_id = NEW.organization_id
             AND o.supplier_id = NEW.supplier_id
             AND o.currency_code = NEW.currency_code
             AND o.beneficiary_business_party_id =
                 NEW.beneficiary_business_party_id
            WHERE n.id = NEW.supplier_credit_note_id
              AND n.organization_id = NEW.organization_id
              AND n.supplier_id = NEW.supplier_id
              AND n.currency_code = NEW.currency_code
              AND s.business_party_id =
                  NEW.beneficiary_business_party_id
        )
        THEN RAISE(
            ABORT,
            'supplier_credit_application_relation_invalid'
        )
    END;

    SELECT CASE
        WHEN NEW.amount_minor <= 0
          OR length(trim(NEW.idempotency_key)) = 0
          OR length(NEW.fingerprint) != 64
          OR EXISTS (
              SELECT 1
              FROM purchase_payment_requests r
              WHERE r.organization_id = NEW.organization_id
                AND r.purchase_obligation_id =
                    NEW.purchase_obligation_id
                AND r.status IN ('pending', 'approved')
          )
        THEN RAISE(
            ABORT,
            'supplier_credit_application_state_invalid'
        )
    END;

    SELECT CASE
        WHEN (
            COALESCE(
                (
                    SELECT SUM(a.amount_minor)
                    FROM supplier_credit_applications a
                    WHERE a.organization_id =
                        NEW.organization_id
                      AND a.supplier_credit_note_id =
                        NEW.supplier_credit_note_id
                ),
                0
            ) + NEW.amount_minor
        ) > (
            SELECT n.amount_minor
            FROM supplier_credit_notes n
            WHERE n.id = NEW.supplier_credit_note_id
        )
        THEN RAISE(
            ABORT,
            'supplier_credit_application_source_overdraw'
        )
    END;

    SELECT CASE
        WHEN (
            COALESCE(
                (
                    SELECT SUM(a.amount_minor)
                    FROM supplier_credit_applications a
                    WHERE a.organization_id =
                        NEW.organization_id
                      AND a.purchase_obligation_id =
                        NEW.purchase_obligation_id
                ),
                0
            )
            + COALESCE(
                (
                    SELECT SUM(e.amount_minor)
                    FROM purchase_payment_executions e
                    WHERE e.organization_id =
                        NEW.organization_id
                      AND e.purchase_obligation_id =
                        NEW.purchase_obligation_id
                ),
                0
            )
            + NEW.amount_minor
        ) > (
            SELECT o.amount_minor
            FROM purchase_obligations o
            WHERE o.id = NEW.purchase_obligation_id
        )
        THEN RAISE(
            ABORT,
            'supplier_credit_application_obligation_overdraw'
        )
    END;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_guard_update
BEFORE UPDATE ON supplier_credit_applications
FOR EACH ROW
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_credit_application_immutable'
    );
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_guard_delete
BEFORE DELETE ON supplier_credit_applications
FOR EACH ROW
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_credit_application_immutable'
    );
END;
SQL);
    }

    private function createMysqlGuards(): void
    {
        /*
         * Do not aggregate supplier_credit_applications from
         * this table's own INSERT trigger. Cumulative caps
         * remain transactionally enforced in manager/model
         * until isolated MySQL/MariaDB hardening proves a
         * portable boundary strategy.
         */
        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_guard_insert
BEFORE INSERT ON supplier_credit_applications
FOR EACH ROW
BEGIN
    DECLARE relation_count BIGINT DEFAULT 0;
    DECLARE source_total BIGINT DEFAULT NULL;
    DECLARE obligation_total BIGINT DEFAULT NULL;
    DECLARE executed_total BIGINT DEFAULT 0;
    DECLARE active_requests BIGINT DEFAULT 0;

    SELECT COUNT(*),
           MAX(n.amount_minor),
           MAX(o.amount_minor)
      INTO relation_count,
           source_total,
           obligation_total
      FROM supplier_credit_notes n
      JOIN suppliers s
        ON s.id = n.supplier_id
       AND s.organization_id = n.organization_id
      JOIN purchase_obligations o
        ON o.id = NEW.purchase_obligation_id
       AND o.organization_id = NEW.organization_id
       AND o.supplier_id = NEW.supplier_id
       AND o.currency_code = NEW.currency_code
       AND o.beneficiary_business_party_id =
           NEW.beneficiary_business_party_id
     WHERE n.id = NEW.supplier_credit_note_id
       AND n.organization_id = NEW.organization_id
       AND n.supplier_id = NEW.supplier_id
       AND n.currency_code = NEW.currency_code
       AND s.business_party_id =
           NEW.beneficiary_business_party_id;

    SELECT COALESCE(SUM(e.amount_minor), 0)
      INTO executed_total
      FROM purchase_payment_executions e
     WHERE e.organization_id = NEW.organization_id
       AND e.purchase_obligation_id =
           NEW.purchase_obligation_id;

    SELECT COUNT(*)
      INTO active_requests
      FROM purchase_payment_requests r
     WHERE r.organization_id = NEW.organization_id
       AND r.purchase_obligation_id =
           NEW.purchase_obligation_id
       AND r.status IN ('pending', 'approved');

    IF relation_count != 1
       OR source_total IS NULL
       OR obligation_total IS NULL
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_credit_application_relation_invalid';
    END IF;

    IF NEW.amount_minor <= 0
       OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
       OR CHAR_LENGTH(NEW.fingerprint) != 64
       OR active_requests > 0
       OR NEW.amount_minor > source_total
       OR executed_total + NEW.amount_minor
            > obligation_total
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_credit_application_state_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_guard_update
BEFORE UPDATE ON supplier_credit_applications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_credit_application_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER supplier_credit_application_guard_delete
BEFORE DELETE ON supplier_credit_applications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'supplier_credit_application_immutable';
END
SQL);
    }
};
