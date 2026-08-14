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
            'financial_provider_connections',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('financial_account_id')
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->string('provider_key', 100);
                $table->string(
                    'external_account_id',
                    191
                )->nullable();
                $table->boolean('active')->default(true);
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('updated_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'financial_account_id',
                    ],
                    'financial_provider_connections_account_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'provider_key',
                        'external_account_id',
                    ],
                    'financial_provider_connections_external_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'provider_key',
                        'active',
                    ],
                    'financial_provider_connections_lookup_index'
                );
            }
        );

        $this->replaceFinancialAccountUpdateGuard();
        $this->createConnectionGuards();
    }

    public function down(): void
    {
        $this->dropConnectionGuards();
        $this->restoreFinancialAccountUpdateGuard();
        Schema::dropIfExists('financial_provider_connections');
    }

    private function replaceFinancialAccountUpdateGuard(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS financial_accounts_guard_update'
        );

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_update
BEFORE UPDATE ON financial_accounts
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.created_by_user_id <> OLD.created_by_user_id
    OR (
        EXISTS (
            SELECT 1
            FROM financial_provider_connections connection
            WHERE connection.financial_account_id = OLD.id
        )
        AND (
            NEW.type <> OLD.type
            OR COALESCE(NEW.provider, '') <> COALESCE(OLD.provider, '')
            OR NEW.currency_code <> OLD.currency_code
        )
    )
    OR (
        OLD.active = 1
        AND NEW.active = 0
        AND EXISTS (
            SELECT 1
            FROM financial_provider_connections connection
            WHERE connection.financial_account_id = OLD.id
                AND connection.active = 1
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La identidad de la cuenta financiera vinculada es inmutable.'
    );
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_update
BEFORE UPDATE ON financial_accounts
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.created_by_user_id <> OLD.created_by_user_id
        OR (
            EXISTS (
                SELECT 1
                FROM financial_provider_connections connection_row
                WHERE connection_row.financial_account_id = OLD.id
            )
            AND (
                NEW.type <> OLD.type
                OR COALESCE(NEW.provider, '') <> COALESCE(OLD.provider, '')
                OR NEW.currency_code <> OLD.currency_code
            )
        )
        OR (
            OLD.active = 1
            AND NEW.active = 0
            AND EXISTS (
                SELECT 1
                FROM financial_provider_connections connection_row
                WHERE connection_row.financial_account_id = OLD.id
                    AND connection_row.active = 1
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La identidad de la cuenta financiera vinculada es inmutable.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "Los guards P5.1 no están implementados para {$driver}."
        );
    }

    private function restoreFinancialAccountUpdateGuard(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS financial_accounts_guard_update'
        );

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_update
BEFORE UPDATE ON financial_accounts
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.created_by_user_id <> OLD.created_by_user_id
BEGIN
    SELECT RAISE(
        ABORT,
        'La identidad de la cuenta financiera es inmutable.'
    );
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_update
BEFORE UPDATE ON financial_accounts
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.created_by_user_id <> OLD.created_by_user_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La identidad de la cuenta financiera es inmutable.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "Los guards P5.1 no están implementados para {$driver}."
        );
    }

    private function createConnectionGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_provider_connections_guard_insert
BEFORE INSERT ON financial_provider_connections
WHEN trim(NEW.provider_key) = ''
    OR length(NEW.provider_key) > 100
    OR NEW.provider_key GLOB '*[^a-z0-9-]*'
    OR NEW.provider_key GLOB '-*'
    OR NEW.provider_key GLOB '*-'
    OR NEW.provider_key GLOB '*--*'
    OR NEW.active NOT IN (0, 1)
    OR (
        NEW.external_account_id IS NOT NULL
        AND trim(NEW.external_account_id) = ''
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.active = 1
            AND account.type NOT IN ('cash_box', 'cash_reserve')
            AND account.provider IS NOT NULL
            AND trim(account.provider) <> ''
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La conexión financiera P5.1 no es válida.'
    );
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_provider_connections_guard_update
BEFORE UPDATE ON financial_provider_connections
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.financial_account_id <> OLD.financial_account_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.provider_key <> OLD.provider_key
    OR COALESCE(NEW.external_account_id, '')
        <> COALESCE(OLD.external_account_id, '')
    OR NEW.created_by_user_id <> OLD.created_by_user_id
    OR NEW.active NOT IN (0, 1)
    OR (
        OLD.active = 0
        AND NEW.active = 1
        AND NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id = OLD.financial_account_id
                AND account.organization_id = OLD.organization_id
                AND account.active = 1
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La identidad de la conexión financiera es inmutable.'
    );
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_provider_connections_guard_delete
BEFORE DELETE ON financial_provider_connections
BEGIN
    SELECT RAISE(
        ABORT,
        'La conexión financiera no puede eliminarse.'
    );
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_provider_connections_guard_insert
BEFORE INSERT ON financial_provider_connections
FOR EACH ROW
BEGIN
    IF TRIM(NEW.provider_key) = ''
        OR CHAR_LENGTH(NEW.provider_key) > 100
        OR NEW.provider_key NOT REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'
        OR NEW.active NOT IN (0, 1)
        OR (
            NEW.external_account_id IS NOT NULL
            AND TRIM(NEW.external_account_id) = ''
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id = NEW.financial_account_id
                AND account.organization_id = NEW.organization_id
                AND account.active = 1
                AND account.type NOT IN ('cash_box', 'cash_reserve')
                AND account.provider IS NOT NULL
                AND TRIM(account.provider) <> ''
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La conexion financiera P5.1 no es valida.';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_provider_connections_guard_update
BEFORE UPDATE ON financial_provider_connections
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.financial_account_id <> OLD.financial_account_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.provider_key <> OLD.provider_key
        OR COALESCE(NEW.external_account_id, '')
            <> COALESCE(OLD.external_account_id, '')
        OR NEW.created_by_user_id <> OLD.created_by_user_id
        OR NEW.active NOT IN (0, 1)
        OR (
            OLD.active = 0
            AND NEW.active = 1
            AND NOT EXISTS (
                SELECT 1
                FROM financial_accounts account
                WHERE account.id = OLD.financial_account_id
                    AND account.organization_id = OLD.organization_id
                    AND account.active = 1
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La identidad de la conexion financiera es inmutable.';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_provider_connections_guard_delete
BEFORE DELETE ON financial_provider_connections
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'La conexion financiera no puede eliminarse.';
END
SQL);

            return;
        }

        throw new LogicException(
            "Los guards P5.1 no están implementados para {$driver}."
        );
    }

    private function dropConnectionGuards(): void
    {
        foreach ([
            'financial_provider_connections_guard_insert',
            'financial_provider_connections_guard_update',
            'financial_provider_connections_guard_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
