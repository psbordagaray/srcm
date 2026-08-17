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
            'purchase_payment_group_requests',
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
                    'beneficiary_business_party_id'
                )
                    ->constrained('business_parties')
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
                $table->char('currency_code', 3);
                $table->string('status', 32);
                $table->text(
                    'request_note'
                )->nullable();
                $table->string(
                    'request_idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestamp('requested_at');
                $table->foreignId(
                    'approved_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->text(
                    'approval_note'
                )->nullable();
                $table->string(
                    'approval_idempotency_key',
                    180
                )->nullable();
                $table->char(
                    'approval_fingerprint',
                    64
                )->nullable();
                $table->timestamp(
                    'approved_at'
                )->nullable();
                $table->foreignId(
                    'resolved_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->text(
                    'resolution_note'
                )->nullable();
                $table->string(
                    'resolution_idempotency_key',
                    180
                )->nullable();
                $table->timestamp(
                    'resolved_at'
                )->nullable();
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'request_idempotency_key',
                    ],
                    'purchase_pay_group_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'supplier_id',
                        'beneficiary_business_party_id',
                        'currency_code',
                        'status',
                    ],
                    'purchase_pay_group_scope_index'
                );
            }
        );

        Schema::create(
            'purchase_payment_group_request_items',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_payment_group_request_id'
                )
                    ->constrained(
                        'purchase_payment_group_requests'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_obligation_id'
                )
                    ->constrained(
                        'purchase_obligations'
                    )
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->char('fingerprint', 64);
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'purchase_payment_group_request_id',
                        'purchase_obligation_id',
                    ],
                    'purchase_pay_group_item_obligation_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_obligation_id',
                    ],
                    'purchase_pay_group_item_obligation_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.7h conserva solicitudes agrupadas e imputaciones auditables; no admite rollback automático.'
        );
    }

    private function createGuards(): void
    {
        foreach ([
            'supplier_advance_application_group_guard_insert',
            'supplier_credit_application_group_guard_insert',
            'purchase_payment_request_group_guard_insert',
            'purchase_payment_group_item_guard_delete',
            'purchase_payment_group_item_guard_update',
            'purchase_payment_group_item_guard_insert',
            'purchase_payment_group_request_guard_delete',
            'purchase_payment_group_request_guard_update',
            'purchase_payment_group_request_guard_insert',
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
            "P9.7h no implementa guards para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_request_guard_insert
BEFORE INSERT ON purchase_payment_group_requests
WHEN NEW.status <> 'pending'
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code)
        <> NEW.currency_code
    OR LENGTH(TRIM(
        NEW.request_idempotency_key
    )) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.requested_at IS NULL
    OR NEW.created_at IS NULL
    OR NEW.approved_by_user_id IS NOT NULL
    OR NEW.approval_idempotency_key IS NOT NULL
    OR NEW.approval_fingerprint IS NOT NULL
    OR NEW.approved_at IS NOT NULL
    OR NEW.resolved_by_user_id IS NOT NULL
    OR NEW.resolution_idempotency_key IS NOT NULL
    OR NEW.resolved_at IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM suppliers supplier
        WHERE supplier.id =
                NEW.supplier_id
          AND supplier.organization_id =
                NEW.organization_id
          AND supplier.active = 1
    )
    OR NOT EXISTS (
        SELECT 1
        FROM business_parties party
        WHERE party.id =
                NEW.beneficiary_business_party_id
          AND party.organization_id =
                NEW.organization_id
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
        'purchase_payment_group_request_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_request_guard_update
BEFORE UPDATE ON purchase_payment_group_requests
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
    OR OLD.supplier_id <> NEW.supplier_id
    OR OLD.beneficiary_business_party_id
        <> NEW.beneficiary_business_party_id
    OR OLD.origin_financial_account_id
        <> NEW.origin_financial_account_id
    OR OLD.requested_by_user_id
        <> NEW.requested_by_user_id
    OR OLD.currency_code <> NEW.currency_code
    OR COALESCE(OLD.request_note, '')
        <> COALESCE(NEW.request_note, '')
    OR OLD.request_idempotency_key
        <> NEW.request_idempotency_key
    OR OLD.fingerprint <> NEW.fingerprint
    OR OLD.requested_at <> NEW.requested_at
    OR OLD.created_at <> NEW.created_at
    OR NEW.status = 'executed'
    OR NEW.status NOT IN (
        'pending',
        'approved',
        'rejected',
        'cancelled'
    )
    OR (
        OLD.status = 'pending'
        AND NEW.status NOT IN (
            'approved',
            'rejected',
            'cancelled'
        )
    )
    OR (
        OLD.status = 'approved'
        AND NEW.status <> 'cancelled'
    )
    OR OLD.status IN (
        'rejected',
        'cancelled'
    )
    OR (
        NEW.status = 'approved'
        AND (
            NEW.approved_by_user_id IS NULL
            OR NEW.approved_by_user_id =
                NEW.requested_by_user_id
            OR LENGTH(TRIM(
                COALESCE(
                    NEW.approval_idempotency_key,
                    ''
                )
            )) = 0
            OR LENGTH(
                COALESCE(
                    NEW.approval_fingerprint,
                    ''
                )
            ) <> 64
            OR NEW.approved_at IS NULL
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
            OR (
                SELECT COUNT(*)
                FROM purchase_payment_group_request_items item
                WHERE item.purchase_payment_group_request_id =
                        NEW.id
                  AND item.organization_id =
                        NEW.organization_id
            ) < 2
            OR EXISTS (
                SELECT 1
                FROM purchase_payment_group_request_items item
                JOIN purchase_payment_requests request
                  ON request.purchase_obligation_id =
                        item.purchase_obligation_id
                 AND request.organization_id =
                        item.organization_id
                 AND request.status IN (
                        'pending',
                        'approved'
                 )
                WHERE item.purchase_payment_group_request_id =
                        NEW.id
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_payment_group_request_items item
                JOIN purchase_obligations obligation
                  ON obligation.id =
                        item.purchase_obligation_id
                 AND obligation.organization_id =
                        item.organization_id
                WHERE item.purchase_payment_group_request_id =
                        NEW.id
                  AND item.amount_minor > (
                        obligation.amount_minor
                        - COALESCE(
                            (
                                SELECT SUM(execution.amount_minor)
                                FROM purchase_payment_executions execution
                                WHERE execution.organization_id =
                                        item.organization_id
                                  AND execution.purchase_obligation_id =
                                        item.purchase_obligation_id
                            ),
                            0
                        )
                        - COALESCE(
                            (
                                SELECT SUM(application.amount_minor)
                                FROM supplier_credit_applications application
                                WHERE application.organization_id =
                                        item.organization_id
                                  AND application.purchase_obligation_id =
                                        item.purchase_obligation_id
                            ),
                            0
                        )
                        - COALESCE(
                            (
                                SELECT SUM(application.amount_minor)
                                FROM supplier_advance_applications application
                                WHERE application.organization_id =
                                        item.organization_id
                                  AND application.purchase_obligation_id =
                                        item.purchase_obligation_id
                            ),
                            0
                        )
                  )
            )
            OR EXISTS (
                SELECT 1
                FROM purchase_payment_group_request_items current_item
                JOIN purchase_payment_group_request_items other_item
                  ON other_item.purchase_obligation_id =
                        current_item.purchase_obligation_id
                 AND other_item.organization_id =
                        current_item.organization_id
                 AND other_item.purchase_payment_group_request_id
                        <> current_item.purchase_payment_group_request_id
                JOIN purchase_payment_group_requests other_group
                  ON other_group.id =
                        other_item.purchase_payment_group_request_id
                 AND other_group.status IN (
                        'pending',
                        'approved'
                 )
                WHERE current_item.purchase_payment_group_request_id =
                        NEW.id
            )
        )
    )
    OR (
        NEW.status IN (
            'rejected',
            'cancelled'
        )
        AND (
            NEW.resolved_by_user_id IS NULL
            OR LENGTH(TRIM(
                COALESCE(
                    NEW.resolution_note,
                    ''
                )
            )) = 0
            OR LENGTH(TRIM(
                COALESCE(
                    NEW.resolution_idempotency_key,
                    ''
                )
            )) = 0
            OR NEW.resolved_at IS NULL
        )
    )
    OR (
        NEW.status = 'rejected'
        AND (
            OLD.status <> 'pending'
            OR NEW.resolved_by_user_id =
                NEW.requested_by_user_id
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id =
                        NEW.organization_id
                  AND membership.user_id =
                        NEW.resolved_by_user_id
                  AND membership.active = 1
                  AND membership.role = 'admin'
            )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_request_transition_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_request_guard_delete
BEFORE DELETE ON purchase_payment_group_requests
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_request_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_guard_insert
BEFORE INSERT ON purchase_payment_group_request_items
WHEN NEW.amount_minor < 1
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_payment_group_requests group_request
        JOIN purchase_obligations obligation
          ON obligation.id =
                NEW.purchase_obligation_id
         AND obligation.organization_id =
                group_request.organization_id
         AND obligation.supplier_id =
                group_request.supplier_id
         AND obligation.beneficiary_business_party_id =
                group_request.beneficiary_business_party_id
         AND obligation.currency_code =
                group_request.currency_code
        WHERE group_request.id =
                NEW.purchase_payment_group_request_id
          AND group_request.organization_id =
                NEW.organization_id
          AND group_request.status = 'pending'
          AND NEW.amount_minor
                <= obligation.amount_minor
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
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_item_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_guard_update
BEFORE UPDATE ON purchase_payment_group_request_items
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_item_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_guard_delete
BEFORE DELETE ON purchase_payment_group_request_items
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_item_immutable'
    );
END
SQL);

        $this->createSqliteCrossGuards();
    }

    private function createSqliteCrossGuards(): void
    {
        foreach ([
            'purchase_payment_requests'
                => 'purchase_payment_request_group_guard_insert',
            'supplier_credit_applications'
                => 'supplier_credit_application_group_guard_insert',
            'supplier_advance_applications'
                => 'supplier_advance_application_group_guard_insert',
        ] as $table => $trigger) {
            DB::unprepared(
                "CREATE TRIGGER {$trigger}
BEFORE INSERT ON {$table}
WHEN EXISTS (
    SELECT 1
    FROM purchase_payment_group_request_items item
    JOIN purchase_payment_group_requests group_request
      ON group_request.id =
            item.purchase_payment_group_request_id
     AND group_request.organization_id =
            item.organization_id
     AND group_request.status IN (
            'pending',
            'approved'
     )
    WHERE item.organization_id =
            NEW.organization_id
      AND item.purchase_obligation_id =
            NEW.purchase_obligation_id
)
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_active_conflict'
    );
END"
            );
        }
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_request_guard_insert
BEFORE INSERT ON purchase_payment_group_requests
FOR EACH ROW
BEGIN
    DECLARE supplier_count BIGINT DEFAULT 0;
    DECLARE party_count BIGINT DEFAULT 0;
    DECLARE account_count BIGINT DEFAULT 0;
    DECLARE member_count BIGINT DEFAULT 0;

    SELECT COUNT(*)
      INTO supplier_count
      FROM suppliers supplier
     WHERE supplier.id = NEW.supplier_id
       AND supplier.organization_id =
            NEW.organization_id
       AND supplier.active = 1;

    SELECT COUNT(*)
      INTO party_count
      FROM business_parties party
     WHERE party.id =
            NEW.beneficiary_business_party_id
       AND party.organization_id =
            NEW.organization_id;

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

    IF NEW.status <> 'pending'
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
       OR party_count <> 1
       OR account_count <> 1
       OR member_count <> 1
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_group_request_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_request_guard_update
BEFORE UPDATE ON purchase_payment_group_requests
FOR EACH ROW
BEGIN
    DECLARE approver_count BIGINT DEFAULT 0;
    DECLARE item_count BIGINT DEFAULT 0;
    DECLARE individual_conflicts BIGINT DEFAULT 0;
    DECLARE balance_conflicts BIGINT DEFAULT 0;
    DECLARE resolver_count BIGINT DEFAULT 0;

    IF NEW.status = 'approved' THEN
        SELECT COUNT(*)
          INTO approver_count
          FROM organization_memberships membership
         WHERE membership.organization_id =
                NEW.organization_id
           AND membership.user_id =
                NEW.approved_by_user_id
           AND membership.active = 1
           AND membership.role = 'admin';

        SELECT COUNT(*)
          INTO item_count
          FROM purchase_payment_group_request_items item
         WHERE item.purchase_payment_group_request_id =
                NEW.id
           AND item.organization_id =
                NEW.organization_id;

        SELECT COUNT(*)
          INTO individual_conflicts
          FROM purchase_payment_group_request_items item
          JOIN purchase_payment_requests request
            ON request.purchase_obligation_id =
                item.purchase_obligation_id
           AND request.organization_id =
                item.organization_id
           AND request.status IN (
                'pending',
                'approved'
           )
         WHERE item.purchase_payment_group_request_id =
                NEW.id;

        SELECT COUNT(*)
          INTO balance_conflicts
          FROM purchase_payment_group_request_items item
          JOIN purchase_obligations obligation
            ON obligation.id =
                item.purchase_obligation_id
           AND obligation.organization_id =
                item.organization_id
         WHERE item.purchase_payment_group_request_id =
                NEW.id
           AND item.amount_minor > (
                obligation.amount_minor
                - COALESCE(
                    (
                        SELECT SUM(execution.amount_minor)
                        FROM purchase_payment_executions execution
                        WHERE execution.organization_id =
                                item.organization_id
                          AND execution.purchase_obligation_id =
                                item.purchase_obligation_id
                    ),
                    0
                )
                - COALESCE(
                    (
                        SELECT SUM(application.amount_minor)
                        FROM supplier_credit_applications application
                        WHERE application.organization_id =
                                item.organization_id
                          AND application.purchase_obligation_id =
                                item.purchase_obligation_id
                    ),
                    0
                )
                - COALESCE(
                    (
                        SELECT SUM(application.amount_minor)
                        FROM supplier_advance_applications application
                        WHERE application.organization_id =
                                item.organization_id
                          AND application.purchase_obligation_id =
                                item.purchase_obligation_id
                    ),
                    0
                )
           );
    END IF;

    IF NEW.status IN (
        'rejected',
        'cancelled'
    ) THEN
        SELECT COUNT(*)
          INTO resolver_count
          FROM organization_memberships membership
         WHERE membership.organization_id =
                NEW.organization_id
           AND membership.user_id =
                NEW.resolved_by_user_id
           AND membership.active = 1;
    END IF;

    IF OLD.organization_id <> NEW.organization_id
       OR OLD.public_id <> NEW.public_id
       OR OLD.supplier_id <> NEW.supplier_id
       OR OLD.beneficiary_business_party_id
            <> NEW.beneficiary_business_party_id
       OR OLD.origin_financial_account_id
            <> NEW.origin_financial_account_id
       OR OLD.requested_by_user_id
            <> NEW.requested_by_user_id
       OR BINARY OLD.currency_code
            <> BINARY NEW.currency_code
       OR NOT (
            OLD.request_note <=> NEW.request_note
       )
       OR BINARY OLD.request_idempotency_key
            <> BINARY NEW.request_idempotency_key
       OR BINARY OLD.fingerprint
            <> BINARY NEW.fingerprint
       OR OLD.requested_at <> NEW.requested_at
       OR OLD.created_at <> NEW.created_at
       OR NEW.status = 'executed'
       OR NEW.status NOT IN (
            'pending',
            'approved',
            'rejected',
            'cancelled'
       )
       OR (
            OLD.status = 'pending'
            AND NEW.status NOT IN (
                'approved',
                'rejected',
                'cancelled'
            )
       )
       OR (
            OLD.status = 'approved'
            AND NEW.status <> 'cancelled'
       )
       OR OLD.status IN (
            'rejected',
            'cancelled'
       )
       OR (
            NEW.status = 'approved'
            AND (
                NEW.approved_by_user_id IS NULL
                OR NEW.approved_by_user_id =
                    NEW.requested_by_user_id
                OR CHAR_LENGTH(TRIM(
                    COALESCE(
                        NEW.approval_idempotency_key,
                        ''
                    )
                )) = 0
                OR CHAR_LENGTH(
                    COALESCE(
                        NEW.approval_fingerprint,
                        ''
                    )
                ) <> 64
                OR NEW.approved_at IS NULL
                OR approver_count <> 1
                OR item_count < 2
                OR individual_conflicts > 0
                OR balance_conflicts > 0
            )
       )
       OR (
            NEW.status IN (
                'rejected',
                'cancelled'
            )
            AND (
                NEW.resolved_by_user_id IS NULL
                OR CHAR_LENGTH(TRIM(
                    COALESCE(
                        NEW.resolution_note,
                        ''
                    )
                )) = 0
                OR CHAR_LENGTH(TRIM(
                    COALESCE(
                        NEW.resolution_idempotency_key,
                        ''
                    )
                )) = 0
                OR NEW.resolved_at IS NULL
                OR resolver_count <> 1
            )
       )
       OR (
            NEW.status = 'rejected'
            AND (
                OLD.status <> 'pending'
                OR NEW.resolved_by_user_id =
                    NEW.requested_by_user_id
            )
       )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_group_request_transition_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_request_guard_delete
BEFORE DELETE ON purchase_payment_group_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_group_request_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_guard_insert
BEFORE INSERT ON purchase_payment_group_request_items
FOR EACH ROW
BEGIN
    DECLARE relation_count BIGINT DEFAULT 0;
    DECLARE individual_conflicts BIGINT DEFAULT 0;

    SELECT COUNT(*)
      INTO relation_count
      FROM purchase_payment_group_requests group_request
      JOIN purchase_obligations obligation
        ON obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            group_request.organization_id
       AND obligation.supplier_id =
            group_request.supplier_id
       AND obligation.beneficiary_business_party_id =
            group_request.beneficiary_business_party_id
       AND BINARY obligation.currency_code =
            BINARY group_request.currency_code
     WHERE group_request.id =
            NEW.purchase_payment_group_request_id
       AND group_request.organization_id =
            NEW.organization_id
       AND group_request.status = 'pending'
       AND NEW.amount_minor <= obligation.amount_minor;

    SELECT COUNT(*)
      INTO individual_conflicts
      FROM purchase_payment_requests request
     WHERE request.organization_id =
            NEW.organization_id
       AND request.purchase_obligation_id =
            NEW.purchase_obligation_id
       AND request.status IN (
            'pending',
            'approved'
       );

    IF NEW.amount_minor < 1
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.created_at IS NULL
       OR relation_count <> 1
       OR individual_conflicts > 0
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_group_item_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_guard_update
BEFORE UPDATE ON purchase_payment_group_request_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_group_item_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_guard_delete
BEFORE DELETE ON purchase_payment_group_request_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_group_item_immutable';
END
SQL);

        $this->createMysqlCrossGuards();
    }

    private function createMysqlCrossGuards(): void
    {
        foreach ([
            'purchase_payment_requests'
                => 'purchase_payment_request_group_guard_insert',
            'supplier_credit_applications'
                => 'supplier_credit_application_group_guard_insert',
            'supplier_advance_applications'
                => 'supplier_advance_application_group_guard_insert',
        ] as $table => $trigger) {
            DB::unprepared(
                "CREATE TRIGGER {$trigger}
BEFORE INSERT ON {$table}
FOR EACH ROW
BEGIN
    DECLARE conflict_count BIGINT DEFAULT 0;

    SELECT COUNT(*)
      INTO conflict_count
      FROM purchase_payment_group_request_items item
      JOIN purchase_payment_group_requests group_request
        ON group_request.id =
            item.purchase_payment_group_request_id
       AND group_request.organization_id =
            item.organization_id
       AND group_request.status IN (
            'pending',
            'approved'
       )
     WHERE item.organization_id =
            NEW.organization_id
       AND item.purchase_obligation_id =
            NEW.purchase_obligation_id;

    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_group_active_conflict';
    END IF;
END"
            );
        }
    }
};
