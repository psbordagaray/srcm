<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CASH_MAIN =
        'cash_movements_guard_insert';

    private const REQUEST_UPDATE =
        'purchase_payment_requests_guard_update';

    private const GROUP_UPDATE =
        'purchase_payment_group_request_guard_update';

    public function up(): void
    {
        Schema::create(
            'purchase_payment_disbursements',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'purchase_payment_request_id'
                )
                    ->nullable()
                    ->unique()
                    ->constrained(
                        'purchase_payment_requests'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_payment_group_request_id'
                )
                    ->nullable()
                    ->unique()
                    ->constrained(
                        'purchase_payment_group_requests'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'origin_financial_account_id'
                )
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->foreignId(
                    'beneficiary_business_party_id'
                )
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->string('channel', 16);
                $table->foreignId(
                    'cash_register_session_id'
                )
                    ->nullable()
                    ->constrained(
                        'cash_register_sessions'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'cash_register_id'
                )
                    ->nullable()
                    ->constrained('cash_registers')
                    ->restrictOnDelete();
                $table->foreignId(
                    'executed_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->char('currency_code', 3);
                $table->string(
                    'execution_reference',
                    180
                )->nullable();
                $table->text(
                    'execution_note'
                )->nullable();
                $table->string(
                    'idempotency_key',
                    180
                );
                $table->char('fingerprint', 64);
                $table->timestamp('executed_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'purchase_disbursement_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'origin_financial_account_id',
                        'executed_at',
                    ],
                    'purchase_disbursement_origin_index'
                );
                $table->index(
                    [
                        'organization_id',
                        'beneficiary_business_party_id',
                        'currency_code',
                        'executed_at',
                    ],
                    'purchase_disbursement_beneficiary_index'
                );
            }
        );

        Schema::create(
            'purchase_payment_disbursement_allocations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_payment_disbursement_id'
                )
                    ->constrained(
                        'purchase_payment_disbursements'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_obligation_id'
                )
                    ->constrained(
                        'purchase_obligations'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_payment_request_id'
                )
                    ->nullable()
                    ->unique()
                    ->constrained(
                        'purchase_payment_requests'
                    )
                    ->restrictOnDelete();
                $table->foreignId(
                    'purchase_payment_group_request_item_id'
                )
                    ->nullable()
                    ->unique()
                    ->constrained(
                        'purchase_payment_group_request_items'
                    )
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'amount_minor'
                );
                $table->char('fingerprint', 64);
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'purchase_payment_disbursement_id',
                        'purchase_obligation_id',
                    ],
                    'purchase_disbursement_allocation_obligation_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'purchase_obligation_id',
                    ],
                    'purchase_disbursement_allocation_balance_index'
                );
            }
        );

        $this->addCashMovementLink();
        $this->extendCashMovementAllowedTypes();
        $this->extendIndividualExecutedEvidence();
        $this->extendGroupExecutedTransition();
        $this->createGuards();
    }

    public function down(): void
    {
        throw new LogicException(
            'P9.7i conserva desembolsos e imputaciones append-only y no admite rollback automático.'
        );
    }

    private function addCashMovementLink(): void
    {
        $driver = DB::getDriverName();

        if (Schema::hasColumn(
            'cash_movements',
            'purchase_payment_disbursement_id'
        )) {
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE "cash_movements"
ADD COLUMN "purchase_payment_disbursement_id"
INTEGER NULL
REFERENCES "purchase_payment_disbursements" ("id")
ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX
"cash_movements_purchase_payment_disbursement_id_unique"
ON "cash_movements" ("purchase_payment_disbursement_id")
SQL);

            return;
        }

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            Schema::table(
                'cash_movements',
                function (Blueprint $table): void {
                    $table->foreignId(
                        'purchase_payment_disbursement_id'
                    )
                        ->nullable()
                        ->unique(
                            'cash_movements_purchase_payment_disbursement_id_unique'
                        )
                        ->after(
                            'purchase_payment_execution_id'
                        )
                        ->constrained(
                            'purchase_payment_disbursements'
                        )
                        ->restrictOnDelete();
                }
            );

            return;
        }

        throw new LogicException(
            "P9.7i no implementa vínculo cash para {$driver}."
        );
    }

    private function currentTriggerBody(
        string $trigger
    ): string {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $row = DB::selectOne(
                'SELECT sql FROM sqlite_master '
                ."WHERE type = 'trigger' AND name = ?",
                [$trigger]
            );

            $sql = is_object($row)
                && isset($row->sql)
                && is_string($row->sql)
                    ? $row->sql
                    : null;

            if ($sql === null) {
                throw new LogicException(
                    "No se encontró trigger SQLite {$trigger}."
                );
            }

            return $sql;
        }

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = ?
SQL, [$trigger]);

            $body = is_object($row)
                && isset($row->action_statement)
                && is_string(
                    $row->action_statement
                )
                    ? $row->action_statement
                    : null;

            if ($body === null) {
                throw new LogicException(
                    "No se encontró trigger MySQL {$trigger}."
                );
            }

            return $body;
        }

        throw new LogicException(
            "P9.7i no puede leer triggers para {$driver}."
        );
    }

    private function replaceTriggerBody(
        string $trigger,
        string $body
    ): void {
        $driver = DB::getDriverName();

        DB::unprepared(
            'DROP TRIGGER IF EXISTS '.$trigger
        );

        if ($driver === 'sqlite') {
            DB::unprepared($body);

            return;
        }

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            DB::unprepared(
                'CREATE TRIGGER '.$trigger
                .' BEFORE UPDATE ON '
                .$this->triggerTable($trigger)
                .' FOR EACH ROW '
                .$body
            );

            return;
        }

        throw new LogicException(
            "P9.7i no puede recrear triggers para {$driver}."
        );
    }

    private function triggerTable(
        string $trigger
    ): string {
        return match ($trigger) {
            self::REQUEST_UPDATE =>
                'purchase_payment_requests',
            self::GROUP_UPDATE =>
                'purchase_payment_group_requests',
            default => throw new LogicException(
                'Tabla de trigger desconocida.'
            ),
        };
    }

    private function extendCashMovementAllowedTypes():
        void
    {
        $driver = DB::getDriverName();

        $pattern =
            "/'sale_payment'\\s*,\\s*"
            ."'security_drop'\\s*,\\s*"
            ."'purchase_payment'\\s*,\\s*"
            ."'post_sale_refund'\\s*,\\s*"
            ."'post_sale_exchange_difference'\\s*,\\s*"
            ."'customer_collection'\\s*,\\s*"
            ."'customer_advance'\\s*,\\s*"
            ."'supplier_advance'/";

        $replacement =
            "'sale_payment', 'security_drop', "
            ."'purchase_payment', "
            ."'post_sale_refund', "
            ."'post_sale_exchange_difference', "
            ."'customer_collection', "
            ."'customer_advance', "
            ."'supplier_advance', "
            ."'purchase_payment_disbursement'";

        if ($driver === 'sqlite') {
            $sql = $this->currentTriggerBody(
                self::CASH_MAIN
            );

            $extended = preg_replace(
                $pattern,
                $replacement,
                $sql,
                1,
                $count
            );

            if (
                ! is_string($extended)
                || $count !== 1
            ) {
                throw new LogicException(
                    'P9.7i no pudo extender exactamente una vez el guard SQLite de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .self::CASH_MAIN
            );
            DB::unprepared($extended);

            return;
        }

        if (in_array(
            $driver,
            ['mysql', 'mariadb'],
            true
        )) {
            $body = $this->currentTriggerBody(
                self::CASH_MAIN
            );

            $extended = preg_replace(
                $pattern,
                $replacement,
                $body,
                1,
                $count
            );

            if (
                ! is_string($extended)
                || $count !== 1
            ) {
                throw new LogicException(
                    'P9.7i no pudo extender exactamente una vez el guard MySQL de caja.'
                );
            }

            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .self::CASH_MAIN
            );
            DB::unprepared(
                'CREATE TRIGGER '
                .self::CASH_MAIN
                .' BEFORE INSERT ON cash_movements '
                .'FOR EACH ROW '
                .$extended
            );

            return;
        }

        throw new LogicException(
            "P9.7i no implementa guard cash para {$driver}."
        );
    }

    private function extendIndividualExecutedEvidence():
        void
    {
        $body = $this->currentTriggerBody(
            self::REQUEST_UPDATE
        );

        $pattern = <<<'REGEX'
/EXISTS\s*\(\s*
SELECT\s+1\s*
FROM\s+purchase_payment_executions\s+execution\s*
JOIN\s+cash_movements\s+movement\s*
ON\s+movement\.purchase_payment_execution_id\s*=\s*execution\.id
.*?
AND\s+movement\.currency_code\s*=\s*OLD\.currency_code\s*
\)/sx
REGEX;

        if (
            preg_match(
                $pattern,
                $body,
                $match
            ) !== 1
        ) {
            throw new LogicException(
                'P9.7i no encontró la evidencia legacy exacta de ejecución individual.'
            );
        }

        $legacy = $match[0];

        $canonical = <<<'SQL'
EXISTS (
    SELECT 1
    FROM purchase_payment_disbursements disbursement
    JOIN purchase_payment_disbursement_allocations allocation
      ON allocation.purchase_payment_disbursement_id =
            disbursement.id
     AND allocation.organization_id =
            disbursement.organization_id
    WHERE disbursement.purchase_payment_request_id =
            OLD.id
      AND disbursement.purchase_payment_group_request_id
            IS NULL
      AND disbursement.organization_id =
            OLD.organization_id
      AND disbursement.origin_financial_account_id =
            OLD.origin_financial_account_id
      AND disbursement.beneficiary_business_party_id =
            OLD.beneficiary_business_party_id
      AND disbursement.amount_minor =
            OLD.amount_minor
      AND disbursement.currency_code =
            OLD.currency_code
      AND disbursement.executed_by_user_id <>
            OLD.approved_by_user_id
      AND allocation.purchase_payment_request_id =
            OLD.id
      AND allocation.purchase_payment_group_request_item_id
            IS NULL
      AND allocation.purchase_obligation_id =
            OLD.purchase_obligation_id
      AND allocation.amount_minor =
            OLD.amount_minor
      AND (
            disbursement.channel = 'noncash'
            OR (
                disbursement.channel = 'cash'
                AND EXISTS (
                    SELECT 1
                    FROM cash_movements movement
                    WHERE movement.purchase_payment_disbursement_id =
                            disbursement.id
                      AND movement.organization_id =
                            OLD.organization_id
                      AND movement.direction = 'out'
                      AND movement.type =
                            'purchase_payment_disbursement'
                      AND movement.amount_minor =
                            OLD.amount_minor
                      AND movement.currency_code =
                            OLD.currency_code
                )
            )
      )
)
SQL;

        $replacement =
            '('
            .$legacy
            .' OR '
            .$canonical
            .')';

        $extended = preg_replace(
            $pattern,
            $replacement,
            $body,
            1,
            $count
        );

        if (
            ! is_string($extended)
            || $count !== 1
        ) {
            throw new LogicException(
                'P9.7i no pudo extender evidencia individual.'
            );
        }

        $this->replaceTriggerBody(
            self::REQUEST_UPDATE,
            $extended
        );
    }

    private function extendGroupExecutedTransition():
        void
    {
        $body = $this->currentTriggerBody(
            self::GROUP_UPDATE
        );

        $steps = [
            [
                "/\\s+OR\\s+NEW\\.status\\s*=\\s*'executed'/",
                '',
            ],
            [
                "/NEW\\.status\\s+NOT\\s+IN\\s*\\(\\s*"
                ."'pending'\\s*,\\s*"
                ."'approved'\\s*,\\s*"
                ."'rejected'\\s*,\\s*"
                ."'cancelled'\\s*\\)/",
                "NEW.status NOT IN ("
                ."'pending', 'approved', "
                ."'rejected', 'cancelled', "
                ."'executed')",
            ],
            [
                "/OLD\\.status\\s*=\\s*'approved'\\s+"
                ."AND\\s+NEW\\.status\\s*<>\\s*'cancelled'/",
                "OLD.status = 'approved' "
                ."AND NEW.status NOT IN ("
                ."'cancelled', 'executed')",
            ],
            [
                "/OLD\\.status\\s+IN\\s*\\(\\s*"
                ."'rejected'\\s*,\\s*"
                ."'cancelled'\\s*\\)/",
                "OLD.status IN ("
                ."'rejected', 'cancelled', "
                ."'executed')",
            ],
        ];

        foreach ($steps as [$pattern, $replacement]) {
            $body = preg_replace(
                $pattern,
                $replacement,
                $body,
                1,
                $count
            );

            if (
                ! is_string($body)
                || $count !== 1
            ) {
                throw new LogicException(
                    'P9.7i no pudo extender exactamente una transición agrupada.'
                );
            }
        }

        $this->replaceTriggerBody(
            self::GROUP_UPDATE,
            $body
        );
    }

    private function createGuards(): void
    {
        foreach ([
            'purchase_payment_disbursement_cash_guard_insert',
            'purchase_payment_group_executed_evidence_guard_update',
            'purchase_payment_group_disbursement_balance_guard_update',
            'purchase_payment_group_item_disbursement_balance_guard_insert',
            'purchase_payment_request_disbursement_balance_guard_insert',
            'supplier_credit_application_disbursement_balance_guard_insert',
            'supplier_advance_application_disbursement_balance_guard_insert',
            'purchase_payment_disbursement_allocations_guard_delete',
            'purchase_payment_disbursement_allocations_guard_update',
            'purchase_payment_disbursement_allocations_guard_insert',
            'purchase_payment_disbursements_guard_delete',
            'purchase_payment_disbursements_guard_update',
            'purchase_payment_disbursements_guard_insert',
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
            "P9.7i no implementa guards para {$driver}."
        );
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursements_guard_insert
BEFORE INSERT ON purchase_payment_disbursements
WHEN NEW.amount_minor < 1
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR NEW.channel NOT IN ('cash', 'noncash')
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.executed_at IS NULL
    OR NEW.created_at IS NULL
    OR (
        (NEW.purchase_payment_request_id IS NULL)
        =
        (NEW.purchase_payment_group_request_id IS NULL)
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
          AND (
                (
                    NEW.channel = 'cash'
                    AND account.type = 'cash_box'
                )
                OR (
                    NEW.channel = 'noncash'
                    AND account.type NOT IN (
                        'cash_box',
                        'cash_reserve'
                    )
                    AND LENGTH(TRIM(
                        COALESCE(
                            NEW.execution_reference,
                            ''
                        )
                    )) > 0
                )
          )
    )
    OR (
        NEW.channel = 'cash'
        AND (
            NEW.cash_register_session_id IS NULL
            OR NEW.cash_register_id IS NULL
            OR NOT EXISTS (
                SELECT 1
                FROM cash_register_sessions session
                JOIN cash_registers register_row
                  ON register_row.id =
                        session.cash_register_id
                WHERE session.id =
                        NEW.cash_register_session_id
                  AND session.organization_id =
                        NEW.organization_id
                  AND session.status = 'open'
                  AND session.opened_by_user_id =
                        NEW.executed_by_user_id
                  AND session.currency_code =
                        NEW.currency_code
                  AND session.cash_register_id =
                        NEW.cash_register_id
                  AND register_row.organization_id =
                        NEW.organization_id
                  AND register_row.active = 1
                  AND register_row.financial_account_id =
                        NEW.origin_financial_account_id
            )
        )
    )
    OR (
        NEW.channel = 'noncash'
        AND (
            NEW.cash_register_session_id IS NOT NULL
            OR NEW.cash_register_id IS NOT NULL
        )
    )
    OR (
        NEW.purchase_payment_request_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM purchase_payment_requests request
            WHERE request.id =
                    NEW.purchase_payment_request_id
              AND request.organization_id =
                    NEW.organization_id
              AND request.origin_financial_account_id =
                    NEW.origin_financial_account_id
              AND request.beneficiary_business_party_id =
                    NEW.beneficiary_business_party_id
              AND request.amount_minor =
                    NEW.amount_minor
              AND request.currency_code =
                    NEW.currency_code
              AND request.status = 'approved'
              AND request.approved_by_user_id IS NOT NULL
              AND request.approved_by_user_id <>
                    NEW.executed_by_user_id
        )
    )
    OR (
        NEW.purchase_payment_group_request_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM purchase_payment_group_requests group_request
            WHERE group_request.id =
                    NEW.purchase_payment_group_request_id
              AND group_request.organization_id =
                    NEW.organization_id
              AND group_request.origin_financial_account_id =
                    NEW.origin_financial_account_id
              AND group_request.beneficiary_business_party_id =
                    NEW.beneficiary_business_party_id
              AND group_request.currency_code =
                    NEW.currency_code
              AND group_request.status = 'approved'
              AND group_request.approved_by_user_id IS NOT NULL
              AND group_request.approved_by_user_id <>
                    NEW.executed_by_user_id
              AND NEW.amount_minor = (
                    SELECT COALESCE(
                        SUM(item.amount_minor),
                        0
                    )
                    FROM purchase_payment_group_request_items item
                    WHERE item.purchase_payment_group_request_id =
                            group_request.id
                      AND item.organization_id =
                            group_request.organization_id
              )
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_disbursement_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursements_guard_update
BEFORE UPDATE ON purchase_payment_disbursements
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_disbursement_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursements_guard_delete
BEFORE DELETE ON purchase_payment_disbursements
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_disbursement_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_allocations_guard_insert
BEFORE INSERT ON purchase_payment_disbursement_allocations
WHEN NEW.amount_minor < 1
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.created_at IS NULL
    OR (
        (NEW.purchase_payment_request_id IS NULL)
        =
        (NEW.purchase_payment_group_request_item_id IS NULL)
    )
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_payment_disbursements disbursement
        JOIN purchase_obligations obligation
          ON obligation.id =
                NEW.purchase_obligation_id
         AND obligation.organization_id =
                NEW.organization_id
        WHERE disbursement.id =
                NEW.purchase_payment_disbursement_id
          AND disbursement.organization_id =
                NEW.organization_id
          AND (
                (
                    NEW.purchase_payment_request_id IS NOT NULL
                    AND disbursement.purchase_payment_request_id =
                        NEW.purchase_payment_request_id
                    AND disbursement.purchase_payment_group_request_id
                        IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM purchase_payment_requests request
                        WHERE request.id =
                                NEW.purchase_payment_request_id
                          AND request.organization_id =
                                NEW.organization_id
                          AND request.purchase_obligation_id =
                                NEW.purchase_obligation_id
                          AND request.amount_minor =
                                NEW.amount_minor
                    )
                )
                OR (
                    NEW.purchase_payment_group_request_item_id
                        IS NOT NULL
                    AND disbursement.purchase_payment_request_id
                        IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM purchase_payment_group_request_items item
                        WHERE item.id =
                                NEW.purchase_payment_group_request_item_id
                          AND item.organization_id =
                                NEW.organization_id
                          AND item.purchase_payment_group_request_id =
                                disbursement.purchase_payment_group_request_id
                          AND item.purchase_obligation_id =
                                NEW.purchase_obligation_id
                          AND item.amount_minor =
                                NEW.amount_minor
                    )
                )
          )
          AND NEW.amount_minor <= (
                obligation.amount_minor
                - COALESCE(
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
                - COALESCE(
                    (
                        SELECT SUM(allocation.amount_minor)
                        FROM purchase_payment_disbursement_allocations allocation
                        WHERE allocation.organization_id =
                                NEW.organization_id
                          AND allocation.purchase_obligation_id =
                                NEW.purchase_obligation_id
                    ),
                    0
                )
                - COALESCE(
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
                - COALESCE(
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
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_disbursement_allocation_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_allocations_guard_update
BEFORE UPDATE ON purchase_payment_disbursement_allocations
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_disbursement_allocation_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_allocations_guard_delete
BEFORE DELETE ON purchase_payment_disbursement_allocations
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_disbursement_allocation_immutable'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_cash_guard_insert
BEFORE INSERT ON cash_movements
WHEN (
    NEW.type = 'purchase_payment_disbursement'
    AND (
        NEW.direction <> 'out'
        OR NEW.purchase_payment_disbursement_id
            IS NULL
        OR NEW.purchase_payment_execution_id
            IS NOT NULL
        OR NEW.commerce_payment_id
            IS NOT NULL
        OR NEW.customer_collection_id
            IS NOT NULL
        OR NEW.customer_advance_id
            IS NOT NULL
        OR NEW.supplier_advance_id
            IS NOT NULL
        OR NEW.destination_financial_account_id
            IS NOT NULL
        OR NEW.cash_security_drop_request_id
            IS NOT NULL
        OR NEW.post_sale_cash_refund_execution_id
            IS NOT NULL
        OR NEW.post_sale_exchange_payment_id
            IS NOT NULL
        OR NEW.reason_code IS NOT NULL
        OR NEW.note IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_payment_disbursements disbursement
            WHERE disbursement.id =
                    NEW.purchase_payment_disbursement_id
              AND disbursement.organization_id =
                    NEW.organization_id
              AND disbursement.channel = 'cash'
              AND disbursement.cash_register_session_id =
                    NEW.cash_register_session_id
              AND disbursement.cash_register_id =
                    NEW.cash_register_id
              AND disbursement.origin_financial_account_id =
                    NEW.financial_account_id
              AND disbursement.amount_minor =
                    NEW.amount_minor
              AND disbursement.currency_code =
                    NEW.currency_code
              AND disbursement.executed_by_user_id =
                    NEW.recorded_by_user_id
        )
    )
)
OR (
    NEW.type <> 'purchase_payment_disbursement'
    AND NEW.purchase_payment_disbursement_id
        IS NOT NULL
)
BEGIN
    SELECT RAISE(
        ABORT,
        'cash_purchase_payment_disbursement_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_executed_evidence_guard_update
BEFORE UPDATE ON purchase_payment_group_requests
WHEN NEW.status = 'executed'
AND NOT (
    OLD.status = 'approved'
    AND NEW.approved_by_user_id =
        OLD.approved_by_user_id
    AND NEW.approval_idempotency_key =
        OLD.approval_idempotency_key
    AND NEW.approval_fingerprint =
        OLD.approval_fingerprint
    AND COALESCE(NEW.approval_note, '') =
        COALESCE(OLD.approval_note, '')
    AND NEW.approved_at =
        OLD.approved_at
    AND NEW.resolved_by_user_id IS NULL
    AND NEW.resolution_idempotency_key IS NULL
    AND NEW.resolution_note IS NULL
    AND NEW.resolved_at IS NULL
    AND EXISTS (
        SELECT 1
        FROM purchase_payment_disbursements disbursement
        WHERE disbursement.purchase_payment_group_request_id =
                OLD.id
          AND disbursement.purchase_payment_request_id
                IS NULL
          AND disbursement.organization_id =
                OLD.organization_id
          AND disbursement.origin_financial_account_id =
                OLD.origin_financial_account_id
          AND disbursement.beneficiary_business_party_id =
                OLD.beneficiary_business_party_id
          AND disbursement.currency_code =
                OLD.currency_code
          AND disbursement.executed_by_user_id <>
                OLD.approved_by_user_id
          AND disbursement.amount_minor = (
                SELECT COALESCE(
                    SUM(item.amount_minor),
                    0
                )
                FROM purchase_payment_group_request_items item
                WHERE item.purchase_payment_group_request_id =
                        OLD.id
                  AND item.organization_id =
                        OLD.organization_id
          )
          AND NOT EXISTS (
                SELECT 1
                FROM purchase_payment_group_request_items item
                WHERE item.purchase_payment_group_request_id =
                        OLD.id
                  AND item.organization_id =
                        OLD.organization_id
                  AND NOT EXISTS (
                        SELECT 1
                        FROM purchase_payment_disbursement_allocations allocation
                        WHERE allocation.purchase_payment_disbursement_id =
                                disbursement.id
                          AND allocation.organization_id =
                                OLD.organization_id
                          AND allocation.purchase_payment_group_request_item_id =
                                item.id
                          AND allocation.purchase_payment_request_id
                                IS NULL
                          AND allocation.purchase_obligation_id =
                                item.purchase_obligation_id
                          AND allocation.amount_minor =
                                item.amount_minor
                  )
          )
          AND (
                disbursement.channel = 'noncash'
                OR (
                    disbursement.channel = 'cash'
                    AND EXISTS (
                        SELECT 1
                        FROM cash_movements movement
                        WHERE movement.purchase_payment_disbursement_id =
                                disbursement.id
                          AND movement.organization_id =
                                OLD.organization_id
                          AND movement.direction = 'out'
                          AND movement.type =
                                'purchase_payment_disbursement'
                          AND movement.amount_minor =
                                disbursement.amount_minor
                          AND movement.currency_code =
                                disbursement.currency_code
                    )
                )
          )
    )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_execution_evidence_invalid'
    );
END
SQL);

        $this->createSqliteBalanceGuards();
    }

    private function createSqliteBalanceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_request_disbursement_balance_guard_insert
BEFORE INSERT ON purchase_payment_requests
WHEN NOT EXISTS (
    SELECT 1
    FROM purchase_obligations obligation
    WHERE obligation.id =
            NEW.purchase_obligation_id
      AND obligation.organization_id =
            NEW.organization_id
      AND NEW.amount_minor <= (
            obligation.amount_minor
            - COALESCE((
                SELECT SUM(execution.amount_minor)
                FROM purchase_payment_executions execution
                WHERE execution.organization_id =
                        NEW.organization_id
                  AND execution.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM purchase_payment_disbursement_allocations allocation
                WHERE allocation.organization_id =
                        NEW.organization_id
                  AND allocation.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_credit_applications application
                WHERE application.organization_id =
                        NEW.organization_id
                  AND application.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_advance_applications application
                WHERE application.organization_id =
                        NEW.organization_id
                  AND application.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
      )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_request_disbursement_balance_invalid'
    );
END
SQL);

        foreach ([
            'supplier_credit_applications'
                => 'supplier_credit_application_disbursement_balance_guard_insert',
            'supplier_advance_applications'
                => 'supplier_advance_application_disbursement_balance_guard_insert',
        ] as $table => $trigger) {
            DB::unprepared(
                "CREATE TRIGGER {$trigger}
BEFORE INSERT ON {$table}
WHEN NOT EXISTS (
    SELECT 1
    FROM purchase_obligations obligation
    WHERE obligation.id =
            NEW.purchase_obligation_id
      AND obligation.organization_id =
            NEW.organization_id
      AND NEW.amount_minor <= (
            obligation.amount_minor
            - COALESCE((
                SELECT SUM(execution.amount_minor)
                FROM purchase_payment_executions execution
                WHERE execution.organization_id =
                        NEW.organization_id
                  AND execution.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM purchase_payment_disbursement_allocations allocation
                WHERE allocation.organization_id =
                        NEW.organization_id
                  AND allocation.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_credit_applications application
                WHERE application.organization_id =
                        NEW.organization_id
                  AND application.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_advance_applications application
                WHERE application.organization_id =
                        NEW.organization_id
                  AND application.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
      )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'supplier_application_disbursement_balance_invalid'
    );
END"
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_disbursement_balance_guard_insert
BEFORE INSERT ON purchase_payment_group_request_items
WHEN NOT EXISTS (
    SELECT 1
    FROM purchase_obligations obligation
    WHERE obligation.id =
            NEW.purchase_obligation_id
      AND obligation.organization_id =
            NEW.organization_id
      AND NEW.amount_minor <= (
            obligation.amount_minor
            - COALESCE((
                SELECT SUM(execution.amount_minor)
                FROM purchase_payment_executions execution
                WHERE execution.organization_id =
                        NEW.organization_id
                  AND execution.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM purchase_payment_disbursement_allocations allocation
                WHERE allocation.organization_id =
                        NEW.organization_id
                  AND allocation.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_credit_applications application
                WHERE application.organization_id =
                        NEW.organization_id
                  AND application.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_advance_applications application
                WHERE application.organization_id =
                        NEW.organization_id
                  AND application.purchase_obligation_id =
                        NEW.purchase_obligation_id
            ), 0)
      )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_item_disbursement_balance_invalid'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_disbursement_balance_guard_update
BEFORE UPDATE ON purchase_payment_group_requests
WHEN NEW.status = 'approved'
AND EXISTS (
    SELECT 1
    FROM purchase_payment_group_request_items item
    JOIN purchase_obligations obligation
      ON obligation.id =
            item.purchase_obligation_id
     AND obligation.organization_id =
            item.organization_id
    WHERE item.purchase_payment_group_request_id =
            NEW.id
      AND item.organization_id =
            NEW.organization_id
      AND item.amount_minor > (
            obligation.amount_minor
            - COALESCE((
                SELECT SUM(execution.amount_minor)
                FROM purchase_payment_executions execution
                WHERE execution.organization_id =
                        item.organization_id
                  AND execution.purchase_obligation_id =
                        item.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(allocation.amount_minor)
                FROM purchase_payment_disbursement_allocations allocation
                WHERE allocation.organization_id =
                        item.organization_id
                  AND allocation.purchase_obligation_id =
                        item.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_credit_applications application
                WHERE application.organization_id =
                        item.organization_id
                  AND application.purchase_obligation_id =
                        item.purchase_obligation_id
            ), 0)
            - COALESCE((
                SELECT SUM(application.amount_minor)
                FROM supplier_advance_applications application
                WHERE application.organization_id =
                        item.organization_id
                  AND application.purchase_obligation_id =
                        item.purchase_obligation_id
            ), 0)
      )
)
BEGIN
    SELECT RAISE(
        ABORT,
        'purchase_payment_group_disbursement_balance_invalid'
    );
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursements_guard_insert
BEFORE INSERT ON purchase_payment_disbursements
FOR EACH ROW
BEGIN
    DECLARE member_count BIGINT DEFAULT 0;
    DECLARE party_count BIGINT DEFAULT 0;
    DECLARE account_count BIGINT DEFAULT 0;
    DECLARE authorization_count BIGINT DEFAULT 0;
    DECLARE session_count BIGINT DEFAULT 0;

    SELECT COUNT(*)
      INTO member_count
      FROM organization_memberships membership
     WHERE membership.organization_id =
            NEW.organization_id
       AND membership.user_id =
            NEW.executed_by_user_id
       AND membership.active = 1
       AND membership.role IN (
            'admin',
            'operator'
       );

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
            BINARY NEW.currency_code
       AND (
            (
                NEW.channel = 'cash'
                AND account.type = 'cash_box'
            )
            OR (
                NEW.channel = 'noncash'
                AND account.type NOT IN (
                    'cash_box',
                    'cash_reserve'
                )
                AND CHAR_LENGTH(TRIM(
                    COALESCE(
                        NEW.execution_reference,
                        ''
                    )
                )) > 0
            )
       );

    IF NEW.purchase_payment_request_id IS NOT NULL THEN
        SELECT COUNT(*)
          INTO authorization_count
          FROM purchase_payment_requests request
         WHERE request.id =
                NEW.purchase_payment_request_id
           AND request.organization_id =
                NEW.organization_id
           AND request.origin_financial_account_id =
                NEW.origin_financial_account_id
           AND request.beneficiary_business_party_id =
                NEW.beneficiary_business_party_id
           AND request.amount_minor =
                NEW.amount_minor
           AND BINARY request.currency_code =
                BINARY NEW.currency_code
           AND request.status = 'approved'
           AND request.approved_by_user_id IS NOT NULL
           AND request.approved_by_user_id <>
                NEW.executed_by_user_id;
    ELSE
        SELECT COUNT(*)
          INTO authorization_count
          FROM purchase_payment_group_requests group_request
         WHERE group_request.id =
                NEW.purchase_payment_group_request_id
           AND group_request.organization_id =
                NEW.organization_id
           AND group_request.origin_financial_account_id =
                NEW.origin_financial_account_id
           AND group_request.beneficiary_business_party_id =
                NEW.beneficiary_business_party_id
           AND BINARY group_request.currency_code =
                BINARY NEW.currency_code
           AND group_request.status = 'approved'
           AND group_request.approved_by_user_id IS NOT NULL
           AND group_request.approved_by_user_id <>
                NEW.executed_by_user_id
           AND NEW.amount_minor = (
                SELECT COALESCE(
                    SUM(item.amount_minor),
                    0
                )
                FROM purchase_payment_group_request_items item
                WHERE item.purchase_payment_group_request_id =
                        group_request.id
                  AND item.organization_id =
                        group_request.organization_id
           );
    END IF;

    IF NEW.channel = 'cash' THEN
        SELECT COUNT(*)
          INTO session_count
          FROM cash_register_sessions session
          JOIN cash_registers register_row
            ON register_row.id =
                session.cash_register_id
         WHERE session.id =
                NEW.cash_register_session_id
           AND session.organization_id =
                NEW.organization_id
           AND session.status = 'open'
           AND session.opened_by_user_id =
                NEW.executed_by_user_id
           AND BINARY session.currency_code =
                BINARY NEW.currency_code
           AND session.cash_register_id =
                NEW.cash_register_id
           AND register_row.organization_id =
                NEW.organization_id
           AND register_row.active = 1
           AND register_row.financial_account_id =
                NEW.origin_financial_account_id;
    END IF;

    IF NEW.amount_minor < 1
       OR CHAR_LENGTH(
            NEW.currency_code
       ) <> 3
       OR BINARY NEW.currency_code
            <> BINARY UPPER(
                NEW.currency_code
            )
       OR NEW.channel NOT IN (
            'cash',
            'noncash'
       )
       OR CHAR_LENGTH(TRIM(
            NEW.idempotency_key
       )) = 0
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.executed_at IS NULL
       OR NEW.created_at IS NULL
       OR (
            (NEW.purchase_payment_request_id IS NULL)
            =
            (NEW.purchase_payment_group_request_id IS NULL)
       )
       OR member_count <> 1
       OR party_count <> 1
       OR account_count <> 1
       OR authorization_count <> 1
       OR (
            NEW.channel = 'cash'
            AND (
                NEW.cash_register_session_id IS NULL
                OR NEW.cash_register_id IS NULL
                OR session_count <> 1
            )
       )
       OR (
            NEW.channel = 'noncash'
            AND (
                NEW.cash_register_session_id IS NOT NULL
                OR NEW.cash_register_id IS NOT NULL
            )
       )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_disbursement_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursements_guard_update
BEFORE UPDATE ON purchase_payment_disbursements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_disbursement_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursements_guard_delete
BEFORE DELETE ON purchase_payment_disbursements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_disbursement_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_allocations_guard_insert
BEFORE INSERT ON purchase_payment_disbursement_allocations
FOR EACH ROW
BEGIN
    DECLARE relation_count BIGINT DEFAULT 0;
    DECLARE remaining_minor BIGINT DEFAULT NULL;

    SELECT COUNT(*)
      INTO relation_count
      FROM purchase_payment_disbursements disbursement
      JOIN purchase_obligations obligation
        ON obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
     WHERE disbursement.id =
            NEW.purchase_payment_disbursement_id
       AND disbursement.organization_id =
            NEW.organization_id
       AND (
            (
                NEW.purchase_payment_request_id IS NOT NULL
                AND disbursement.purchase_payment_request_id =
                    NEW.purchase_payment_request_id
                AND disbursement.purchase_payment_group_request_id
                    IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM purchase_payment_requests request
                    WHERE request.id =
                            NEW.purchase_payment_request_id
                      AND request.organization_id =
                            NEW.organization_id
                      AND request.purchase_obligation_id =
                            NEW.purchase_obligation_id
                      AND request.amount_minor =
                            NEW.amount_minor
                )
            )
            OR (
                NEW.purchase_payment_group_request_item_id
                    IS NOT NULL
                AND disbursement.purchase_payment_request_id
                    IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM purchase_payment_group_request_items item
                    WHERE item.id =
                            NEW.purchase_payment_group_request_item_id
                      AND item.organization_id =
                            NEW.organization_id
                      AND item.purchase_payment_group_request_id =
                            disbursement.purchase_payment_group_request_id
                      AND item.purchase_obligation_id =
                            NEW.purchase_obligation_id
                      AND item.amount_minor =
                            NEW.amount_minor
                )
            )
       );

    SELECT obligation.amount_minor
        - COALESCE((
            SELECT SUM(execution.amount_minor)
            FROM purchase_payment_executions execution
            WHERE execution.organization_id =
                    NEW.organization_id
              AND execution.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM purchase_payment_disbursement_allocations allocation
            WHERE allocation.organization_id =
                    NEW.organization_id
              AND allocation.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_credit_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_advance_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
      INTO remaining_minor
      FROM purchase_obligations obligation
     WHERE obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
     LIMIT 1;

    IF NEW.amount_minor < 1
       OR CHAR_LENGTH(
            NEW.fingerprint
       ) <> 64
       OR NEW.created_at IS NULL
       OR (
            (NEW.purchase_payment_request_id IS NULL)
            =
            (NEW.purchase_payment_group_request_item_id IS NULL)
       )
       OR relation_count <> 1
       OR remaining_minor IS NULL
       OR NEW.amount_minor > remaining_minor
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_disbursement_allocation_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_allocations_guard_update
BEFORE UPDATE ON purchase_payment_disbursement_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_disbursement_allocation_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_allocations_guard_delete
BEFORE DELETE ON purchase_payment_disbursement_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'purchase_payment_disbursement_allocation_immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_disbursement_cash_guard_insert
BEFORE INSERT ON cash_movements
FOR EACH ROW
BEGIN
    DECLARE relation_count BIGINT DEFAULT 0;

    IF NEW.type =
        'purchase_payment_disbursement'
    THEN
        SELECT COUNT(*)
          INTO relation_count
          FROM purchase_payment_disbursements disbursement
         WHERE disbursement.id =
                NEW.purchase_payment_disbursement_id
           AND disbursement.organization_id =
                NEW.organization_id
           AND disbursement.channel = 'cash'
           AND disbursement.cash_register_session_id =
                NEW.cash_register_session_id
           AND disbursement.cash_register_id =
                NEW.cash_register_id
           AND disbursement.origin_financial_account_id =
                NEW.financial_account_id
           AND disbursement.amount_minor =
                NEW.amount_minor
           AND BINARY disbursement.currency_code =
                BINARY NEW.currency_code
           AND disbursement.executed_by_user_id =
                NEW.recorded_by_user_id;

        IF NEW.direction <> 'out'
           OR NEW.purchase_payment_disbursement_id
                IS NULL
           OR NEW.purchase_payment_execution_id
                IS NOT NULL
           OR NEW.commerce_payment_id
                IS NOT NULL
           OR NEW.customer_collection_id
                IS NOT NULL
           OR NEW.customer_advance_id
                IS NOT NULL
           OR NEW.supplier_advance_id
                IS NOT NULL
           OR NEW.destination_financial_account_id
                IS NOT NULL
           OR NEW.cash_security_drop_request_id
                IS NOT NULL
           OR NEW.post_sale_cash_refund_execution_id
                IS NOT NULL
           OR NEW.post_sale_exchange_payment_id
                IS NOT NULL
           OR NEW.reason_code IS NOT NULL
           OR NEW.note IS NOT NULL
           OR relation_count <> 1
        THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'cash_purchase_payment_disbursement_invalid';
        END IF;
    ELSEIF NEW.purchase_payment_disbursement_id
        IS NOT NULL
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'cash_purchase_payment_disbursement_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_executed_evidence_guard_update
BEFORE UPDATE ON purchase_payment_group_requests
FOR EACH ROW
BEGIN
    DECLARE evidence_count BIGINT DEFAULT 0;
    DECLARE item_count BIGINT DEFAULT 0;
    DECLARE allocation_count BIGINT DEFAULT 0;

    IF NEW.status = 'executed' THEN
        SELECT COUNT(*)
          INTO item_count
          FROM purchase_payment_group_request_items item
         WHERE item.purchase_payment_group_request_id =
                OLD.id
           AND item.organization_id =
                OLD.organization_id;

        SELECT COUNT(*)
          INTO evidence_count
          FROM purchase_payment_disbursements disbursement
         WHERE disbursement.purchase_payment_group_request_id =
                OLD.id
           AND disbursement.purchase_payment_request_id
                IS NULL
           AND disbursement.organization_id =
                OLD.organization_id
           AND disbursement.origin_financial_account_id =
                OLD.origin_financial_account_id
           AND disbursement.beneficiary_business_party_id =
                OLD.beneficiary_business_party_id
           AND BINARY disbursement.currency_code =
                BINARY OLD.currency_code
           AND disbursement.executed_by_user_id <>
                OLD.approved_by_user_id
           AND disbursement.amount_minor = (
                SELECT COALESCE(
                    SUM(item.amount_minor),
                    0
                )
                FROM purchase_payment_group_request_items item
                WHERE item.purchase_payment_group_request_id =
                        OLD.id
                  AND item.organization_id =
                        OLD.organization_id
           )
           AND (
                disbursement.channel = 'noncash'
                OR EXISTS (
                    SELECT 1
                    FROM cash_movements movement
                    WHERE movement.purchase_payment_disbursement_id =
                            disbursement.id
                      AND movement.organization_id =
                            OLD.organization_id
                      AND movement.direction = 'out'
                      AND movement.type =
                            'purchase_payment_disbursement'
                      AND movement.amount_minor =
                            disbursement.amount_minor
                      AND BINARY movement.currency_code =
                            BINARY disbursement.currency_code
                )
           );

        SELECT COUNT(*)
          INTO allocation_count
          FROM purchase_payment_disbursement_allocations allocation
          JOIN purchase_payment_disbursements disbursement
            ON disbursement.id =
                allocation.purchase_payment_disbursement_id
           AND disbursement.purchase_payment_group_request_id =
                OLD.id
         WHERE allocation.organization_id =
                OLD.organization_id
           AND allocation.purchase_payment_request_id
                IS NULL
           AND EXISTS (
                SELECT 1
                FROM purchase_payment_group_request_items item
                WHERE item.id =
                        allocation.purchase_payment_group_request_item_id
                  AND item.purchase_payment_group_request_id =
                        OLD.id
                  AND item.organization_id =
                        OLD.organization_id
                  AND item.purchase_obligation_id =
                        allocation.purchase_obligation_id
                  AND item.amount_minor =
                        allocation.amount_minor
           );

        IF OLD.status <> 'approved'
           OR NEW.approved_by_user_id <>
                OLD.approved_by_user_id
           OR NOT (
                NEW.approval_idempotency_key
                <=> OLD.approval_idempotency_key
           )
           OR NOT (
                NEW.approval_fingerprint
                <=> OLD.approval_fingerprint
           )
           OR NOT (
                NEW.approval_note
                <=> OLD.approval_note
           )
           OR NEW.approved_at <> OLD.approved_at
           OR NEW.resolved_by_user_id IS NOT NULL
           OR NEW.resolution_idempotency_key IS NOT NULL
           OR NEW.resolution_note IS NOT NULL
           OR NEW.resolved_at IS NOT NULL
           OR evidence_count <> 1
           OR item_count < 2
           OR allocation_count <> item_count
        THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'purchase_payment_group_execution_evidence_invalid';
        END IF;
    END IF;
END
SQL);

        $this->createMysqlBalanceGuards();
    }

    private function createMysqlBalanceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_request_disbursement_balance_guard_insert
BEFORE INSERT ON purchase_payment_requests
FOR EACH ROW
BEGIN
    DECLARE remaining_minor BIGINT DEFAULT NULL;

    SELECT obligation.amount_minor
        - COALESCE((
            SELECT SUM(execution.amount_minor)
            FROM purchase_payment_executions execution
            WHERE execution.organization_id =
                    NEW.organization_id
              AND execution.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM purchase_payment_disbursement_allocations allocation
            WHERE allocation.organization_id =
                    NEW.organization_id
              AND allocation.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_credit_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_advance_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
      INTO remaining_minor
      FROM purchase_obligations obligation
     WHERE obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
     LIMIT 1;

    IF remaining_minor IS NULL
       OR NEW.amount_minor > remaining_minor
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_request_disbursement_balance_invalid';
    END IF;
END
SQL);

        foreach ([
            'supplier_credit_applications'
                => 'supplier_credit_application_disbursement_balance_guard_insert',
            'supplier_advance_applications'
                => 'supplier_advance_application_disbursement_balance_guard_insert',
        ] as $table => $trigger) {
            DB::unprepared(
                "CREATE TRIGGER {$trigger}
BEFORE INSERT ON {$table}
FOR EACH ROW
BEGIN
    DECLARE remaining_minor BIGINT DEFAULT NULL;

    SELECT obligation.amount_minor
        - COALESCE((
            SELECT SUM(execution.amount_minor)
            FROM purchase_payment_executions execution
            WHERE execution.organization_id =
                    NEW.organization_id
              AND execution.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM purchase_payment_disbursement_allocations allocation
            WHERE allocation.organization_id =
                    NEW.organization_id
              AND allocation.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_credit_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_advance_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
      INTO remaining_minor
      FROM purchase_obligations obligation
     WHERE obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
     LIMIT 1;

    IF remaining_minor IS NULL
       OR NEW.amount_minor > remaining_minor
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'supplier_application_disbursement_balance_invalid';
    END IF;
END"
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_item_disbursement_balance_guard_insert
BEFORE INSERT ON purchase_payment_group_request_items
FOR EACH ROW
BEGIN
    DECLARE remaining_minor BIGINT DEFAULT NULL;

    SELECT obligation.amount_minor
        - COALESCE((
            SELECT SUM(execution.amount_minor)
            FROM purchase_payment_executions execution
            WHERE execution.organization_id =
                    NEW.organization_id
              AND execution.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(allocation.amount_minor)
            FROM purchase_payment_disbursement_allocations allocation
            WHERE allocation.organization_id =
                    NEW.organization_id
              AND allocation.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_credit_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
        - COALESCE((
            SELECT SUM(application.amount_minor)
            FROM supplier_advance_applications application
            WHERE application.organization_id =
                    NEW.organization_id
              AND application.purchase_obligation_id =
                    NEW.purchase_obligation_id
        ), 0)
      INTO remaining_minor
      FROM purchase_obligations obligation
     WHERE obligation.id =
            NEW.purchase_obligation_id
       AND obligation.organization_id =
            NEW.organization_id
     LIMIT 1;

    IF remaining_minor IS NULL
       OR NEW.amount_minor > remaining_minor
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'purchase_payment_group_item_disbursement_balance_invalid';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_payment_group_disbursement_balance_guard_update
BEFORE UPDATE ON purchase_payment_group_requests
FOR EACH ROW
BEGIN
    DECLARE conflict_count BIGINT DEFAULT 0;

    IF NEW.status = 'approved' THEN
        SELECT COUNT(*)
          INTO conflict_count
          FROM purchase_payment_group_request_items item
          JOIN purchase_obligations obligation
            ON obligation.id =
                item.purchase_obligation_id
           AND obligation.organization_id =
                item.organization_id
         WHERE item.purchase_payment_group_request_id =
                NEW.id
           AND item.organization_id =
                NEW.organization_id
           AND item.amount_minor > (
                obligation.amount_minor
                - COALESCE((
                    SELECT SUM(execution.amount_minor)
                    FROM purchase_payment_executions execution
                    WHERE execution.organization_id =
                            item.organization_id
                      AND execution.purchase_obligation_id =
                            item.purchase_obligation_id
                ), 0)
                - COALESCE((
                    SELECT SUM(allocation.amount_minor)
                    FROM purchase_payment_disbursement_allocations allocation
                    WHERE allocation.organization_id =
                            item.organization_id
                      AND allocation.purchase_obligation_id =
                            item.purchase_obligation_id
                ), 0)
                - COALESCE((
                    SELECT SUM(application.amount_minor)
                    FROM supplier_credit_applications application
                    WHERE application.organization_id =
                            item.organization_id
                      AND application.purchase_obligation_id =
                            item.purchase_obligation_id
                ), 0)
                - COALESCE((
                    SELECT SUM(application.amount_minor)
                    FROM supplier_advance_applications application
                    WHERE application.organization_id =
                            item.organization_id
                      AND application.purchase_obligation_id =
                            item.purchase_obligation_id
                ), 0)
           );

        IF conflict_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'purchase_payment_group_disbursement_balance_invalid';
        END IF;
    END IF;
END
SQL);
    }
};
