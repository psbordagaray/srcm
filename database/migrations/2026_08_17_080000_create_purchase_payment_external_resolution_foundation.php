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
            'purchase_payment_external_resolutions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->uuid('public_id');
                $table->foreignId(
                    'purchase_payment_external_verification_id'
                );
                $table->foreignId(
                    'reviewed_financial_external_movement_id'
                );
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->string('outcome', 48);
                $table->string('observed_status', 24);
                $table->bigInteger('amount_difference_minor');
                $table->unsignedBigInteger('fee_amount_minor');
                $table->unsignedBigInteger(
                    'withholding_amount_minor'
                );
                $table->text('note');
                $table->foreignId('resolved_by_user_id');
                $table->timestampTz('resolved_at');
                $table->timestampTz('created_at');

                $table->foreign(
                    'organization_id',
                    'purchase_pay_ext_res_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'purchase_payment_external_verification_id',
                    'purchase_pay_ext_res_verify_fk'
                )
                    ->references('id')
                    ->on(
                        'purchase_payment_external_verifications'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'reviewed_financial_external_movement_id',
                    'purchase_pay_ext_res_move_fk'
                )
                    ->references('id')
                    ->on('financial_external_movements')
                    ->restrictOnDelete();

                $table->foreign(
                    'resolved_by_user_id',
                    'purchase_pay_ext_res_user_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    'public_id',
                    'purchase_pay_ext_res_public_unique'
                );

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'purchase_pay_ext_res_org_idem_unique'
                );

                $table->unique(
                    [
                        'purchase_payment_external_verification_id',
                        'reviewed_financial_external_movement_id',
                    ],
                    'purchase_pay_ext_res_observation_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'resolved_at',
                    ],
                    'purchase_pay_ext_res_org_time_idx'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.7l conserva resoluciones externas append-only y no admite rollback automático.'
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

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlGuards();

            return;
        }

        throw new LogicException(
            "P9.7l no implementa guards para {$driver}."
        );
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'purchase_pay_ext_res_guard_insert',
            'purchase_pay_ext_res_guard_update',
            'purchase_pay_ext_res_guard_delete',
        ];
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_res_guard_insert
BEFORE INSERT ON purchase_payment_external_resolutions
WHEN LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR LENGTH(TRIM(NEW.note)) < 10
    OR NEW.outcome NOT IN (
        'treasury_exception_accepted',
        'provider_follow_up_required',
        'supplier_follow_up_required',
        'evidence_correction_required'
    )
    OR NEW.observed_status NOT IN (
        'posted',
        'pending',
        'failed',
        'reversed'
    )
    OR (
        NEW.outcome = 'treasury_exception_accepted'
        AND NEW.observed_status <> 'posted'
    )
    OR NEW.resolved_at IS NULL
    OR NEW.created_at IS NULL
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
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_payment_external_verifications verification
        JOIN purchase_payment_disbursements disbursement
          ON disbursement.id =
                verification.purchase_payment_disbursement_id
        JOIN financial_external_movements verified
          ON verified.id =
                verification.financial_external_movement_id
        JOIN financial_external_movements reviewed
          ON reviewed.id =
                NEW.reviewed_financial_external_movement_id
        WHERE verification.id =
                NEW.purchase_payment_external_verification_id
          AND verification.organization_id =
                NEW.organization_id
          AND disbursement.organization_id =
                NEW.organization_id
          AND verified.organization_id =
                NEW.organization_id
          AND reviewed.organization_id =
                NEW.organization_id
          AND reviewed.financial_account_id =
                verified.financial_account_id
          AND reviewed.currency_code =
                verified.currency_code
          AND reviewed.direction = 'debit'
          AND reviewed.status =
                NEW.observed_status
          AND NEW.amount_difference_minor =
                reviewed.gross_amount_minor
                - disbursement.amount_minor
          AND NEW.fee_amount_minor =
                reviewed.fee_amount_minor
          AND NEW.withholding_amount_minor =
                reviewed.withholding_amount_minor
          AND (
                (
                    verified.external_operation_id IS NULL
                    AND reviewed.id = verified.id
                )
                OR (
                    verified.external_operation_id IS NOT NULL
                    AND reviewed.external_operation_id =
                        verified.external_operation_id
                    AND NOT EXISTS (
                        SELECT 1
                        FROM financial_external_movements later
                        WHERE later.organization_id =
                                NEW.organization_id
                          AND later.financial_account_id =
                                verified.financial_account_id
                          AND later.external_operation_id =
                                verified.external_operation_id
                          AND later.direction = 'debit'
                          AND later.currency_code =
                                verified.currency_code
                          AND (
                                later.occurred_at >
                                    reviewed.occurred_at
                                OR (
                                    later.occurred_at =
                                        reviewed.occurred_at
                                    AND later.id > reviewed.id
                                )
                          )
                    )
                )
          )
          AND NOT (
                reviewed.status = 'posted'
                AND reviewed.gross_amount_minor
                    - disbursement.amount_minor = 0
                AND reviewed.fee_amount_minor = 0
                AND reviewed.withholding_amount_minor = 0
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_external_resolution_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_res_guard_update
BEFORE UPDATE ON purchase_payment_external_resolutions
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_external_resolution_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_res_guard_delete
BEFORE DELETE ON purchase_payment_external_resolutions
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_external_resolution_immutable'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_res_guard_insert
BEFORE INSERT ON purchase_payment_external_resolutions
FOR EACH ROW
BEGIN
    IF LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR LENGTH(NEW.fingerprint) <> 64
        OR CHAR_LENGTH(TRIM(NEW.note)) < 10
        OR NEW.outcome NOT IN (
            'treasury_exception_accepted',
            'provider_follow_up_required',
            'supplier_follow_up_required',
            'evidence_correction_required'
        )
        OR NEW.observed_status NOT IN (
            'posted',
            'pending',
            'failed',
            'reversed'
        )
        OR (
            NEW.outcome = 'treasury_exception_accepted'
            AND NEW.observed_status <> 'posted'
        )
        OR NEW.resolved_at IS NULL
        OR NEW.created_at IS NULL
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
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_payment_external_verifications verification
            JOIN purchase_payment_disbursements disbursement
              ON disbursement.id =
                    verification.purchase_payment_disbursement_id
            JOIN financial_external_movements verified
              ON verified.id =
                    verification.financial_external_movement_id
            JOIN financial_external_movements reviewed
              ON reviewed.id =
                    NEW.reviewed_financial_external_movement_id
            WHERE verification.id =
                    NEW.purchase_payment_external_verification_id
              AND verification.organization_id =
                    NEW.organization_id
              AND disbursement.organization_id =
                    NEW.organization_id
              AND verified.organization_id =
                    NEW.organization_id
              AND reviewed.organization_id =
                    NEW.organization_id
              AND reviewed.financial_account_id =
                    verified.financial_account_id
              AND reviewed.currency_code =
                    verified.currency_code
              AND reviewed.direction = 'debit'
              AND reviewed.status =
                    NEW.observed_status
              AND NEW.amount_difference_minor =
                    CAST(reviewed.gross_amount_minor AS SIGNED)
                    - CAST(disbursement.amount_minor AS SIGNED)
              AND NEW.fee_amount_minor =
                    reviewed.fee_amount_minor
              AND NEW.withholding_amount_minor =
                    reviewed.withholding_amount_minor
              AND (
                    (
                        verified.external_operation_id IS NULL
                        AND reviewed.id = verified.id
                    )
                    OR (
                        verified.external_operation_id IS NOT NULL
                        AND reviewed.external_operation_id =
                            verified.external_operation_id
                        AND NOT EXISTS (
                            SELECT 1
                            FROM financial_external_movements later
                            WHERE later.organization_id =
                                    NEW.organization_id
                              AND later.financial_account_id =
                                    verified.financial_account_id
                              AND later.external_operation_id =
                                    verified.external_operation_id
                              AND later.direction = 'debit'
                              AND later.currency_code =
                                    verified.currency_code
                              AND (
                                    later.occurred_at >
                                        reviewed.occurred_at
                                    OR (
                                        later.occurred_at =
                                            reviewed.occurred_at
                                        AND later.id > reviewed.id
                                    )
                              )
                        )
                    )
              )
              AND NOT (
                    reviewed.status = 'posted'
                    AND CAST(reviewed.gross_amount_minor AS SIGNED)
                        - CAST(disbursement.amount_minor AS SIGNED) = 0
                    AND reviewed.fee_amount_minor = 0
                    AND reviewed.withholding_amount_minor = 0
              )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_external_resolution_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_res_guard_update
BEFORE UPDATE ON purchase_payment_external_resolutions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_external_resolution_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_pay_ext_res_guard_delete
BEFORE DELETE ON purchase_payment_external_resolutions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_external_resolution_immutable';
END
SQL);
    }
};
