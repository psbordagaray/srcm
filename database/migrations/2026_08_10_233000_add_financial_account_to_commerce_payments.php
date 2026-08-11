<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER = 'commerce_payments_guard_insert';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
ALTER TABLE commerce_payments
ADD COLUMN financial_account_id INTEGER NULL
REFERENCES financial_accounts(id) ON DELETE RESTRICT
SQL);

            DB::statement(<<<'SQL'
CREATE INDEX commerce_payments_financial_account_id_index
ON commerce_payments(financial_account_id)
SQL);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table(
                'commerce_payments',
                function (Blueprint $table): void {
                    $table->foreignId('financial_account_id')
                        ->nullable()
                        ->after('commerce_sale_id')
                        ->constrained('financial_accounts')
                        ->restrictOnDelete();
                }
            );
        } else {
            throw new LogicException(
                "La cuenta destino de cobros no está implementada para {$driver}."
            );
        }

        $this->dropInsertTrigger();
        $this->createAccountAwareInsertTrigger();
    }

    public function down(): void
    {
        $this->dropInsertTrigger();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS commerce_payments_financial_account_id_index'
            );
            DB::statement(
                'ALTER TABLE commerce_payments DROP COLUMN financial_account_id'
            );
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table(
                'commerce_payments',
                function (Blueprint $table): void {
                    $table->dropConstrainedForeignId(
                        'financial_account_id'
                    );
                }
            );
        } else {
            throw new LogicException(
                "La cuenta destino de cobros no está implementada para {$driver}."
            );
        }

        $this->createStructuredInsertTrigger();
    }

    private function dropInsertTrigger(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER
        );
    }

    private function createAccountAwareInsertTrigger(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
WHEN NEW.amount_minor < 1
    OR NEW.method NOT IN (
        'cash', 'debit_card', 'credit_card', 'bank_transfer',
        'digital_wallet', 'account_credit', 'other'
    )
    OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
    OR (NEW.card_brand IS NOT NULL AND TRIM(NEW.card_brand) = '')
    OR (NEW.card_network IS NOT NULL AND TRIM(NEW.card_network) = '')
    OR (
        NEW.card_last4 IS NOT NULL
        AND (
            LENGTH(TRIM(NEW.card_last4)) <> 4
            OR TRIM(NEW.card_last4) GLOB '*[^0-9]*'
        )
    )
    OR (
        NEW.installments IS NOT NULL
        AND (NEW.installments < 1 OR NEW.installments > 120)
    )
    OR (NEW.processor IS NOT NULL AND TRIM(NEW.processor) = '')
    OR (
        NEW.external_operation_id IS NOT NULL
        AND TRIM(NEW.external_operation_id) = ''
    )
    OR (
        NEW.authorization_code IS NOT NULL
        AND TRIM(NEW.authorization_code) = ''
    )
    OR (
        NEW.provider_status IS NOT NULL
        AND TRIM(NEW.provider_status) = ''
    )
    OR (
        NEW.method NOT IN ('debit_card', 'credit_card')
        AND (
            NEW.card_brand IS NOT NULL
            OR NEW.card_network IS NOT NULL
            OR NEW.card_last4 IS NOT NULL
            OR NEW.installments IS NOT NULL
        )
    )
    OR (
        NEW.method = 'cash'
        AND (
            NEW.processor IS NOT NULL
            OR NEW.external_operation_id IS NOT NULL
            OR NEW.authorization_code IS NOT NULL
            OR NEW.provider_status IS NOT NULL
        )
    )
    OR (
        NEW.financial_account_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            JOIN commerce_sales account_sale
                ON account_sale.id = NEW.commerce_sale_id
            WHERE account.id = NEW.financial_account_id
                AND account.organization_id = NEW.organization_id
                AND account.active = 1
                AND account.currency_code = account_sale.currency_code
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        WHERE sale.id = NEW.commerce_sale_id
            AND sale.organization_id = NEW.organization_id
            AND sale.status = 'building'
            AND NEW.paid_at <= sale.sold_at
    )
BEGIN
    SELECT RAISE(ABORT, 'El pago comercial no es válido.');
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.method NOT IN (
            'cash', 'debit_card', 'credit_card', 'bank_transfer',
            'digital_wallet', 'account_credit', 'other'
        )
        OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
        OR (NEW.card_brand IS NOT NULL AND TRIM(NEW.card_brand) = '')
        OR (NEW.card_network IS NOT NULL AND TRIM(NEW.card_network) = '')
        OR (
            NEW.card_last4 IS NOT NULL
            AND NEW.card_last4 NOT REGEXP '^[0-9]{4}$'
        )
        OR (
            NEW.installments IS NOT NULL
            AND (NEW.installments < 1 OR NEW.installments > 120)
        )
        OR (NEW.processor IS NOT NULL AND TRIM(NEW.processor) = '')
        OR (
            NEW.external_operation_id IS NOT NULL
            AND TRIM(NEW.external_operation_id) = ''
        )
        OR (
            NEW.authorization_code IS NOT NULL
            AND TRIM(NEW.authorization_code) = ''
        )
        OR (
            NEW.provider_status IS NOT NULL
            AND TRIM(NEW.provider_status) = ''
        )
        OR (
            NEW.method NOT IN ('debit_card', 'credit_card')
            AND (
                NEW.card_brand IS NOT NULL
                OR NEW.card_network IS NOT NULL
                OR NEW.card_last4 IS NOT NULL
                OR NEW.installments IS NOT NULL
            )
        )
        OR (
            NEW.method = 'cash'
            AND (
                NEW.processor IS NOT NULL
                OR NEW.external_operation_id IS NOT NULL
                OR NEW.authorization_code IS NOT NULL
                OR NEW.provider_status IS NOT NULL
            )
        )
        OR (
            NEW.financial_account_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM financial_accounts account
                JOIN commerce_sales account_sale
                    ON account_sale.id = NEW.commerce_sale_id
                WHERE account.id = NEW.financial_account_id
                    AND account.organization_id = NEW.organization_id
                    AND account.active = 1
                    AND account.currency_code = account_sale.currency_code
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            WHERE sale.id = NEW.commerce_sale_id
                AND sale.organization_id = NEW.organization_id
                AND sale.status = 'building'
                AND NEW.paid_at <= sale.sold_at
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El pago comercial no es valido.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "La cuenta destino de cobros no está implementada para {$driver}."
        );
    }

    private function createStructuredInsertTrigger(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
WHEN NEW.amount_minor < 1
    OR NEW.method NOT IN (
        'cash', 'debit_card', 'credit_card', 'bank_transfer',
        'digital_wallet', 'account_credit', 'other'
    )
    OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
    OR (NEW.card_brand IS NOT NULL AND TRIM(NEW.card_brand) = '')
    OR (NEW.card_network IS NOT NULL AND TRIM(NEW.card_network) = '')
    OR (
        NEW.card_last4 IS NOT NULL
        AND (
            LENGTH(TRIM(NEW.card_last4)) <> 4
            OR TRIM(NEW.card_last4) GLOB '*[^0-9]*'
        )
    )
    OR (
        NEW.installments IS NOT NULL
        AND (NEW.installments < 1 OR NEW.installments > 120)
    )
    OR (NEW.processor IS NOT NULL AND TRIM(NEW.processor) = '')
    OR (
        NEW.external_operation_id IS NOT NULL
        AND TRIM(NEW.external_operation_id) = ''
    )
    OR (
        NEW.authorization_code IS NOT NULL
        AND TRIM(NEW.authorization_code) = ''
    )
    OR (
        NEW.provider_status IS NOT NULL
        AND TRIM(NEW.provider_status) = ''
    )
    OR (
        NEW.method NOT IN ('debit_card', 'credit_card')
        AND (
            NEW.card_brand IS NOT NULL
            OR NEW.card_network IS NOT NULL
            OR NEW.card_last4 IS NOT NULL
            OR NEW.installments IS NOT NULL
        )
    )
    OR (
        NEW.method = 'cash'
        AND (
            NEW.processor IS NOT NULL
            OR NEW.external_operation_id IS NOT NULL
            OR NEW.authorization_code IS NOT NULL
            OR NEW.provider_status IS NOT NULL
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_sales sale
        WHERE sale.id = NEW.commerce_sale_id
            AND sale.organization_id = NEW.organization_id
            AND sale.status = 'building'
            AND NEW.paid_at <= sale.sold_at
    )
BEGIN
    SELECT RAISE(ABORT, 'El pago comercial no es válido.');
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
FOR EACH ROW
BEGIN
    IF NEW.amount_minor < 1
        OR NEW.method NOT IN (
            'cash', 'debit_card', 'credit_card', 'bank_transfer',
            'digital_wallet', 'account_credit', 'other'
        )
        OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
        OR (NEW.card_brand IS NOT NULL AND TRIM(NEW.card_brand) = '')
        OR (NEW.card_network IS NOT NULL AND TRIM(NEW.card_network) = '')
        OR (
            NEW.card_last4 IS NOT NULL
            AND NEW.card_last4 NOT REGEXP '^[0-9]{4}$'
        )
        OR (
            NEW.installments IS NOT NULL
            AND (NEW.installments < 1 OR NEW.installments > 120)
        )
        OR (NEW.processor IS NOT NULL AND TRIM(NEW.processor) = '')
        OR (
            NEW.external_operation_id IS NOT NULL
            AND TRIM(NEW.external_operation_id) = ''
        )
        OR (
            NEW.authorization_code IS NOT NULL
            AND TRIM(NEW.authorization_code) = ''
        )
        OR (
            NEW.provider_status IS NOT NULL
            AND TRIM(NEW.provider_status) = ''
        )
        OR (
            NEW.method NOT IN ('debit_card', 'credit_card')
            AND (
                NEW.card_brand IS NOT NULL
                OR NEW.card_network IS NOT NULL
                OR NEW.card_last4 IS NOT NULL
                OR NEW.installments IS NOT NULL
            )
        )
        OR (
            NEW.method = 'cash'
            AND (
                NEW.processor IS NOT NULL
                OR NEW.external_operation_id IS NOT NULL
                OR NEW.authorization_code IS NOT NULL
                OR NEW.provider_status IS NOT NULL
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_sales sale
            WHERE sale.id = NEW.commerce_sale_id
                AND sale.organization_id = NEW.organization_id
                AND sale.status = 'building'
                AND NEW.paid_at <= sale.sold_at
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El pago comercial no es valido.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "La evidencia de pagos no está implementada para {$driver}."
        );
    }
};
