<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISPATCH_INSERT =
        'post_sale_ext_refund_dispatch_guard_insert';

    private const DISPATCH_UPDATE =
        'post_sale_ext_refund_dispatch_guard_update';

    private const DISPATCH_DELETE =
        'post_sale_ext_refund_dispatch_guard_delete';

    private const EVIDENCE_INSERT =
        'post_sale_ext_refund_evidence_guard_insert';

    private const EVIDENCE_UPDATE =
        'post_sale_ext_refund_evidence_guard_update';

    private const EVIDENCE_DELETE =
        'post_sale_ext_refund_evidence_guard_delete';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_external_refund_dispatches',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->uuid('public_id');

                $table->foreignId(
                    'commerce_post_sale_external_refund_instruction_id'
                );

                $table->foreignId(
                    'financial_provider_connection_id'
                );

                $table->foreignId(
                    'financial_account_id'
                );

                $table->string(
                    'provider_key',
                    100
                );

                $table->string(
                    'provider_idempotency_key',
                    180
                );

                $table->char(
                    'fingerprint',
                    64
                );

                $table->timestamp(
                    'created_at'
                );

                $table->unique(
                    'public_id',
                    'post_sale_ext_refund_dispatch_public_unique'
                );

                $table->unique(
                    'commerce_post_sale_external_refund_instruction_id',
                    'post_sale_ext_refund_dispatch_instruction_unique'
                );

                $table->unique(
                    [
                        'financial_provider_connection_id',
                        'provider_idempotency_key',
                    ],
                    'post_sale_ext_refund_dispatch_provider_idem_unique'
                );

                $table->foreign(
                    'organization_id',
                    'post_sale_ext_refund_dispatch_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'commerce_post_sale_external_refund_instruction_id',
                    'post_sale_ext_refund_dispatch_instruction_fk'
                )
                    ->references('id')
                    ->on(
                        'commerce_post_sale_external_refund_instructions'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'financial_provider_connection_id',
                    'post_sale_ext_refund_dispatch_connection_fk'
                )
                    ->references('id')
                    ->on(
                        'financial_provider_connections'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'financial_account_id',
                    'post_sale_ext_refund_dispatch_account_fk'
                )
                    ->references('id')
                    ->on('financial_accounts')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'commerce_post_sale_external_refund_evidence',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->uuid('public_id');

                $table->foreignId(
                    'commerce_post_sale_external_refund_dispatch_id'
                );

                $table->foreignId(
                    'financial_external_movement_id'
                );

                $table->string(
                    'source',
                    30
                );

                $table->char(
                    'fingerprint',
                    64
                );

                $table->timestamp(
                    'observed_at'
                );

                $table->timestamp(
                    'created_at'
                );

                $table->unique(
                    'public_id',
                    'post_sale_ext_refund_evidence_public_unique'
                );

                $table->unique(
                    'financial_external_movement_id',
                    'post_sale_ext_refund_evidence_movement_unique'
                );

                $table->index(
                    [
                        'commerce_post_sale_external_refund_dispatch_id',
                        'observed_at',
                    ],
                    'post_sale_ext_refund_evidence_dispatch_index'
                );

                $table->foreign(
                    'organization_id',
                    'post_sale_ext_refund_evidence_org_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'commerce_post_sale_external_refund_dispatch_id',
                    'post_sale_ext_refund_evidence_dispatch_fk'
                )
                    ->references('id')
                    ->on(
                        'commerce_post_sale_external_refund_dispatches'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'financial_external_movement_id',
                    'post_sale_ext_refund_evidence_movement_fk'
                )
                    ->references('id')
                    ->on(
                        'financial_external_movements'
                    )
                    ->restrictOnDelete();
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        throw new LogicException(
            'P8.4.3.2 conserva despacho y evidencia externa append-only; no admite rollback automático.'
        );
    }

    private function createTriggers(): void
    {
        foreach ([
            self::EVIDENCE_DELETE,
            self::EVIDENCE_UPDATE,
            self::EVIDENCE_INSERT,
            self::DISPATCH_DELETE,
            self::DISPATCH_UPDATE,
            self::DISPATCH_INSERT,
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
            'La integridad P8.4.3.2 no está implementada para '
            .$driver.'.'
        );
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_dispatch_guard_insert
BEFORE INSERT ON commerce_post_sale_external_refund_dispatches
WHEN LENGTH(TRIM(NEW.provider_key)) = 0
    OR LENGTH(NEW.provider_key) > 100
    OR LENGTH(TRIM(NEW.provider_idempotency_key)) = 0
    OR LENGTH(NEW.provider_idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_external_refund_instructions instruction
        INNER JOIN financial_provider_connections connection_row
            ON connection_row.id =
                NEW.financial_provider_connection_id
        INNER JOIN financial_accounts account
            ON account.id =
                NEW.financial_account_id
        WHERE instruction.id =
                NEW.commerce_post_sale_external_refund_instruction_id
          AND instruction.organization_id =
                NEW.organization_id
          AND instruction.financial_provider_connection_id =
                connection_row.id
          AND instruction.financial_account_id =
                account.id
          AND instruction.amount_minor > 0
          AND instruction.currency_code =
                account.currency_code
          AND connection_row.organization_id =
                NEW.organization_id
          AND connection_row.financial_account_id =
                account.id
          AND connection_row.active = 1
          AND connection_row.provider_key =
                NEW.provider_key
          AND account.organization_id =
                NEW.organization_id
          AND account.active = 1
          AND NEW.provider_idempotency_key =
                'srcm-refund:' || instruction.public_id
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
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El despacho P8.4.3.2 no conserva instrucción, proveedor, idempotencia o gate válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_dispatch_guard_update
BEFORE UPDATE ON commerce_post_sale_external_refund_dispatches
BEGIN
    SELECT RAISE(
        ABORT,
        'Un despacho de reembolso externo es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_dispatch_guard_delete
BEFORE DELETE ON commerce_post_sale_external_refund_dispatches
BEGIN
    SELECT RAISE(
        ABORT,
        'Un despacho de reembolso externo no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_evidence_guard_insert
BEFORE INSERT ON commerce_post_sale_external_refund_evidence
WHEN NEW.source NOT IN (
        'api',
        'webhook',
        'polling'
    )
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.observed_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_external_refund_dispatches dispatch_row
        INNER JOIN commerce_post_sale_external_refund_instructions instruction
            ON instruction.id =
                dispatch_row.commerce_post_sale_external_refund_instruction_id
        INNER JOIN financial_external_movements movement
            ON movement.id =
                NEW.financial_external_movement_id
        WHERE dispatch_row.id =
                NEW.commerce_post_sale_external_refund_dispatch_id
          AND dispatch_row.organization_id =
                NEW.organization_id
          AND instruction.organization_id =
                NEW.organization_id
          AND movement.organization_id =
                NEW.organization_id
          AND movement.financial_account_id =
                dispatch_row.financial_account_id
          AND movement.source =
                NEW.source
          AND movement.direction = 'debit'
          AND movement.currency_code =
                instruction.currency_code
          AND movement.gross_amount_minor =
                instruction.amount_minor
          AND movement.external_operation_id IS NOT NULL
          AND LENGTH(TRIM(movement.external_operation_id)) > 0
          AND movement.status IN (
              'pending',
              'posted',
              'failed',
              'reversed'
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La evidencia P8.4.3.2 no coincide con el movimiento externo del reembolso.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_evidence_guard_update
BEFORE UPDATE ON commerce_post_sale_external_refund_evidence
BEGIN
    SELECT RAISE(
        ABORT,
        'La evidencia de reembolso externo es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_evidence_guard_delete
BEFORE DELETE ON commerce_post_sale_external_refund_evidence
BEGIN
    SELECT RAISE(
        ABORT,
        'La evidencia de reembolso externo no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_dispatch_guard_insert
BEFORE INSERT ON commerce_post_sale_external_refund_dispatches
FOR EACH ROW
BEGIN
    IF CHAR_LENGTH(TRIM(NEW.provider_key)) = 0
        OR CHAR_LENGTH(NEW.provider_key) > 100
        OR CHAR_LENGTH(TRIM(NEW.provider_idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.provider_idempotency_key) > 180
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_external_refund_instructions instruction
            INNER JOIN financial_provider_connections connection_row
                ON connection_row.id =
                    NEW.financial_provider_connection_id
            INNER JOIN financial_accounts account
                ON account.id =
                    NEW.financial_account_id
            WHERE instruction.id =
                    NEW.commerce_post_sale_external_refund_instruction_id
              AND instruction.organization_id =
                    NEW.organization_id
              AND instruction.financial_provider_connection_id =
                    connection_row.id
              AND instruction.financial_account_id =
                    account.id
              AND instruction.amount_minor > 0
              AND instruction.currency_code =
                    account.currency_code
              AND connection_row.organization_id =
                    NEW.organization_id
              AND connection_row.financial_account_id =
                    account.id
              AND connection_row.active = 1
              AND connection_row.provider_key =
                    NEW.provider_key
              AND account.organization_id =
                    NEW.organization_id
              AND account.active = 1
              AND NEW.provider_idempotency_key =
                    CONCAT(
                        'srcm-refund:',
                        instruction.public_id
                    )
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
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El despacho P8.4.3.2 no conserva instrucción, proveedor, idempotencia o gate válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_dispatch_guard_update
BEFORE UPDATE ON commerce_post_sale_external_refund_dispatches
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un despacho de reembolso externo es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_dispatch_guard_delete
BEFORE DELETE ON commerce_post_sale_external_refund_dispatches
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un despacho de reembolso externo no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_evidence_guard_insert
BEFORE INSERT ON commerce_post_sale_external_refund_evidence
FOR EACH ROW
BEGIN
    IF NEW.source NOT IN (
            'api',
            'webhook',
            'polling'
        )
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.observed_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_external_refund_dispatches dispatch_row
            INNER JOIN commerce_post_sale_external_refund_instructions instruction
                ON instruction.id =
                    dispatch_row.commerce_post_sale_external_refund_instruction_id
            INNER JOIN financial_external_movements movement
                ON movement.id =
                    NEW.financial_external_movement_id
            WHERE dispatch_row.id =
                    NEW.commerce_post_sale_external_refund_dispatch_id
              AND dispatch_row.organization_id =
                    NEW.organization_id
              AND instruction.organization_id =
                    NEW.organization_id
              AND movement.organization_id =
                    NEW.organization_id
              AND movement.financial_account_id =
                    dispatch_row.financial_account_id
              AND movement.source =
                    NEW.source
              AND movement.direction = 'debit'
              AND movement.currency_code =
                    instruction.currency_code
              AND movement.gross_amount_minor =
                    instruction.amount_minor
              AND movement.external_operation_id IS NOT NULL
              AND CHAR_LENGTH(TRIM(movement.external_operation_id)) > 0
              AND movement.status IN (
                  'pending',
                  'posted',
                  'failed',
                  'reversed'
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La evidencia P8.4.3.2 no coincide con el movimiento externo del reembolso.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_evidence_guard_update
BEFORE UPDATE ON commerce_post_sale_external_refund_evidence
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'La evidencia de reembolso externo es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_ext_refund_evidence_guard_delete
BEFORE DELETE ON commerce_post_sale_external_refund_evidence
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'La evidencia de reembolso externo no puede eliminarse.';
END
SQL);
    }
};
