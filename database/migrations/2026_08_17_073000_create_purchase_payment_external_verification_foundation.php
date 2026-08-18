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
            'purchase_payment_external_verifications',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->uuid('public_id');
                $table->foreignId(
                    'purchase_payment_disbursement_id'
                );
                $table->foreignId(
                    'financial_external_movement_id'
                );
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->string(
                    'reference_match_kind',
                    32
                );
                $table->bigInteger(
                    'amount_difference_minor'
                );
                $table->text('note')->nullable();
                $table->foreignId('verified_by_user_id');
                $table->timestampTz('verified_at');
                $table->timestampTz('created_at');

                $table->foreign(
                    'organization_id',
                    'purchase_pay_ext_verify_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'purchase_payment_disbursement_id',
                    'purchase_pay_ext_verify_disb_fk'
                )
                    ->references('id')
                    ->on(
                        'purchase_payment_disbursements'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'financial_external_movement_id',
                    'purchase_pay_ext_verify_move_fk'
                )
                    ->references('id')
                    ->on('financial_external_movements')
                    ->restrictOnDelete();

                $table->foreign(
                    'verified_by_user_id',
                    'purchase_pay_ext_verify_user_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    'public_id',
                    'purchase_pay_ext_verify_public_unique'
                );

                $table->unique(
                    'purchase_payment_disbursement_id',
                    'purchase_pay_ext_verify_disb_unique'
                );

                $table->unique(
                    'financial_external_movement_id',
                    'purchase_pay_ext_verify_move_unique'
                );

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'purchase_pay_ext_verify_org_idem_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'verified_at',
                    ],
                    'purchase_pay_ext_verify_org_time_idx'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.7k conserva verificaciones externas append-only y no admite rollback automático.'
        );
    }

    private function createGuards(): void
    {
        foreach ($this->triggerNames() as $trigger) {
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
            "P9.7k no implementa guards para {$driver}."
        );
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'pay_reconciliation_no_purchase_payment_insert',
            'post_sale_refund_no_purchase_payment_insert',
            'purchase_pay_ext_verify_guard_delete',
            'purchase_pay_ext_verify_guard_update',
            'purchase_pay_ext_verify_guard_insert',
        ];
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_verify_guard_insert
BEFORE INSERT ON purchase_payment_external_verifications
WHEN LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.reference_match_kind NOT IN (
        'external_operation_id',
        'source_key',
        'raw_reference',
        'operator_confirmed'
    )
    OR NEW.verified_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.verified_by_user_id
          AND membership.active = 1
          AND membership.role = 'admin'
    )
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_payment_disbursements disbursement
        JOIN financial_external_movements movement
          ON movement.id =
                NEW.financial_external_movement_id
        WHERE disbursement.id =
                NEW.purchase_payment_disbursement_id
          AND disbursement.organization_id =
                NEW.organization_id
          AND disbursement.channel = 'noncash'
          AND LENGTH(TRIM(
                disbursement.execution_reference
          )) > 0
          AND movement.organization_id =
                NEW.organization_id
          AND movement.financial_account_id =
                disbursement.origin_financial_account_id
          AND movement.currency_code =
                disbursement.currency_code
          AND movement.direction = 'debit'
          AND movement.status = 'posted'
          AND NEW.amount_difference_minor =
                movement.gross_amount_minor
                - disbursement.amount_minor
          AND (
                (
                    NEW.reference_match_kind =
                        'external_operation_id'
                    AND movement.external_operation_id
                        IS NOT NULL
                    AND TRIM(
                        movement.external_operation_id
                    ) = TRIM(
                        disbursement.execution_reference
                    )
                )
                OR (
                    NEW.reference_match_kind =
                        'source_key'
                    AND COALESCE(TRIM(
                        movement.external_operation_id
                    ), '') <> TRIM(
                        disbursement.execution_reference
                    )
                    AND TRIM(movement.source_key) =
                        TRIM(
                            disbursement.execution_reference
                        )
                )
                OR (
                    NEW.reference_match_kind =
                        'raw_reference'
                    AND COALESCE(TRIM(
                        movement.external_operation_id
                    ), '') <> TRIM(
                        disbursement.execution_reference
                    )
                    AND TRIM(movement.source_key) <>
                        TRIM(
                            disbursement.execution_reference
                        )
                    AND movement.raw_reference IS NOT NULL
                    AND TRIM(movement.raw_reference) =
                        TRIM(
                            disbursement.execution_reference
                        )
                )
                OR (
                    NEW.reference_match_kind =
                        'operator_confirmed'
                    AND COALESCE(TRIM(
                        movement.external_operation_id
                    ), '') <> TRIM(
                        disbursement.execution_reference
                    )
                    AND TRIM(movement.source_key) <>
                        TRIM(
                            disbursement.execution_reference
                        )
                    AND COALESCE(TRIM(
                        movement.raw_reference
                    ), '') <> TRIM(
                        disbursement.execution_reference
                    )
                )
          )
          AND (
                (
                    NEW.reference_match_kind <>
                        'operator_confirmed'
                    AND NEW.amount_difference_minor = 0
                    AND movement.fee_amount_minor = 0
                    AND movement.withholding_amount_minor = 0
                )
                OR (
                    NEW.note IS NOT NULL
                    AND LENGTH(TRIM(NEW.note)) >= 10
                )
          )
    )
    OR EXISTS (
        SELECT 1
        FROM payment_reconciliation_allocations allocation
        WHERE allocation.financial_external_movement_id =
                NEW.financial_external_movement_id
    )
    OR EXISTS (
        SELECT 1
        FROM commerce_post_sale_external_refund_evidence evidence
        WHERE evidence.financial_external_movement_id =
                NEW.financial_external_movement_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_external_verification_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_verify_guard_update
BEFORE UPDATE ON purchase_payment_external_verifications
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_external_verification_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_verify_guard_delete
BEFORE DELETE ON purchase_payment_external_verifications
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_external_verification_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_refund_no_purchase_payment_insert
BEFORE INSERT ON commerce_post_sale_external_refund_evidence
WHEN EXISTS (
    SELECT 1
    FROM purchase_payment_external_verifications verification
    WHERE verification.financial_external_movement_id =
            NEW.financial_external_movement_id
)
BEGIN
    SELECT RAISE(
        ABORT,
        'external_movement_already_used_by_purchase_payment'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER pay_reconciliation_no_purchase_payment_insert
BEFORE INSERT ON payment_reconciliation_allocations
WHEN EXISTS (
    SELECT 1
    FROM purchase_payment_external_verifications verification
    WHERE verification.financial_external_movement_id =
            NEW.financial_external_movement_id
)
BEGIN
    SELECT RAISE(
        ABORT,
        'external_movement_already_used_by_purchase_payment'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_verify_guard_insert
BEFORE INSERT ON purchase_payment_external_verifications
FOR EACH ROW
BEGIN
    IF LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR LENGTH(NEW.fingerprint) <> 64
        OR NEW.reference_match_kind NOT IN (
            'external_operation_id',
            'source_key',
            'raw_reference',
            'operator_confirmed'
        )
        OR NEW.verified_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.verified_by_user_id
              AND membership.active = 1
              AND membership.role = 'admin'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_payment_disbursements disbursement
            JOIN financial_external_movements movement
              ON movement.id =
                    NEW.financial_external_movement_id
            WHERE disbursement.id =
                    NEW.purchase_payment_disbursement_id
              AND disbursement.organization_id =
                    NEW.organization_id
              AND disbursement.channel = 'noncash'
              AND LENGTH(TRIM(
                    disbursement.execution_reference
              )) > 0
              AND movement.organization_id =
                    NEW.organization_id
              AND movement.financial_account_id =
                    disbursement.origin_financial_account_id
              AND movement.currency_code =
                    disbursement.currency_code
              AND movement.direction = 'debit'
              AND movement.status = 'posted'
              AND NEW.amount_difference_minor =
                    CAST(movement.gross_amount_minor AS SIGNED)
                    - CAST(disbursement.amount_minor AS SIGNED)
              AND (
                    (
                        NEW.reference_match_kind =
                            'external_operation_id'
                        AND movement.external_operation_id
                            IS NOT NULL
                        AND TRIM(
                            movement.external_operation_id
                        ) = TRIM(
                            disbursement.execution_reference
                        )
                    )
                    OR (
                        NEW.reference_match_kind =
                            'source_key'
                        AND COALESCE(TRIM(
                            movement.external_operation_id
                        ), '') <> TRIM(
                            disbursement.execution_reference
                        )
                        AND TRIM(movement.source_key) =
                            TRIM(
                                disbursement.execution_reference
                            )
                    )
                    OR (
                        NEW.reference_match_kind =
                            'raw_reference'
                        AND COALESCE(TRIM(
                            movement.external_operation_id
                        ), '') <> TRIM(
                            disbursement.execution_reference
                        )
                        AND TRIM(movement.source_key) <>
                            TRIM(
                                disbursement.execution_reference
                            )
                        AND movement.raw_reference IS NOT NULL
                        AND TRIM(movement.raw_reference) =
                            TRIM(
                                disbursement.execution_reference
                            )
                    )
                    OR (
                        NEW.reference_match_kind =
                            'operator_confirmed'
                        AND COALESCE(TRIM(
                            movement.external_operation_id
                        ), '') <> TRIM(
                            disbursement.execution_reference
                        )
                        AND TRIM(movement.source_key) <>
                            TRIM(
                                disbursement.execution_reference
                            )
                        AND COALESCE(TRIM(
                            movement.raw_reference
                        ), '') <> TRIM(
                            disbursement.execution_reference
                        )
                    )
              )
              AND (
                    (
                        NEW.reference_match_kind <>
                            'operator_confirmed'
                        AND NEW.amount_difference_minor = 0
                        AND movement.fee_amount_minor = 0
                        AND movement.withholding_amount_minor = 0
                    )
                    OR (
                        NEW.note IS NOT NULL
                        AND LENGTH(TRIM(NEW.note)) >= 10
                    )
              )
        )
        OR EXISTS (
            SELECT 1
            FROM payment_reconciliation_allocations allocation
            WHERE allocation.financial_external_movement_id =
                    NEW.financial_external_movement_id
        )
        OR EXISTS (
            SELECT 1
            FROM commerce_post_sale_external_refund_evidence evidence
            WHERE evidence.financial_external_movement_id =
                    NEW.financial_external_movement_id
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_external_verification_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_verify_guard_update
BEFORE UPDATE ON purchase_payment_external_verifications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_external_verification_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_verify_guard_delete
BEFORE DELETE ON purchase_payment_external_verifications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_external_verification_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_refund_no_purchase_payment_insert
BEFORE INSERT ON commerce_post_sale_external_refund_evidence
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM purchase_payment_external_verifications verification
        WHERE verification.financial_external_movement_id =
                NEW.financial_external_movement_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'external_movement_used_by_purchase_payment';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER pay_reconciliation_no_purchase_payment_insert
BEFORE INSERT ON payment_reconciliation_allocations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM purchase_payment_external_verifications verification
        WHERE verification.financial_external_movement_id =
                NEW.financial_external_movement_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'external_movement_used_by_purchase_payment';
    END IF;
END
SQL);
    }
};
