<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXEC_INSERT =
        'post_sale_exchange_executions_guard_insert';
    private const EXEC_UPDATE =
        'post_sale_exchange_executions_guard_update';
    private const EXEC_DELETE =
        'post_sale_exchange_executions_guard_delete';
    private const LINE_INSERT =
        'post_sale_exchange_execution_lines_guard_insert';
    private const LINE_UPDATE =
        'post_sale_exchange_execution_lines_guard_update';
    private const LINE_DELETE =
        'post_sale_exchange_execution_lines_guard_delete';
    private const PAYMENT_INSERT =
        'post_sale_exchange_payments_guard_insert';
    private const PAYMENT_UPDATE =
        'post_sale_exchange_payments_guard_update';
    private const PAYMENT_DELETE =
        'post_sale_exchange_payments_guard_delete';
    private const CREDIT_INSERT =
        'post_sale_exchange_credits_guard_insert';
    private const CREDIT_UPDATE =
        'post_sale_exchange_credits_guard_update';
    private const CREDIT_DELETE =
        'post_sale_exchange_credits_guard_delete';
    private const CASH_MAIN =
        'cash_movements_guard_insert';
    private const CASH_EXCHANGE =
        'cash_movements_post_sale_exchange_guard_insert';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_exchange_executions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'commerce_post_sale_exchange_selection_id'
                )
                    ->unique(
                        'post_sale_exchange_execution_selection_unique'
                    )
                    ->constrained(
                        'commerce_post_sale_exchange_selections'
                    )
                    ->restrictOnDelete();
                $table->foreignId('inventory_movement_id')
                    ->unique(
                        'post_sale_exchange_execution_inventory_unique'
                    )
                    ->constrained('inventory_movements')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'recognized_amount_minor'
                );
                $table->unsignedBigInteger(
                    'replacement_amount_minor'
                );
                $table->bigInteger(
                    'difference_amount_minor'
                );
                $table->char('currency_code', 3);
                $table->foreignId('executed_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('executed_at');
                $table->text('notes')->nullable();
                $table->string('idempotency_key', 180);
                $table->char('fingerprint', 64);
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'post_sale_exchange_execution_org_idem_unique'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_exchange_execution_lines',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_exchange_execution_id'
                )
                    ->constrained(
                        'commerce_post_sale_exchange_executions'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_exchange_selection_line_id'
                )
                    ->unique(
                        'post_sale_exchange_execution_selection_line_unique'
                    )
                    ->constrained(
                        'commerce_post_sale_exchange_selection_lines'
                    )
                    ->restrictOnDelete();
                $table->foreignId('inventory_movement_line_id')
                    ->unique(
                        'post_sale_exchange_execution_inventory_line_unique'
                    )
                    ->constrained('inventory_movement_lines')
                    ->restrictOnDelete();
                $table->unsignedInteger('sequence');
                $table->foreignId('source_location_id')
                    ->constrained('inventory_locations')
                    ->restrictOnDelete();
                $table->string('condition', 40);
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'commerce_post_sale_exchange_execution_id',
                        'sequence',
                    ],
                    'post_sale_exchange_execution_lines_sequence_unique'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_exchange_payments',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_exchange_execution_id'
                )
                    ->constrained(
                        'commerce_post_sale_exchange_executions'
                    )
                    ->restrictOnDelete();
                $table->unsignedInteger('sequence');
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
                $table->string('method', 40);
                $table->unsignedBigInteger('amount_minor');
                $table->unsignedBigInteger(
                    'tendered_amount_minor'
                )->nullable();
                $table->unsignedBigInteger(
                    'change_amount_minor'
                )->nullable();
                $table->string('reference', 255)
                    ->nullable();
                $table->string('card_brand', 50)
                    ->nullable();
                $table->string('card_network', 50)
                    ->nullable();
                $table->string('card_last4', 4)
                    ->nullable();
                $table->unsignedSmallInteger('installments')
                    ->nullable();
                $table->string('processor', 100)
                    ->nullable();
                $table->string('external_operation_id', 191)
                    ->nullable();
                $table->string('authorization_code', 100)
                    ->nullable();
                $table->string('provider_status', 50)
                    ->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('received_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('paid_at');
                $table->char('fingerprint', 64);
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'commerce_post_sale_exchange_execution_id',
                        'sequence',
                    ],
                    'post_sale_exchange_payments_sequence_unique'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_exchange_credit_grants',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'commerce_post_sale_exchange_execution_id'
                )
                    ->unique(
                        'post_sale_exchange_credit_execution_unique'
                    )
                    ->constrained(
                        'commerce_post_sale_exchange_executions'
                    )
                    ->restrictOnDelete();
                $table->foreignId('business_party_id')
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('amount_minor');
                $table->char('currency_code', 3);
                $table->foreignId('granted_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('granted_at');
                $table->char('fingerprint', 64);
                $table->timestamp('created_at');
            }
        );

        $this->addCashMovementLink();
        $this->extendExistingCashInsertGuard();
        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P8.4.5 conserva ejecución, inventario y hechos monetarios append-only; no admite rollback automático.'
        );
    }

    private function addCashMovementLink(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "post_sale_exchange_payment_id"
INTEGER NULL
REFERENCES "commerce_post_sale_exchange_payments" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX
"cash_movements_post_sale_exchange_payment_unique"
ON "cash_movements" ("post_sale_exchange_payment_id")
SQL);

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            Schema::table(
                'cash_movements',
                function (Blueprint $table): void {
                    $table->foreignId(
                        'post_sale_exchange_payment_id'
                    )
                        ->nullable()
                        ->unique(
                            'cash_movements_post_sale_exchange_payment_unique'
                        )
                        ->after(
                            'post_sale_cash_refund_execution_id'
                        )
                        ->constrained(
                            'commerce_post_sale_exchange_payments'
                        )
                        ->restrictOnDelete();
                }
            );

            return;
        }

        throw new LogicException(
            'La extensión de caja P8.4.5 no está implementada para '
            .$driver.'.'
        );
    }

    private function extendExistingCashInsertGuard(): void
    {
        $driver = DB::getDriverName();
        $pattern =
            "/'sale_payment'\s*,\s*'security_drop'\s*,\s*'purchase_payment'\s*,\s*'post_sale_refund'/";
        $replacement =
            "'sale_payment', 'security_drop', 'purchase_payment', 'post_sale_refund', 'post_sale_exchange_difference'";

        if ($driver === 'sqlite') {
            $row = DB::selectOne(<<<'SQL'
SELECT sql
FROM sqlite_master
WHERE type = 'trigger'
  AND name = 'cash_movements_guard_insert'
SQL);

            $sql =
                is_object($row)
                    && isset($row->sql)
                    && is_string($row->sql)
                    ? $row->sql
                    : null;

            if ($sql === null) {
                throw new LogicException(
                    'P8.4.5 no encontró el guard actual de cash_movements.'
                );
            }

            $extended =
                preg_replace(
                    $pattern,
                    $replacement,
                    $sql,
                    1,
                    $count
                );

            if (
                $extended === null
                || $count !== 1
            ) {
                throw new LogicException(
                    'P8.4.5 no pudo extender exactamente una vez el guard SQLite de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::CASH_MAIN
            );
            DB::unprepared($extended);

            return;
        }

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'cash_movements_guard_insert'
SQL);

            $body =
                is_object($row)
                    && isset($row->action_statement)
                    && is_string($row->action_statement)
                    ? $row->action_statement
                    : null;

            if ($body === null) {
                throw new LogicException(
                    'P8.4.5 no encontró el guard MySQL actual de cash_movements.'
                );
            }

            $extendedBody =
                preg_replace(
                    $pattern,
                    $replacement,
                    $body,
                    1,
                    $count
                );

            if (
                $extendedBody === null
                || $count !== 1
            ) {
                throw new LogicException(
                    'P8.4.5 no pudo extender exactamente una vez el guard MySQL de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.self::CASH_MAIN
            );

            DB::unprepared(
                'CREATE TRIGGER '.self::CASH_MAIN
                .' BEFORE INSERT ON cash_movements '
                .'FOR EACH ROW '.$extendedBody
            );

            return;
        }

        throw new LogicException(
            'La extensión del guard de caja P8.4.5 no está implementada para '
            .$driver.'.'
        );
    }

    private function createGuards(): void
    {
        foreach ([
            self::CASH_EXCHANGE,
            self::CREDIT_DELETE,
            self::CREDIT_UPDATE,
            self::CREDIT_INSERT,
            self::PAYMENT_DELETE,
            self::PAYMENT_UPDATE,
            self::PAYMENT_INSERT,
            self::LINE_DELETE,
            self::LINE_UPDATE,
            self::LINE_INSERT,
            self::EXEC_DELETE,
            self::EXEC_UPDATE,
            self::EXEC_INSERT,
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

        if (
            in_array(
                $driver,
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlGuards();

            return;
        }

        throw new LogicException(
            'La integridad P8.4.5 no está implementada para '
            .$driver.'.'
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_executions_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_executions
WHEN NEW.recognized_amount_minor <= 0
    OR NEW.replacement_amount_minor <= 0
    OR NEW.difference_amount_minor <>
        NEW.replacement_amount_minor
        - NEW.recognized_amount_minor
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code) <> NEW.currency_code
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.idempotency_key) > 180
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.executed_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_exchange_selections selection
        INNER JOIN commerce_post_sale_resolutions resolution
            ON resolution.id =
                selection.commerce_post_sale_resolution_id
        INNER JOIN commerce_post_sale_requests request_row
            ON request_row.id =
                resolution.commerce_post_sale_request_id
        INNER JOIN commerce_sales sale
            ON sale.id =
                request_row.commerce_sale_id
        WHERE selection.id =
                NEW.commerce_post_sale_exchange_selection_id
          AND selection.organization_id =
                NEW.organization_id
          AND selection.currency_code =
                NEW.currency_code
          AND selection.recognized_amount_minor =
                NEW.recognized_amount_minor
          AND resolution.organization_id =
                NEW.organization_id
          AND resolution.outcome = 'exchange'
          AND resolution.resolved_by_user_id IS NOT NULL
          AND resolution.resolved_by_user_id <>
                NEW.executed_by_user_id
          AND request_row.organization_id =
                NEW.organization_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.status = 'confirmed'
          AND sale.currency_code =
                NEW.currency_code
          AND selection.selected_by_user_id <>
                NEW.executed_by_user_id
          AND (
              SELECT COALESCE(
                  SUM(line.line_amount_minor),
                  0
              )
              FROM commerce_post_sale_exchange_selection_lines line
              WHERE line.organization_id =
                    NEW.organization_id
                AND line.commerce_post_sale_exchange_selection_id =
                    selection.id
          ) = NEW.replacement_amount_minor
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.executed_by_user_id
          AND membership.active = 1
          AND membership.role IN ('admin', 'operator')
    )
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_movements movement
        INNER JOIN commerce_post_sale_exchange_selections selection
            ON selection.id =
                NEW.commerce_post_sale_exchange_selection_id
        WHERE movement.id =
                NEW.inventory_movement_id
          AND movement.organization_id =
                NEW.organization_id
          AND movement.type = 'issue'
          AND movement.status = 'confirmed'
          AND movement.created_by_user_id =
                NEW.executed_by_user_id
          AND movement.confirmed_by_user_id =
                NEW.executed_by_user_id
          AND movement.source_type =
                'commerce_post_sale_exchange_selection'
          AND movement.source_id =
                selection.public_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La ejecución de cambio no conserva selección, inventario, importes o segregación válidos.'
    );
END
SQL);

        $this->sqliteImmutable(
            self::EXEC_UPDATE,
            'commerce_post_sale_exchange_executions',
            'Una ejecución de cambio confirmada es inmutable.'
        );
        $this->sqliteDeleteImmutable(
            self::EXEC_DELETE,
            'commerce_post_sale_exchange_executions',
            'Una ejecución de cambio confirmada no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_execution_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_execution_lines
WHEN NEW.sequence <= 0
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_exchange_executions execution
        INNER JOIN commerce_post_sale_exchange_selections selection
            ON selection.id =
                execution.commerce_post_sale_exchange_selection_id
        INNER JOIN commerce_post_sale_exchange_selection_lines selection_line
            ON selection_line.id =
                NEW.commerce_post_sale_exchange_selection_line_id
        INNER JOIN inventory_movement_lines movement_line
            ON movement_line.id =
                NEW.inventory_movement_line_id
        INNER JOIN inventory_locations location_row
            ON location_row.id =
                NEW.source_location_id
        WHERE execution.id =
                NEW.commerce_post_sale_exchange_execution_id
          AND execution.organization_id =
                NEW.organization_id
          AND selection.organization_id =
                NEW.organization_id
          AND selection_line.organization_id =
                NEW.organization_id
          AND selection_line.commerce_post_sale_exchange_selection_id =
                selection.id
          AND selection_line.sequence =
                NEW.sequence
          AND movement_line.organization_id =
                NEW.organization_id
          AND movement_line.inventory_movement_id =
                execution.inventory_movement_id
          AND movement_line.sequence =
                NEW.sequence
          AND movement_line.catalog_product_id =
                selection_line.catalog_product_id
          AND movement_line.source_location_id =
                NEW.source_location_id
          AND movement_line.destination_location_id IS NULL
          AND movement_line.condition =
                NEW.condition
          AND movement_line.base_quantity =
                selection_line.quantity
          AND location_row.organization_id =
                NEW.organization_id
          AND location_row.active = 1
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea de ejecución no conserva selección, origen, condición o salida de inventario válidos.'
    );
END
SQL);

        $this->sqliteImmutable(
            self::LINE_UPDATE,
            'commerce_post_sale_exchange_execution_lines',
            'Una línea de ejecución de cambio es inmutable.'
        );
        $this->sqliteDeleteImmutable(
            self::LINE_DELETE,
            'commerce_post_sale_exchange_execution_lines',
            'Una línea de ejecución de cambio no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_payments_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_payments
WHEN NEW.sequence <= 0
    OR NEW.amount_minor <= 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.paid_at IS NULL
    OR NEW.created_at IS NULL
    OR NEW.method NOT IN (
        'cash',
        'debit_card',
        'credit_card',
        'bank_transfer',
        'digital_wallet',
        'other'
    )
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_exchange_executions execution
        INNER JOIN financial_accounts account
            ON account.id =
                NEW.financial_account_id
        WHERE execution.id =
                NEW.commerce_post_sale_exchange_execution_id
          AND execution.organization_id =
                NEW.organization_id
          AND execution.difference_amount_minor > 0
          AND execution.executed_by_user_id =
                NEW.received_by_user_id
          AND account.organization_id =
                NEW.organization_id
          AND account.active = 1
          AND account.currency_code =
                execution.currency_code
    )
    OR (
        NEW.method = 'cash'
        AND (
            NEW.cash_register_session_id IS NULL
            OR NEW.cash_register_id IS NULL
            OR NEW.card_brand IS NOT NULL
            OR NEW.card_network IS NOT NULL
            OR NEW.card_last4 IS NOT NULL
            OR NEW.installments IS NOT NULL
            OR NEW.processor IS NOT NULL
            OR NEW.external_operation_id IS NOT NULL
            OR NEW.authorization_code IS NOT NULL
            OR NEW.provider_status IS NOT NULL
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
                AND NEW.change_amount_minor <>
                    NEW.tendered_amount_minor
                    - NEW.amount_minor
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
                  AND register_row.organization_id =
                        NEW.organization_id
                  AND register_row.active = 1
                  AND register_row.financial_account_id =
                        NEW.financial_account_id
                  AND account.organization_id =
                        NEW.organization_id
                  AND account.active = 1
                  AND account.type = 'cash_box'
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
            OR NEW.reference IS NULL
            OR LENGTH(TRIM(NEW.reference)) = 0
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
    OR (
        NEW.method NOT IN (
            'debit_card',
            'credit_card'
        )
        AND (
            NEW.card_brand IS NOT NULL
            OR NEW.card_network IS NOT NULL
            OR NEW.card_last4 IS NOT NULL
            OR NEW.installments IS NOT NULL
        )
    )
    OR (
        NEW.card_last4 IS NOT NULL
        AND (
            LENGTH(NEW.card_last4) <> 4
            OR NEW.card_last4 GLOB '*[^0-9]*'
        )
    )
    OR (
        NEW.installments IS NOT NULL
        AND (
            NEW.installments < 1
            OR NEW.installments > 120
        )
    )
    OR (
        COALESCE(
            (
                SELECT SUM(previous.amount_minor)
                FROM commerce_post_sale_exchange_payments previous
                WHERE previous.organization_id =
                    NEW.organization_id
                  AND previous.commerce_post_sale_exchange_execution_id =
                    NEW.commerce_post_sale_exchange_execution_id
            ),
            0
        ) + NEW.amount_minor
        >
        (
            SELECT execution.difference_amount_minor
            FROM commerce_post_sale_exchange_executions execution
            WHERE execution.id =
                NEW.commerce_post_sale_exchange_execution_id
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El cobro de diferencia de cambio no conserva ejecución, cuenta, medio o importe válidos.'
    );
END
SQL);

        $this->sqliteImmutable(
            self::PAYMENT_UPDATE,
            'commerce_post_sale_exchange_payments',
            'Un cobro de diferencia de cambio es inmutable.'
        );
        $this->sqliteDeleteImmutable(
            self::PAYMENT_DELETE,
            'commerce_post_sale_exchange_payments',
            'Un cobro de diferencia de cambio no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_credits_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_credit_grants
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code) <> NEW.currency_code
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.granted_at IS NULL
    OR NEW.created_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_exchange_executions execution
        INNER JOIN commerce_post_sale_exchange_selections selection
            ON selection.id =
                execution.commerce_post_sale_exchange_selection_id
        INNER JOIN commerce_post_sale_resolutions resolution
            ON resolution.id =
                selection.commerce_post_sale_resolution_id
        INNER JOIN commerce_post_sale_requests request_row
            ON request_row.id =
                resolution.commerce_post_sale_request_id
        INNER JOIN commerce_sales sale
            ON sale.id =
                request_row.commerce_sale_id
        INNER JOIN business_parties party
            ON party.id =
                NEW.business_party_id
        WHERE execution.id =
                NEW.commerce_post_sale_exchange_execution_id
          AND execution.organization_id =
                NEW.organization_id
          AND execution.difference_amount_minor < 0
          AND NEW.amount_minor =
                -execution.difference_amount_minor
          AND NEW.currency_code =
                execution.currency_code
          AND NEW.granted_by_user_id =
                execution.executed_by_user_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.customer_business_party_id =
                NEW.business_party_id
          AND party.organization_id =
                NEW.organization_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El crédito de diferencia de cambio no conserva cliente, importe o ejecución válidos.'
    );
END
SQL);

        $this->sqliteImmutable(
            self::CREDIT_UPDATE,
            'commerce_post_sale_exchange_credit_grants',
            'Un crédito por diferencia de cambio es inmutable.'
        );
        $this->sqliteDeleteImmutable(
            self::CREDIT_DELETE,
            'commerce_post_sale_exchange_credit_grants',
            'Un crédito por diferencia de cambio no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_post_sale_exchange_guard_insert
BEFORE INSERT ON cash_movements
WHEN NEW.type = 'post_sale_exchange_difference'
AND (
    NEW.direction <> 'in'
    OR NEW.post_sale_exchange_payment_id IS NULL
    OR NEW.commerce_payment_id IS NOT NULL
    OR NEW.destination_financial_account_id IS NOT NULL
    OR NEW.cash_security_drop_request_id IS NOT NULL
    OR NEW.purchase_payment_execution_id IS NOT NULL
    OR NEW.post_sale_cash_refund_execution_id IS NOT NULL
    OR NEW.reason_code IS NOT NULL
    OR NEW.note IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_exchange_payments payment
        INNER JOIN commerce_post_sale_exchange_executions execution
            ON execution.id =
                payment.commerce_post_sale_exchange_execution_id
        WHERE payment.id =
                NEW.post_sale_exchange_payment_id
          AND payment.organization_id =
                NEW.organization_id
          AND payment.method = 'cash'
          AND payment.cash_register_session_id =
                NEW.cash_register_session_id
          AND payment.cash_register_id =
                NEW.cash_register_id
          AND payment.financial_account_id =
                NEW.financial_account_id
          AND payment.amount_minor =
                NEW.amount_minor
          AND payment.received_by_user_id =
                NEW.recorded_by_user_id
          AND execution.organization_id =
                NEW.organization_id
          AND execution.currency_code =
                NEW.currency_code
          AND execution.difference_amount_minor > 0
    )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'El movimiento de caja de diferencia de cambio no conserva el cobro asociado.'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_executions_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_executions
FOR EACH ROW
BEGIN
    IF NEW.recognized_amount_minor <= 0
        OR NEW.replacement_amount_minor <= 0
        OR NEW.difference_amount_minor <>
            NEW.replacement_amount_minor
            - NEW.recognized_amount_minor
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR UPPER(NEW.currency_code) <> NEW.currency_code
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.idempotency_key) > 180
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.executed_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_selections selection
            INNER JOIN commerce_post_sale_resolutions resolution
                ON resolution.id =
                    selection.commerce_post_sale_resolution_id
            INNER JOIN commerce_post_sale_requests request_row
                ON request_row.id =
                    resolution.commerce_post_sale_request_id
            INNER JOIN commerce_sales sale
                ON sale.id =
                    request_row.commerce_sale_id
            WHERE selection.id =
                    NEW.commerce_post_sale_exchange_selection_id
              AND selection.organization_id =
                    NEW.organization_id
              AND selection.currency_code =
                    NEW.currency_code
              AND selection.recognized_amount_minor =
                    NEW.recognized_amount_minor
              AND resolution.organization_id =
                    NEW.organization_id
              AND resolution.outcome = 'exchange'
              AND resolution.resolved_by_user_id IS NOT NULL
              AND resolution.resolved_by_user_id <>
                    NEW.executed_by_user_id
              AND request_row.organization_id =
                    NEW.organization_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.status = 'confirmed'
              AND sale.currency_code =
                    NEW.currency_code
              AND selection.selected_by_user_id <>
                    NEW.executed_by_user_id
              AND (
                  SELECT COALESCE(
                      SUM(line.line_amount_minor),
                      0
                  )
                  FROM commerce_post_sale_exchange_selection_lines line
                  WHERE line.organization_id =
                        NEW.organization_id
                    AND line.commerce_post_sale_exchange_selection_id =
                        selection.id
              ) = NEW.replacement_amount_minor
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.executed_by_user_id
              AND membership.active = 1
              AND membership.role IN (
                  'admin',
                  'operator'
              )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_movements movement
            INNER JOIN commerce_post_sale_exchange_selections selection
                ON selection.id =
                    NEW.commerce_post_sale_exchange_selection_id
            WHERE movement.id =
                    NEW.inventory_movement_id
              AND movement.organization_id =
                    NEW.organization_id
              AND movement.type = 'issue'
              AND movement.status = 'confirmed'
              AND movement.created_by_user_id =
                    NEW.executed_by_user_id
              AND movement.confirmed_by_user_id =
                    NEW.executed_by_user_id
              AND movement.source_type =
                    'commerce_post_sale_exchange_selection'
              AND movement.source_id =
                    selection.public_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La ejecucion de cambio no conserva seleccion, inventario, importes o segregacion validos.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            self::EXEC_UPDATE,
            'commerce_post_sale_exchange_executions',
            'Una ejecucion de cambio confirmada es inmutable.'
        );
        $this->mysqlDeleteImmutable(
            self::EXEC_DELETE,
            'commerce_post_sale_exchange_executions',
            'Una ejecucion de cambio confirmada no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_execution_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_execution_lines
FOR EACH ROW
BEGIN
    IF NEW.sequence <= 0
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_executions execution
            INNER JOIN commerce_post_sale_exchange_selections selection
                ON selection.id =
                    execution.commerce_post_sale_exchange_selection_id
            INNER JOIN commerce_post_sale_exchange_selection_lines selection_line
                ON selection_line.id =
                    NEW.commerce_post_sale_exchange_selection_line_id
            INNER JOIN inventory_movement_lines movement_line
                ON movement_line.id =
                    NEW.inventory_movement_line_id
            INNER JOIN inventory_locations location_row
                ON location_row.id =
                    NEW.source_location_id
            WHERE execution.id =
                    NEW.commerce_post_sale_exchange_execution_id
              AND execution.organization_id =
                    NEW.organization_id
              AND selection.organization_id =
                    NEW.organization_id
              AND selection_line.organization_id =
                    NEW.organization_id
              AND selection_line.commerce_post_sale_exchange_selection_id =
                    selection.id
              AND selection_line.sequence =
                    NEW.sequence
              AND movement_line.organization_id =
                    NEW.organization_id
              AND movement_line.inventory_movement_id =
                    execution.inventory_movement_id
              AND movement_line.sequence =
                    NEW.sequence
              AND movement_line.catalog_product_id =
                    selection_line.catalog_product_id
              AND movement_line.source_location_id =
                    NEW.source_location_id
              AND movement_line.destination_location_id IS NULL
              AND movement_line.condition =
                    NEW.condition
              AND movement_line.base_quantity =
                    selection_line.quantity
              AND location_row.organization_id =
                    NEW.organization_id
              AND location_row.active = 1
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La linea de ejecucion no conserva seleccion, origen, condicion o inventario validos.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            self::LINE_UPDATE,
            'commerce_post_sale_exchange_execution_lines',
            'Una linea de ejecucion de cambio es inmutable.'
        );
        $this->mysqlDeleteImmutable(
            self::LINE_DELETE,
            'commerce_post_sale_exchange_execution_lines',
            'Una linea de ejecucion de cambio no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_payments_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_payments
FOR EACH ROW
BEGIN
    IF NEW.sequence <= 0
        OR NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.paid_at IS NULL
        OR NEW.created_at IS NULL
        OR NEW.method NOT IN (
            'cash',
            'debit_card',
            'credit_card',
            'bank_transfer',
            'digital_wallet',
            'other'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_executions execution
            INNER JOIN financial_accounts account
                ON account.id =
                    NEW.financial_account_id
            WHERE execution.id =
                    NEW.commerce_post_sale_exchange_execution_id
              AND execution.organization_id =
                    NEW.organization_id
              AND execution.difference_amount_minor > 0
              AND execution.executed_by_user_id =
                    NEW.received_by_user_id
              AND account.organization_id =
                    NEW.organization_id
              AND account.active = 1
              AND account.currency_code =
                    execution.currency_code
        )
        OR (
            NEW.method = 'cash'
            AND (
                NEW.cash_register_session_id IS NULL
                OR NEW.cash_register_id IS NULL
                OR NEW.card_brand IS NOT NULL
                OR NEW.card_network IS NOT NULL
                OR NEW.card_last4 IS NOT NULL
                OR NEW.installments IS NOT NULL
                OR NEW.processor IS NOT NULL
                OR NEW.external_operation_id IS NOT NULL
                OR NEW.authorization_code IS NOT NULL
                OR NEW.provider_status IS NOT NULL
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
                    AND NEW.change_amount_minor <>
                        NEW.tendered_amount_minor
                        - NEW.amount_minor
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
                      AND register_row.organization_id =
                            NEW.organization_id
                      AND register_row.active = 1
                      AND register_row.financial_account_id =
                            NEW.financial_account_id
                      AND account.organization_id =
                            NEW.organization_id
                      AND account.active = 1
                      AND account.type = 'cash_box'
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
                OR NEW.reference IS NULL
                OR CHAR_LENGTH(TRIM(NEW.reference)) = 0
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
        OR (
            NEW.method NOT IN (
                'debit_card',
                'credit_card'
            )
            AND (
                NEW.card_brand IS NOT NULL
                OR NEW.card_network IS NOT NULL
                OR NEW.card_last4 IS NOT NULL
                OR NEW.installments IS NOT NULL
            )
        )
        OR (
            NEW.card_last4 IS NOT NULL
            AND (
                CHAR_LENGTH(NEW.card_last4) <> 4
                OR NEW.card_last4 REGEXP '[^0-9]'
            )
        )
        OR (
            NEW.installments IS NOT NULL
            AND (
                NEW.installments < 1
                OR NEW.installments > 120
            )
        )
        OR (
            COALESCE(
                (
                    SELECT SUM(previous.amount_minor)
                    FROM commerce_post_sale_exchange_payments previous
                    WHERE previous.organization_id =
                        NEW.organization_id
                      AND previous.commerce_post_sale_exchange_execution_id =
                        NEW.commerce_post_sale_exchange_execution_id
                ),
                0
            ) + NEW.amount_minor
            >
            (
                SELECT execution.difference_amount_minor
                FROM commerce_post_sale_exchange_executions execution
                WHERE execution.id =
                    NEW.commerce_post_sale_exchange_execution_id
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El cobro de diferencia no conserva ejecucion, cuenta, medio o importe validos.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            self::PAYMENT_UPDATE,
            'commerce_post_sale_exchange_payments',
            'Un cobro de diferencia de cambio es inmutable.'
        );
        $this->mysqlDeleteImmutable(
            self::PAYMENT_DELETE,
            'commerce_post_sale_exchange_payments',
            'Un cobro de diferencia de cambio no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER post_sale_exchange_credits_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_credit_grants
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR UPPER(NEW.currency_code) <> NEW.currency_code
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.granted_at IS NULL
        OR NEW.created_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_executions execution
            INNER JOIN commerce_post_sale_exchange_selections selection
                ON selection.id =
                    execution.commerce_post_sale_exchange_selection_id
            INNER JOIN commerce_post_sale_resolutions resolution
                ON resolution.id =
                    selection.commerce_post_sale_resolution_id
            INNER JOIN commerce_post_sale_requests request_row
                ON request_row.id =
                    resolution.commerce_post_sale_request_id
            INNER JOIN commerce_sales sale
                ON sale.id =
                    request_row.commerce_sale_id
            INNER JOIN business_parties party
                ON party.id =
                    NEW.business_party_id
            WHERE execution.id =
                    NEW.commerce_post_sale_exchange_execution_id
              AND execution.organization_id =
                    NEW.organization_id
              AND execution.difference_amount_minor < 0
              AND NEW.amount_minor =
                    -execution.difference_amount_minor
              AND NEW.currency_code =
                    execution.currency_code
              AND NEW.granted_by_user_id =
                    execution.executed_by_user_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.customer_business_party_id =
                    NEW.business_party_id
              AND party.organization_id =
                    NEW.organization_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El credito de diferencia no conserva cliente, importe o ejecucion validos.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            self::CREDIT_UPDATE,
            'commerce_post_sale_exchange_credit_grants',
            'Un credito por diferencia de cambio es inmutable.'
        );
        $this->mysqlDeleteImmutable(
            self::CREDIT_DELETE,
            'commerce_post_sale_exchange_credit_grants',
            'Un credito por diferencia de cambio no puede eliminarse.'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER cash_movements_post_sale_exchange_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    IF NEW.type = 'post_sale_exchange_difference'
        AND (
            NEW.direction <> 'in'
            OR NEW.post_sale_exchange_payment_id IS NULL
            OR NEW.commerce_payment_id IS NOT NULL
            OR NEW.destination_financial_account_id IS NOT NULL
            OR NEW.cash_security_drop_request_id IS NOT NULL
            OR NEW.purchase_payment_execution_id IS NOT NULL
            OR NEW.post_sale_cash_refund_execution_id IS NOT NULL
            OR NEW.reason_code IS NOT NULL
            OR NEW.note IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM commerce_post_sale_exchange_payments payment
                INNER JOIN commerce_post_sale_exchange_executions execution
                    ON execution.id =
                        payment.commerce_post_sale_exchange_execution_id
                WHERE payment.id =
                        NEW.post_sale_exchange_payment_id
                  AND payment.organization_id =
                        NEW.organization_id
                  AND payment.method = 'cash'
                  AND payment.cash_register_session_id =
                        NEW.cash_register_session_id
                  AND payment.cash_register_id =
                        NEW.cash_register_id
                  AND payment.financial_account_id =
                        NEW.financial_account_id
                  AND payment.amount_minor =
                        NEW.amount_minor
                  AND payment.received_by_user_id =
                        NEW.recorded_by_user_id
                  AND execution.organization_id =
                        NEW.organization_id
                  AND execution.currency_code =
                        NEW.currency_code
                  AND execution.difference_amount_minor > 0
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El movimiento de caja de diferencia no conserva el cobro asociado.';
    END IF;
END
SQL);
    }

    private function sqliteImmutable(
        string $name,
        string $table,
        string $message
    ): void {
        DB::unprepared(
            'CREATE TRIGGER '.$name
            .' BEFORE UPDATE ON '.$table
            .' BEGIN SELECT RAISE(ABORT, '
            .$this->sqliteString($message)
            .'); END'
        );
    }

    private function sqliteDeleteImmutable(
        string $name,
        string $table,
        string $message
    ): void {
        DB::unprepared(
            'CREATE TRIGGER '.$name
            .' BEFORE DELETE ON '.$table
            .' BEGIN SELECT RAISE(ABORT, '
            .$this->sqliteString($message)
            .'); END'
        );
    }

    private function mysqlImmutable(
        string $name,
        string $table,
        string $message
    ): void {
        DB::unprepared(
            'CREATE TRIGGER '.$name
            .' BEFORE UPDATE ON '.$table
            .' FOR EACH ROW BEGIN SIGNAL SQLSTATE \'45000\' '
            .'SET MESSAGE_TEXT = '
            .$this->mysqlString($message)
            .'; END'
        );
    }

    private function mysqlDeleteImmutable(
        string $name,
        string $table,
        string $message
    ): void {
        DB::unprepared(
            'CREATE TRIGGER '.$name
            .' BEFORE DELETE ON '.$table
            .' FOR EACH ROW BEGIN SIGNAL SQLSTATE \'45000\' '
            .'SET MESSAGE_TEXT = '
            .$this->mysqlString($message)
            .'; END'
        );
    }

    private function sqliteString(
        string $value
    ): string {
        return "'"
            .str_replace("'", "''", $value)
            ."'";
    }

    private function mysqlString(
        string $value
    ): string {
        return "'"
            .str_replace("'", "''", $value)
            ."'";
    }
};
