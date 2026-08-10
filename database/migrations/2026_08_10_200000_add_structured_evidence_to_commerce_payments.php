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
        Schema::table('commerce_payments', function (Blueprint $table): void {
            $table->string('card_brand', 50)->nullable();
            $table->string('card_network', 50)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('processor', 100)->nullable();
            $table->string('external_operation_id', 191)->nullable();
            $table->string('authorization_code', 100)->nullable();
            $table->string('provider_status', 50)->nullable();

            $table->index(
                ['organization_id', 'processor', 'external_operation_id'],
                'commerce_payments_external_evidence_index'
            );
        });

        $this->dropInsertTrigger();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createStructuredSqliteInsertTrigger();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createStructuredMysqlInsertTrigger();

            return;
        }

        throw new LogicException(
            "La evidencia de pagos no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropInsertTrigger();

        Schema::table('commerce_payments', function (Blueprint $table): void {
            $table->dropIndex('commerce_payments_external_evidence_index');
            $table->dropColumn([
                'card_brand',
                'card_network',
                'card_last4',
                'installments',
                'processor',
                'external_operation_id',
                'authorization_code',
                'provider_status',
            ]);
        });

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createLegacySqliteInsertTrigger();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createLegacyMysqlInsertTrigger();

            return;
        }

        throw new LogicException(
            "La integridad comercial no está implementada para {$driver}."
        );
    }

    private function dropInsertTrigger(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER
        );
    }

    private function createStructuredSqliteInsertTrigger(): void
    {
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
    }

    private function createStructuredMysqlInsertTrigger(): void
    {
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
    }

    private function createLegacySqliteInsertTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_payments_guard_insert
BEFORE INSERT ON commerce_payments
WHEN NEW.amount_minor < 1
    OR NEW.method NOT IN (
        'cash', 'debit_card', 'credit_card', 'bank_transfer',
        'digital_wallet', 'account_credit', 'other'
    )
    OR (NEW.method <> 'cash' AND TRIM(COALESCE(NEW.reference, '')) = '')
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
    }

    private function createLegacyMysqlInsertTrigger(): void
    {
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
    }
};
