<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER =
        'purchase_obligations_guard_insert';

    private const UPDATE_TRIGGER =
        'purchase_obligations_guard_update';

    private const DELETE_TRIGGER =
        'purchase_obligations_guard_delete';

    public function up(): void
    {
        Schema::create('purchase_obligations', function (
            Blueprint $table
        ): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')
                ->constrained('purchase_receipts')
                ->restrictOnDelete();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();
            $table->foreignId(
                'beneficiary_business_party_id'
            )
                ->constrained('business_parties')
                ->restrictOnDelete();
            $table->string('kind', 32);
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('payment_condition', 32);
            $table->date('due_on')->nullable();
            $table->text('condition_note')->nullable();
            $table->string('idempotency_key', 180);
            $table->char('fingerprint', 64);
            $table->foreignId('recognized_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('recognized_at');
            $table->timestamp('created_at');

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'purchase_obligations_org_idem_unique'
            );
            $table->unique(
                [
                    'organization_id',
                    'purchase_receipt_id',
                    'kind',
                ],
                'purchase_obligations_receipt_kind_unique'
            );
            $table->index(
                [
                    'organization_id',
                    'beneficiary_business_party_id',
                ],
                'purchase_obligations_beneficiary_index'
            );
            $table->index(
                ['organization_id', 'due_on'],
                'purchase_obligations_due_index'
            );
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('purchase_obligations');
    }

    private function createTriggers(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            'La integridad de obligaciones de compra no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach ([
            self::INSERT_TRIGGER,
            self::UPDATE_TRIGGER,
            self::DELETE_TRIGGER,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_obligations_guard_insert
BEFORE INSERT ON purchase_obligations
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR NEW.currency_code <> UPPER(NEW.currency_code)
    OR NEW.kind NOT IN ('merchandise', 'logistics')
    OR NEW.payment_condition NOT IN (
        'on_receipt',
        'due_date',
        'other'
    )
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.recognized_by_user_id IS NULL
    OR NEW.recognized_at IS NULL
    OR NEW.created_at IS NULL
    OR (
        NEW.payment_condition = 'due_date'
        AND NEW.due_on IS NULL
    )
    OR (
        NEW.payment_condition <> 'due_date'
        AND NEW.due_on IS NOT NULL
    )
    OR (
        NEW.payment_condition = 'other'
        AND (
            NEW.condition_note IS NULL
            OR LENGTH(TRIM(NEW.condition_note)) = 0
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_receipts receipt
        INNER JOIN purchase_orders purchase_order
            ON purchase_order.id = receipt.purchase_order_id
        WHERE receipt.id = NEW.purchase_receipt_id
          AND receipt.organization_id = NEW.organization_id
          AND receipt.purchase_order_id = NEW.purchase_order_id
          AND receipt.supplier_id = NEW.supplier_id
          AND purchase_order.organization_id = NEW.organization_id
          AND purchase_order.id = NEW.purchase_order_id
          AND purchase_order.supplier_id = NEW.supplier_id
          AND purchase_order.currency_code = NEW.currency_code
          AND (
              (
                  NEW.kind = 'merchandise'
                  AND receipt.merchandise_total_minor > 0
                  AND NEW.amount_minor =
                      receipt.merchandise_total_minor
              )
              OR (
                  NEW.kind = 'logistics'
                  AND receipt.logistics_cost_minor > 0
                  AND NEW.amount_minor =
                      receipt.logistics_cost_minor
              )
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
        FROM organization_memberships membership
        WHERE membership.organization_id =
            NEW.organization_id
          AND membership.user_id =
            NEW.recognized_by_user_id
          AND membership.active = 1
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La obligación no conserva fuente, importe, beneficiario, condición o autoridad válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_obligations_guard_update
BEFORE UPDATE ON purchase_obligations
BEGIN
    SELECT RAISE(
        ABORT,
        'Una obligación económica reconocida es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_obligations_guard_delete
BEFORE DELETE ON purchase_obligations
BEGIN
    SELECT RAISE(
        ABORT,
        'Una obligación económica reconocida no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_obligations_guard_insert
BEFORE INSERT ON purchase_obligations
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR NEW.currency_code <> UPPER(NEW.currency_code)
        OR NEW.kind NOT IN ('merchandise', 'logistics')
        OR NEW.payment_condition NOT IN (
            'on_receipt',
            'due_date',
            'other'
        )
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.recognized_by_user_id IS NULL
        OR NEW.recognized_at IS NULL
        OR NEW.created_at IS NULL
        OR (
            NEW.payment_condition = 'due_date'
            AND NEW.due_on IS NULL
        )
        OR (
            NEW.payment_condition <> 'due_date'
            AND NEW.due_on IS NOT NULL
        )
        OR (
            NEW.payment_condition = 'other'
            AND (
                NEW.condition_note IS NULL
                OR CHAR_LENGTH(TRIM(NEW.condition_note)) = 0
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM purchase_receipts receipt
            INNER JOIN purchase_orders purchase_order
                ON purchase_order.id =
                    receipt.purchase_order_id
            WHERE receipt.id = NEW.purchase_receipt_id
              AND receipt.organization_id =
                  NEW.organization_id
              AND receipt.purchase_order_id =
                  NEW.purchase_order_id
              AND receipt.supplier_id = NEW.supplier_id
              AND purchase_order.organization_id =
                  NEW.organization_id
              AND purchase_order.id =
                  NEW.purchase_order_id
              AND purchase_order.supplier_id =
                  NEW.supplier_id
              AND purchase_order.currency_code =
                  NEW.currency_code
              AND (
                  (
                      NEW.kind = 'merchandise'
                      AND receipt.merchandise_total_minor > 0
                      AND NEW.amount_minor =
                          receipt.merchandise_total_minor
                  )
                  OR (
                      NEW.kind = 'logistics'
                      AND receipt.logistics_cost_minor > 0
                      AND NEW.amount_minor =
                          receipt.logistics_cost_minor
                  )
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
            FROM organization_memberships membership
            WHERE membership.organization_id =
                NEW.organization_id
              AND membership.user_id =
                NEW.recognized_by_user_id
              AND membership.active = 1
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La obligación no conserva fuente, importe, beneficiario, condición o autoridad válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_obligations_guard_update
BEFORE UPDATE ON purchase_obligations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una obligación económica reconocida es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_obligations_guard_delete
BEFORE DELETE ON purchase_obligations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una obligación económica reconocida no puede eliminarse.';
END
SQL);
    }
};
