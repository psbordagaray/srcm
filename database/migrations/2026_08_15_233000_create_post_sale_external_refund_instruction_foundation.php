<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER =
        'post_sale_external_refund_instructions_guard_insert';

    private const UPDATE_TRIGGER =
        'post_sale_external_refund_instructions_guard_update';

    private const DELETE_TRIGGER =
        'post_sale_external_refund_instructions_guard_delete';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_external_refund_instructions',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'organization_id'
                );

                $table->uuid(
                    'public_id'
                );

                $table->foreignId(
                    'commerce_post_sale_resolution_id'
                );

                $table->foreignId(
                    'original_commerce_payment_id'
                );

                $table->foreignId(
                    'financial_account_id'
                );

                $table->foreignId(
                    'financial_provider_connection_id'
                );

                $table->foreignId(
                    'requested_by_user_id'
                );

                $table->unsignedBigInteger(
                    'amount_minor'
                );

                $table->char(
                    'currency_code',
                    3
                );

                $table->string(
                    'idempotency_key',
                    180
                );

                $table->char(
                    'fingerprint',
                    64
                );

                $table->timestamp(
                    'requested_at'
                );

                $table->timestamp(
                    'created_at'
                );

                $table->unique(
                    'public_id',
                    'post_sale_ext_refund_public_id_unique'
                );

                $table->unique(
                    'commerce_post_sale_resolution_id',
                    'post_sale_ext_refund_resolution_unique'
                );

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'post_sale_ext_refund_org_idem_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'original_commerce_payment_id',
                        'requested_at',
                    ],
                    'post_sale_ext_refund_payment_index'
                );

                $table->foreign(
                    'organization_id',
                    'post_sale_ext_refund_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'commerce_post_sale_resolution_id',
                    'post_sale_ext_refund_resolution_fk'
                )
                    ->references('id')
                    ->on(
                        'commerce_post_sale_resolutions'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'original_commerce_payment_id',
                    'post_sale_ext_refund_payment_fk'
                )
                    ->references('id')
                    ->on('commerce_payments')
                    ->restrictOnDelete();

                $table->foreign(
                    'financial_account_id',
                    'post_sale_ext_refund_account_fk'
                )
                    ->references('id')
                    ->on('financial_accounts')
                    ->restrictOnDelete();

                $table->foreign(
                    'financial_provider_connection_id',
                    'post_sale_ext_refund_connection_fk'
                )
                    ->references('id')
                    ->on(
                        'financial_provider_connections'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'requested_by_user_id',
                    'post_sale_ext_refund_requester_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        throw new LogicException(
            'P8.4.3 conserva instrucciones externas append-only; no admite rollback automático.'
        );
    }

    private function createTriggers(): void
    {
        foreach ([
            self::DELETE_TRIGGER,
            self::UPDATE_TRIGGER,
            self::INSERT_TRIGGER,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            'La integridad P8.4.3 no está implementada para '
            .$driver.'.'
        );
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_external_refund_instructions_guard_insert
BEFORE INSERT ON commerce_post_sale_external_refund_instructions
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.requested_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_resolutions resolution
        INNER JOIN commerce_post_sale_requests request_row
            ON request_row.id =
                resolution.commerce_post_sale_request_id
        INNER JOIN commerce_sales sale
            ON sale.id =
                request_row.commerce_sale_id
        INNER JOIN commerce_payments payment
            ON payment.id =
                NEW.original_commerce_payment_id
        INNER JOIN financial_accounts account
            ON account.id =
                NEW.financial_account_id
        INNER JOIN financial_provider_connections connection_row
            ON connection_row.id =
                NEW.financial_provider_connection_id
        WHERE resolution.id =
                NEW.commerce_post_sale_resolution_id
          AND resolution.organization_id =
                NEW.organization_id
          AND resolution.outcome = 'refund'
          AND resolution.preferred_original_payment_id =
                payment.id
          AND resolution.currency_code =
                NEW.currency_code
          AND resolution.resolved_by_user_id IS NOT NULL
          AND resolution.resolved_by_user_id <>
                NEW.requested_by_user_id
          AND request_row.organization_id =
                NEW.organization_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.status = 'confirmed'
          AND sale.currency_code =
                NEW.currency_code
          AND payment.organization_id =
                NEW.organization_id
          AND payment.commerce_sale_id =
                sale.id
          AND payment.method <> 'cash'
          AND payment.financial_account_id =
                account.id
          AND payment.external_operation_id IS NOT NULL
          AND LENGTH(TRIM(payment.external_operation_id)) > 0
          AND payment.amount_minor > 0
          AND account.organization_id =
                NEW.organization_id
          AND account.active = 1
          AND account.type NOT IN (
              'cash_box',
              'cash_reserve'
          )
          AND account.currency_code =
                NEW.currency_code
          AND connection_row.organization_id =
                NEW.organization_id
          AND connection_row.financial_account_id =
                account.id
          AND connection_row.active = 1
          AND EXISTS (
              SELECT 1
              FROM financial_provider_connection_compatibility_bindings binding
              INNER JOIN financial_provider_compatibilities compatibility
                  ON compatibility.id =
                      binding.financial_provider_compatibility_id
              INNER JOIN financial_provider_capability_compatibilities capability_row
                  ON capability_row.financial_provider_compatibility_id =
                      compatibility.id
                 AND capability_row.capability = 'refund'
                 AND capability_row.compatibility_status = 'compatible'
              WHERE binding.financial_provider_connection_id =
                    connection_row.id
                AND NOT EXISTS (
                    SELECT 1
                    FROM financial_provider_connection_compatibility_bindings successor
                    WHERE successor.previous_binding_id =
                          binding.id
                )
                AND compatibility.compatibility_status IN (
                    'compatible',
                    'degraded'
                )
                AND compatibility.migration_required = 0
                AND NOT EXISTS (
                    SELECT 1
                    FROM financial_provider_compatibility_retirements retirement
                    WHERE retirement.financial_provider_compatibility_id =
                          compatibility.id
                )
                AND EXISTS (
                    SELECT 1
                    FROM financial_provider_connection_health_checks health
                    WHERE health.financial_provider_connection_id =
                          connection_row.id
                      AND health.financial_provider_connection_compatibility_binding_id =
                          binding.id
                      AND health.capability = 'refund'
                      AND health.health_status = 'healthy'
                      AND NOT EXISTS (
                          SELECT 1
                          FROM financial_provider_connection_health_checks newer_health
                          WHERE newer_health.financial_provider_connection_id =
                                health.financial_provider_connection_id
                            AND newer_health.financial_provider_connection_compatibility_binding_id =
                                health.financial_provider_connection_compatibility_binding_id
                            AND newer_health.capability =
                                health.capability
                            AND (
                                newer_health.checked_at >
                                    health.checked_at
                                OR (
                                    newer_health.checked_at =
                                        health.checked_at
                                    AND newer_health.id >
                                        health.id
                                )
                            )
                      )
                )
          )
          AND NEW.amount_minor = (
              SELECT COALESCE(
                  SUM(
                      resolution_line.recognized_amount_minor
                  ),
                  0
              )
              FROM commerce_post_sale_resolution_lines resolution_line
              WHERE resolution_line.organization_id =
                    NEW.organization_id
                AND resolution_line.commerce_post_sale_resolution_id =
                    resolution.id
          )
          AND NEW.amount_minor <=
              payment.amount_minor - COALESCE(
                  (
                      SELECT SUM(
                          previous.amount_minor
                      )
                      FROM commerce_post_sale_external_refund_instructions previous
                      WHERE previous.organization_id =
                            NEW.organization_id
                        AND previous.original_commerce_payment_id =
                            payment.id
                  ),
                  0
              )
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
        'La instrucción de reembolso externo no conserva resolución, medio original, segregación, conexión o límite válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_external_refund_instructions_guard_update
BEFORE UPDATE ON commerce_post_sale_external_refund_instructions
BEGIN
    SELECT RAISE(
        ABORT,
        'Una instrucción de reembolso externo es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_external_refund_instructions_guard_delete
BEFORE DELETE ON commerce_post_sale_external_refund_instructions
BEGIN
    SELECT RAISE(
        ABORT,
        'Una instrucción de reembolso externo no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_external_refund_instructions_guard_insert
BEFORE INSERT ON commerce_post_sale_external_refund_instructions
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.idempotency_key) > 180
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.requested_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_resolutions resolution
            INNER JOIN commerce_post_sale_requests request_row
                ON request_row.id =
                    resolution.commerce_post_sale_request_id
            INNER JOIN commerce_sales sale
                ON sale.id =
                    request_row.commerce_sale_id
            INNER JOIN commerce_payments payment
                ON payment.id =
                    NEW.original_commerce_payment_id
            INNER JOIN financial_accounts account
                ON account.id =
                    NEW.financial_account_id
            INNER JOIN financial_provider_connections connection_row
                ON connection_row.id =
                    NEW.financial_provider_connection_id
            WHERE resolution.id =
                    NEW.commerce_post_sale_resolution_id
              AND resolution.organization_id =
                    NEW.organization_id
              AND resolution.outcome = 'refund'
              AND resolution.preferred_original_payment_id =
                    payment.id
              AND resolution.currency_code =
                    NEW.currency_code
              AND resolution.resolved_by_user_id IS NOT NULL
              AND resolution.resolved_by_user_id <>
                    NEW.requested_by_user_id
              AND request_row.organization_id =
                    NEW.organization_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.status = 'confirmed'
              AND sale.currency_code =
                    NEW.currency_code
              AND payment.organization_id =
                    NEW.organization_id
              AND payment.commerce_sale_id =
                    sale.id
              AND payment.method <> 'cash'
              AND payment.financial_account_id =
                    account.id
              AND payment.external_operation_id IS NOT NULL
              AND CHAR_LENGTH(TRIM(payment.external_operation_id)) > 0
              AND payment.amount_minor > 0
              AND account.organization_id =
                    NEW.organization_id
              AND account.active = 1
              AND account.type NOT IN (
                  'cash_box',
                  'cash_reserve'
              )
              AND account.currency_code =
                    NEW.currency_code
              AND connection_row.organization_id =
                    NEW.organization_id
              AND connection_row.financial_account_id =
                    account.id
              AND connection_row.active = 1
              AND EXISTS (
                  SELECT 1
                  FROM financial_provider_connection_compatibility_bindings binding
                  INNER JOIN financial_provider_compatibilities compatibility
                      ON compatibility.id =
                          binding.financial_provider_compatibility_id
                  INNER JOIN financial_provider_capability_compatibilities capability_row
                      ON capability_row.financial_provider_compatibility_id =
                          compatibility.id
                     AND capability_row.capability = 'refund'
                     AND capability_row.compatibility_status = 'compatible'
                  WHERE binding.financial_provider_connection_id =
                        connection_row.id
                    AND NOT EXISTS (
                        SELECT 1
                        FROM financial_provider_connection_compatibility_bindings successor
                        WHERE successor.previous_binding_id =
                              binding.id
                    )
                    AND compatibility.compatibility_status IN (
                        'compatible',
                        'degraded'
                    )
                    AND compatibility.migration_required = 0
                    AND NOT EXISTS (
                        SELECT 1
                        FROM financial_provider_compatibility_retirements retirement
                        WHERE retirement.financial_provider_compatibility_id =
                              compatibility.id
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM financial_provider_connection_health_checks health
                        WHERE health.financial_provider_connection_id =
                              connection_row.id
                          AND health.financial_provider_connection_compatibility_binding_id =
                              binding.id
                          AND health.capability = 'refund'
                          AND health.health_status = 'healthy'
                          AND NOT EXISTS (
                              SELECT 1
                              FROM financial_provider_connection_health_checks newer_health
                              WHERE newer_health.financial_provider_connection_id =
                                    health.financial_provider_connection_id
                                AND newer_health.financial_provider_connection_compatibility_binding_id =
                                    health.financial_provider_connection_compatibility_binding_id
                                AND newer_health.capability =
                                    health.capability
                                AND (
                                    newer_health.checked_at >
                                        health.checked_at
                                    OR (
                                        newer_health.checked_at =
                                            health.checked_at
                                        AND newer_health.id >
                                            health.id
                                    )
                                )
                          )
                    )
              )
              AND NEW.amount_minor = (
                  SELECT COALESCE(
                      SUM(
                          resolution_line.recognized_amount_minor
                      ),
                      0
                  )
                  FROM commerce_post_sale_resolution_lines resolution_line
                  WHERE resolution_line.organization_id =
                        NEW.organization_id
                    AND resolution_line.commerce_post_sale_resolution_id =
                        resolution.id
              )
              AND NEW.amount_minor <=
                  payment.amount_minor - COALESCE(
                      (
                          SELECT SUM(
                              previous.amount_minor
                          )
                          FROM commerce_post_sale_external_refund_instructions previous
                          WHERE previous.organization_id =
                                NEW.organization_id
                            AND previous.original_commerce_payment_id =
                                payment.id
                      ),
                      0
                  )
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
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La instrucción de reembolso externo no conserva resolución, medio original, segregación, conexión o límite válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_external_refund_instructions_guard_update
BEFORE UPDATE ON commerce_post_sale_external_refund_instructions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una instrucción de reembolso externo es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_external_refund_instructions_guard_delete
BEFORE DELETE ON commerce_post_sale_external_refund_instructions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una instrucción de reembolso externo no puede eliminarse.';
END
SQL);
    }
};
