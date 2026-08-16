<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER =
        'customer_credit_grants_guard_insert';

    private const UPDATE_TRIGGER =
        'customer_credit_grants_guard_update';

    private const DELETE_TRIGGER =
        'customer_credit_grants_guard_delete';

    public function up(): void
    {
        Schema::create(
            'customer_credit_grants',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'organization_id'
                )
                    ->constrained(
                        'organizations'
                    )
                    ->restrictOnDelete();

                $table->uuid(
                    'public_id'
                )->unique();

                $table->foreignId(
                    'business_party_id'
                )
                    ->constrained(
                        'business_parties'
                    )
                    ->restrictOnDelete();

                $table->foreignId(
                    'commerce_post_sale_resolution_id'
                )
                    ->unique()
                    ->constrained(
                        'commerce_post_sale_resolutions'
                    )
                    ->restrictOnDelete();

                $table->char(
                    'currency_code',
                    3
                );

                $table->unsignedBigInteger(
                    'amount_minor'
                );

                $table->foreignId(
                    'granted_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamp(
                    'granted_at'
                );

                $table->string(
                    'idempotency_key',
                    180
                );

                $table->char(
                    'fingerprint',
                    64
                );

                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'customer_credit_grants_org_idem_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'business_party_id',
                        'currency_code',
                        'granted_at',
                    ],
                    'customer_credit_grants_party_currency_index'
                );
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'customer_credit_grants'
        );
    }

    private function createTriggers(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (
            in_array(
                DB::getDriverName(),
                ['mysql', 'mariadb'],
                true
            )
        ) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            'La integridad P8.4.1 no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
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
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_grants_guard_insert
BEFORE INSERT ON customer_credit_grants
WHEN NEW.amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code) <> NEW.currency_code
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.granted_at IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_resolutions resolution
        INNER JOIN commerce_post_sale_requests request
            ON request.id =
                resolution.commerce_post_sale_request_id
        INNER JOIN commerce_sales sale
            ON sale.id =
                request.commerce_sale_id
        WHERE resolution.id =
                NEW.commerce_post_sale_resolution_id
          AND resolution.organization_id =
                NEW.organization_id
          AND resolution.outcome =
                'customer_credit'
          AND resolution.currency_code =
                NEW.currency_code
          AND request.organization_id =
                NEW.organization_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.status =
                'confirmed'
          AND sale.customer_business_party_id =
                NEW.business_party_id
          AND (
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
          ) = NEW.amount_minor
    )
    OR NOT EXISTS (
        SELECT 1
        FROM business_parties party
        WHERE party.id =
                NEW.business_party_id
          AND party.organization_id =
                NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.granted_by_user_id
          AND membership.active = 1
          AND membership.role =
                'admin'
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El saldo a favor no conserva resolución, cliente, valor o autoridad válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_grants_guard_update
BEFORE UPDATE ON customer_credit_grants
BEGIN
    SELECT RAISE(
        ABORT,
        'Un saldo a favor otorgado es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_grants_guard_delete
BEFORE DELETE ON customer_credit_grants
BEGIN
    SELECT RAISE(
        ABORT,
        'Un saldo a favor otorgado no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_grants_guard_insert
BEFORE INSERT ON customer_credit_grants
FOR EACH ROW
BEGIN
    IF NEW.amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR UPPER(NEW.currency_code) <> NEW.currency_code
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.granted_at IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_resolutions resolution
            INNER JOIN commerce_post_sale_requests request
                ON request.id =
                    resolution.commerce_post_sale_request_id
            INNER JOIN commerce_sales sale
                ON sale.id =
                    request.commerce_sale_id
            WHERE resolution.id =
                    NEW.commerce_post_sale_resolution_id
              AND resolution.organization_id =
                    NEW.organization_id
              AND resolution.outcome =
                    'customer_credit'
              AND resolution.currency_code =
                    NEW.currency_code
              AND request.organization_id =
                    NEW.organization_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.status =
                    'confirmed'
              AND sale.customer_business_party_id =
                    NEW.business_party_id
              AND (
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
              ) = NEW.amount_minor
        )
        OR NOT EXISTS (
            SELECT 1
            FROM business_parties party
            WHERE party.id =
                    NEW.business_party_id
              AND party.organization_id =
                    NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.granted_by_user_id
              AND membership.active = 1
              AND membership.role =
                    'admin'
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El saldo a favor no conserva resolución, cliente, valor o autoridad válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_grants_guard_update
BEFORE UPDATE ON customer_credit_grants
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un saldo a favor otorgado es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_credit_grants_guard_delete
BEFORE DELETE ON customer_credit_grants
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un saldo a favor otorgado no puede eliminarse.';
END
SQL);
    }
};
