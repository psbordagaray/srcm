<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->string('type', 30);
            $table->string('provider', 100)->nullable();
            $table->char('currency_code', 3);
            $table->string('external_label', 191)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('updated_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'normalized_name'],
                'financial_accounts_org_name_unique'
            );
            $table->index(
                ['organization_id', 'active', 'currency_code'],
                'financial_accounts_org_active_currency_index'
            );
        });

        Schema::create(
            'financial_external_movements',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('financial_account_id')
                    ->constrained('financial_accounts')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->string('source', 20);
                $table->string('source_key', 191);
                $table->char('fingerprint', 64);
                $table->string('external_operation_id', 191)->nullable();
                $table->string('direction', 10);
                $table->string('status', 20);
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('gross_amount_minor');
                $table->unsignedBigInteger('fee_amount_minor')->default(0);
                $table->unsignedBigInteger('withholding_amount_minor')
                    ->default(0);
                $table->unsignedBigInteger('net_amount_minor');
                $table->timestampTz('occurred_at');
                $table->timestampTz('imported_at');
                $table->string('raw_reference', 500)->nullable();
                $table->foreignId('created_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('created_at');

                $table->unique(
                    [
                        'financial_account_id',
                        'source',
                        'source_key',
                    ],
                    'financial_external_movements_source_unique'
                );
                $table->index(
                    ['organization_id', 'occurred_at'],
                    'financial_external_movements_org_occurred_index'
                );
                $table->index(
                    [
                        'organization_id',
                        'external_operation_id',
                    ],
                    'financial_external_movements_operation_index'
                );
            }
        );

        Schema::create(
            'payment_reconciliations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId('commerce_payment_id')
                    ->constrained('commerce_payments')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('expected_amount_minor');
                $table->foreignId('opened_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('opened_at');
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'commerce_payment_id'],
                    'payment_reconciliations_payment_unique'
                );
                $table->index(
                    ['organization_id', 'opened_at'],
                    'payment_reconciliations_org_opened_index'
                );
            }
        );

        Schema::create(
            'payment_reconciliation_events',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('payment_reconciliation_id')
                    ->constrained('payment_reconciliations')
                    ->restrictOnDelete();
                $table->string('idempotency_key', 191);
                $table->string('status', 30);
                $table->unsignedBigInteger('allocated_gross_amount_minor');
                $table->bigInteger('difference_minor');
                $table->text('note')->nullable();
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('occurred_at');
                $table->timestampTz('created_at');

                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'payment_reconciliation_events_idem_unique'
                );
                $table->index(
                    ['payment_reconciliation_id', 'id'],
                    'payment_reconciliation_events_case_index'
                );
            }
        );

        Schema::create(
            'payment_reconciliation_allocations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger(
                    'payment_reconciliation_event_id'
                );
                $table->unsignedBigInteger(
                    'financial_external_movement_id'
                );
                $table->foreign(
                    'payment_reconciliation_event_id',
                    'pay_rec_alloc_event_fk'
                )
                    ->references('id')
                    ->on('payment_reconciliation_events')
                    ->restrictOnDelete();
                $table->foreign(
                    'financial_external_movement_id',
                    'pay_rec_alloc_movement_fk'
                )
                    ->references('id')
                    ->on('financial_external_movements')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('gross_amount_minor');
                $table->timestampTz('created_at');

                $table->unique(
                    [
                        'payment_reconciliation_event_id',
                        'financial_external_movement_id',
                    ],
                    'payment_reconciliation_allocations_event_movement_unique'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('payment_reconciliation_allocations');
        Schema::dropIfExists('payment_reconciliation_events');
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('financial_external_movements');
        Schema::dropIfExists('financial_accounts');
    }

    private function createGuards(): void
    {
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
            "La integridad financiera no está implementada para {$driver}."
        );
    }

    private function dropGuards(): void
    {
        foreach ($this->triggerNames() as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'financial_accounts_guard_update',
            'financial_accounts_guard_delete',
            'financial_external_movements_guard_insert',
            'financial_external_movements_guard_update',
            'financial_external_movements_guard_delete',
            'payment_reconciliations_guard_insert',
            'payment_reconciliations_guard_update',
            'payment_reconciliations_guard_delete',
            'payment_reconciliation_events_guard_insert',
            'payment_reconciliation_events_guard_update',
            'payment_reconciliation_events_guard_delete',
            'payment_reconciliation_allocations_guard_insert',
            'payment_reconciliation_allocations_guard_update',
            'payment_reconciliation_allocations_guard_delete',
        ];
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_update
BEFORE UPDATE ON financial_accounts
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.created_by_user_id <> OLD.created_by_user_id
BEGIN
    SELECT RAISE(ABORT, 'La identidad de la cuenta financiera es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_delete
BEFORE DELETE ON financial_accounts
BEGIN
    SELECT RAISE(ABORT, 'La cuenta financiera no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_external_movements_guard_insert
BEFORE INSERT ON financial_external_movements
WHEN NEW.gross_amount_minor < 1
    OR NEW.fee_amount_minor < 0
    OR NEW.withholding_amount_minor < 0
    OR NEW.net_amount_minor < 0
    OR NEW.gross_amount_minor <> (
        NEW.net_amount_minor
        + NEW.fee_amount_minor
        + NEW.withholding_amount_minor
    )
    OR NEW.direction NOT IN ('credit', 'debit')
    OR NEW.status NOT IN ('pending', 'posted', 'reversed', 'failed')
    OR NEW.source NOT IN ('api', 'webhook', 'polling', 'csv', 'xlsx', 'manual')
    OR NOT EXISTS (
        SELECT 1
        FROM financial_accounts account
        WHERE account.id = NEW.financial_account_id
            AND account.organization_id = NEW.organization_id
            AND account.currency_code = NEW.currency_code
    )
BEGIN
    SELECT RAISE(ABORT, 'El movimiento financiero externo no es válido.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_external_movements_guard_update
BEFORE UPDATE ON financial_external_movements
BEGIN
    SELECT RAISE(ABORT, 'El movimiento financiero externo es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_external_movements_guard_delete
BEFORE DELETE ON financial_external_movements
BEGIN
    SELECT RAISE(ABORT, 'El movimiento financiero externo no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliations_guard_insert
BEFORE INSERT ON payment_reconciliations
WHEN NEW.expected_amount_minor < 1
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_payments payment
        WHERE payment.id = NEW.commerce_payment_id
            AND payment.organization_id = NEW.organization_id
            AND payment.amount_minor = NEW.expected_amount_minor
    )
BEGIN
    SELECT RAISE(ABORT, 'El expediente de conciliación no es válido.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliations_guard_update
BEFORE UPDATE ON payment_reconciliations
BEGIN
    SELECT RAISE(ABORT, 'El expediente de conciliación es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliations_guard_delete
BEFORE DELETE ON payment_reconciliations
BEGIN
    SELECT RAISE(ABORT, 'El expediente de conciliación no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_events_guard_insert
BEFORE INSERT ON payment_reconciliation_events
WHEN NEW.allocated_gross_amount_minor < 1
    OR NEW.status NOT IN ('pending_review', 'matched', 'difference', 'resolved')
    OR NOT EXISTS (
        SELECT 1
        FROM payment_reconciliations reconciliation
        WHERE reconciliation.id = NEW.payment_reconciliation_id
            AND reconciliation.organization_id = NEW.organization_id
    )
BEGIN
    SELECT RAISE(ABORT, 'El evento de conciliación no es válido.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_events_guard_update
BEFORE UPDATE ON payment_reconciliation_events
BEGIN
    SELECT RAISE(ABORT, 'El evento de conciliación es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_events_guard_delete
BEFORE DELETE ON payment_reconciliation_events
BEGIN
    SELECT RAISE(ABORT, 'El evento de conciliación no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_allocations_guard_insert
BEFORE INSERT ON payment_reconciliation_allocations
WHEN NEW.gross_amount_minor < 1
    OR NOT EXISTS (
        SELECT 1
        FROM payment_reconciliation_events event
        WHERE event.id = NEW.payment_reconciliation_event_id
            AND event.organization_id = NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM financial_external_movements movement
        WHERE movement.id = NEW.financial_external_movement_id
            AND movement.organization_id = NEW.organization_id
            AND movement.gross_amount_minor >= NEW.gross_amount_minor
    )
BEGIN
    SELECT RAISE(ABORT, 'La asignación de conciliación no es válida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_allocations_guard_update
BEFORE UPDATE ON payment_reconciliation_allocations
BEGIN
    SELECT RAISE(ABORT, 'La asignación de conciliación es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_allocations_guard_delete
BEFORE DELETE ON payment_reconciliation_allocations
BEGIN
    SELECT RAISE(ABORT, 'La asignación de conciliación no puede eliminarse.');
END
SQL);
    }

    private function createMysqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_update
BEFORE UPDATE ON financial_accounts
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.created_by_user_id <> OLD.created_by_user_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La identidad de la cuenta financiera es inmutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_accounts_guard_delete
BEFORE DELETE ON financial_accounts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La cuenta financiera no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_external_movements_guard_insert
BEFORE INSERT ON financial_external_movements
FOR EACH ROW
BEGIN
    IF NEW.gross_amount_minor < 1
        OR NEW.gross_amount_minor <> (
            NEW.net_amount_minor
            + NEW.fee_amount_minor
            + NEW.withholding_amount_minor
        )
        OR NEW.direction NOT IN ('credit', 'debit')
        OR NEW.status NOT IN ('pending', 'posted', 'reversed', 'failed')
        OR NEW.source NOT IN ('api', 'webhook', 'polling', 'csv', 'xlsx', 'manual')
        OR NOT EXISTS (
            SELECT 1
            FROM financial_accounts account
            WHERE account.id = NEW.financial_account_id
                AND account.organization_id = NEW.organization_id
                AND account.currency_code = NEW.currency_code
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El movimiento financiero externo no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_external_movements_guard_update
BEFORE UPDATE ON financial_external_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El movimiento financiero externo es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER financial_external_movements_guard_delete
BEFORE DELETE ON financial_external_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El movimiento financiero externo no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliations_guard_insert
BEFORE INSERT ON payment_reconciliations
FOR EACH ROW
BEGIN
    IF NEW.expected_amount_minor < 1
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_payments payment
            WHERE payment.id = NEW.commerce_payment_id
                AND payment.organization_id = NEW.organization_id
                AND payment.amount_minor = NEW.expected_amount_minor
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El expediente de conciliacion no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliations_guard_update
BEFORE UPDATE ON payment_reconciliations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El expediente de conciliacion es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliations_guard_delete
BEFORE DELETE ON payment_reconciliations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El expediente de conciliacion no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_events_guard_insert
BEFORE INSERT ON payment_reconciliation_events
FOR EACH ROW
BEGIN
    IF NEW.allocated_gross_amount_minor < 1
        OR NEW.status NOT IN (
            'pending_review', 'matched', 'difference', 'resolved'
        )
        OR NOT EXISTS (
            SELECT 1
            FROM payment_reconciliations reconciliation
            WHERE reconciliation.id = NEW.payment_reconciliation_id
                AND reconciliation.organization_id = NEW.organization_id
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El evento de conciliacion no es valido.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_events_guard_update
BEFORE UPDATE ON payment_reconciliation_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El evento de conciliacion es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_events_guard_delete
BEFORE DELETE ON payment_reconciliation_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El evento de conciliacion no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_allocations_guard_insert
BEFORE INSERT ON payment_reconciliation_allocations
FOR EACH ROW
BEGIN
    IF NEW.gross_amount_minor < 1
        OR NOT EXISTS (
            SELECT 1
            FROM payment_reconciliation_events event_row
            WHERE event_row.id = NEW.payment_reconciliation_event_id
                AND event_row.organization_id = NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM financial_external_movements movement
            WHERE movement.id = NEW.financial_external_movement_id
                AND movement.organization_id = NEW.organization_id
                AND movement.gross_amount_minor >= NEW.gross_amount_minor
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La asignacion de conciliacion no es valida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_allocations_guard_update
BEFORE UPDATE ON payment_reconciliation_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La asignacion de conciliacion es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_reconciliation_allocations_guard_delete
BEFORE DELETE ON payment_reconciliation_allocations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La asignacion de conciliacion no puede eliminarse.';
END
SQL);
    }
};
