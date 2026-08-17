<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECEIVABLE_TRIGGERS = [
        'customer_receivables_guard_insert',
        'customer_receivables_guard_update',
        'customer_receivables_guard_delete',
    ];

    public function up(): void
    {
        Schema::create(
            'customer_receivables',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('business_party_id')
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('commerce_sale_id');
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('amount_minor');
                $table->date('due_on')->nullable();
                $table->string('idempotency_key', 90);
                $table->char('fingerprint', 64);
                $table->foreignId('recognized_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('recognized_at');
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'id'],
                    'customer_receivables_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'commerce_sale_id'],
                    'customer_receivables_org_sale_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'customer_receivables_org_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'due_on',
                    ],
                    'customer_receivables_party_due_index'
                );
                $table->foreign(
                    ['organization_id', 'commerce_sale_id'],
                    'customer_receivables_sale_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('commerce_sales')
                    ->restrictOnDelete();
            }
        );

        $this->createReceivableTriggers();
        $this->rewriteCommerceSaleSettlementGuard(true);
    }

    public function down(): void
    {
        $this->rewriteCommerceSaleSettlementGuard(false);
        $this->dropReceivableTriggers();
        Schema::dropIfExists('customer_receivables');
    }

    private function createReceivableTriggers(): void
    {
        $this->dropReceivableTriggers();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteReceivableTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlReceivableTriggers();

            return;
        }

        throw new LogicException(
            "La integridad de cuentas por cobrar no está implementada para {$driver}."
        );
    }

    private function createSqliteReceivableTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_guard_insert
BEFORE INSERT ON customer_receivables
WHEN NEW.amount_minor < 1
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        INNER JOIN business_parties party
            ON party.id = NEW.business_party_id
            AND party.organization_id = NEW.organization_id
        INNER JOIN customers customer
            ON customer.business_party_id = party.id
            AND customer.organization_id = NEW.organization_id
            AND customer.active = 1
        WHERE sale.id = NEW.commerce_sale_id
            AND sale.organization_id = NEW.organization_id
            AND sale.status = 'building'
            AND sale.customer_business_party_id = NEW.business_party_id
            AND sale.currency_code = NEW.currency_code
            AND NEW.amount_minor <= sale.total_minor
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La cuenta por cobrar no coincide con la venta y el cliente.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_guard_update
BEFORE UPDATE ON customer_receivables
BEGIN
    SELECT RAISE(
        ABORT,
        'Una cuenta por cobrar reconocida es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_guard_delete
BEFORE DELETE ON customer_receivables
BEGIN
    SELECT RAISE(
        ABORT,
        'Una cuenta por cobrar reconocida no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlReceivableTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_guard_insert
BEFORE INSERT ON customer_receivables
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR BINARY NEW.currency_code <> BINARY UPPER(NEW.currency_code)
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            INNER JOIN business_parties party
                ON party.id = NEW.business_party_id
                AND party.organization_id = NEW.organization_id
            INNER JOIN customers customer
                ON customer.business_party_id = party.id
                AND customer.organization_id = NEW.organization_id
                AND customer.active = 1
            WHERE sale.id = NEW.commerce_sale_id
                AND sale.organization_id = NEW.organization_id
                AND sale.status = 'building'
                AND sale.customer_business_party_id = NEW.business_party_id
                AND sale.currency_code = NEW.currency_code
                AND NEW.amount_minor <= sale.total_minor
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La cuenta por cobrar no coincide con la venta y el cliente.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_guard_update
BEFORE UPDATE ON customer_receivables
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una cuenta por cobrar reconocida es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_receivables_guard_delete
BEFORE DELETE ON customer_receivables
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una cuenta por cobrar reconocida no puede eliminarse.';
END
SQL);
    }

    private function dropReceivableTriggers(): void
    {
        foreach (self::RECEIVABLE_TRIGGERS as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function rewriteCommerceSaleSettlementGuard(
        bool $includeReceivable
    ): void {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $row = DB::selectOne(
                <<<'SQL'
SELECT sql
FROM sqlite_master
WHERE type = 'trigger'
  AND name = 'commerce_sales_guard_update'
SQL
            );

            $definition = is_object($row)
                ? (string) ($row->sql ?? '')
                : '';

            if ($definition === '') {
                throw new LogicException(
                    'No se encontró commerce_sales_guard_update para extender P9.1.'
                );
            }

            $rewritten = $this->rewriteSettlementSql(
                $definition,
                $includeReceivable
            );

            DB::statement(
                'DROP TRIGGER IF EXISTS commerce_sales_guard_update'
            );
            DB::unprepared($rewritten);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne(
                <<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'commerce_sales_guard_update'
SQL
            );

            $definition = is_object($row)
                ? (string) ($row->action_statement ?? '')
                : '';

            if ($definition === '') {
                throw new LogicException(
                    'No se encontró commerce_sales_guard_update para extender P9.1.'
                );
            }

            $rewritten = $this->rewriteSettlementSql(
                $definition,
                $includeReceivable
            );

            DB::statement(
                'DROP TRIGGER IF EXISTS commerce_sales_guard_update'
            );
            DB::unprepared(
                'CREATE TRIGGER commerce_sales_guard_update '
                .'BEFORE UPDATE ON commerce_sales '
                .'FOR EACH ROW '
                .$rewritten
            );

            return;
        }

        throw new LogicException(
            "La extensión de liquidación comercial no está implementada para {$driver}."
        );
    }

    private function rewriteSettlementSql(
        string $definition,
        bool $includeReceivable
    ): string {
        $paymentPattern =
            '~OR\s+NEW\.total_minor\s+<>\s+COALESCE\(\(\s*'
            .'SELECT\s+SUM\(payment\.amount_minor\)\s+'
            .'FROM\s+commerce_payments\s+payment\s+'
            .'WHERE\s+payment\.organization_id\s*=\s*NEW\.organization_id\s+'
            .'AND\s+payment\.commerce_sale_id\s*=\s*NEW\.id\s*'
            .'\),\s*0\s*\)~is';

        $extendedPattern =
            '~OR\s+NEW\.total_minor\s+<>\s+COALESCE\(\(\s*'
            .'SELECT\s+SUM\(payment\.amount_minor\)\s+'
            .'FROM\s+commerce_payments\s+payment\s+'
            .'WHERE\s+payment\.organization_id\s*=\s*NEW\.organization_id\s+'
            .'AND\s+payment\.commerce_sale_id\s*=\s*NEW\.id\s*'
            .'\),\s*0\s*\)\s*\+\s*COALESCE\(\(\s*'
            .'SELECT\s+receivable\.amount_minor\s+'
            .'FROM\s+customer_receivables\s+receivable\s+'
            .'WHERE\s+receivable\.organization_id\s*=\s*NEW\.organization_id\s+'
            .'AND\s+receivable\.commerce_sale_id\s*=\s*NEW\.id\s*'
            .'\),\s*0\s*\)~is';

        $paymentSql = <<<'SQL'
OR NEW.total_minor <> COALESCE((
        SELECT SUM(payment.amount_minor)
        FROM commerce_payments payment
        WHERE payment.organization_id = NEW.organization_id
            AND payment.commerce_sale_id = NEW.id
    ), 0)
SQL;

        $extendedSql = <<<'SQL'
OR NEW.total_minor <> COALESCE((
        SELECT SUM(payment.amount_minor)
        FROM commerce_payments payment
        WHERE payment.organization_id = NEW.organization_id
            AND payment.commerce_sale_id = NEW.id
    ), 0) + COALESCE((
        SELECT receivable.amount_minor
        FROM customer_receivables receivable
        WHERE receivable.organization_id = NEW.organization_id
            AND receivable.commerce_sale_id = NEW.id
    ), 0)
SQL;

        $pattern = $includeReceivable
            ? $paymentPattern
            : $extendedPattern;
        $replacement = $includeReceivable
            ? $extendedSql
            : $paymentSql;

        $count = 0;
        $rewritten = preg_replace(
            $pattern,
            $replacement,
            $definition,
            1,
            $count
        );

        if (! is_string($rewritten) || $count !== 1) {
            throw new LogicException(
                'No se pudo extender de forma exacta el guard de liquidación comercial.'
            );
        }

        return $rewritten;
    }
};
