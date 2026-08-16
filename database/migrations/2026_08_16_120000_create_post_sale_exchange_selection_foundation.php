<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SELECTION_INSERT_TRIGGER =
        'commerce_post_sale_exchange_selections_guard_insert';

    private const SELECTION_UPDATE_TRIGGER =
        'commerce_post_sale_exchange_selections_guard_update';

    private const SELECTION_DELETE_TRIGGER =
        'commerce_post_sale_exchange_selections_guard_delete';

    private const LINE_INSERT_TRIGGER =
        'commerce_post_sale_exchange_selection_lines_guard_insert';

    private const LINE_UPDATE_TRIGGER =
        'commerce_post_sale_exchange_selection_lines_guard_update';

    private const LINE_DELETE_TRIGGER =
        'commerce_post_sale_exchange_selection_lines_guard_delete';

    public function up(): void
    {
        Schema::create(
            'commerce_post_sale_exchange_selections',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();

                $table->uuid('public_id')->unique();

                $table->foreignId(
                    'commerce_post_sale_resolution_id'
                )
                    ->unique()
                    ->constrained(
                        'commerce_post_sale_resolutions'
                    )
                    ->restrictOnDelete();

                $table->char('currency_code', 3);

                $table->unsignedBigInteger(
                    'recognized_amount_minor'
                );

                $table->foreignId(
                    'selected_by_user_id'
                )
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamp('selected_at');

                $table->text('notes')->nullable();

                $table->string(
                    'idempotency_key',
                    180
                );

                $table->char('fingerprint', 64);

                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'post_sale_exchange_selection_org_idem_unique'
                );
            }
        );

        Schema::create(
            'commerce_post_sale_exchange_selection_lines',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();

                $table->foreignId(
                    'commerce_post_sale_exchange_selection_id'
                )
                    ->constrained(
                        'commerce_post_sale_exchange_selections'
                    )
                    ->restrictOnDelete();

                $table->unsignedInteger('sequence');

                $table->foreignId(
                    'catalog_product_id'
                )
                    ->constrained('catalog_products')
                    ->restrictOnDelete();

                $table->foreignId(
                    'organization_product_price_id'
                )
                    ->constrained(
                        'organization_product_prices'
                    )
                    ->restrictOnDelete();

                $table->decimal(
                    'quantity',
                    18,
                    6
                );

                $table->unsignedBigInteger(
                    'unit_price_minor'
                );

                $table->unsignedBigInteger(
                    'line_amount_minor'
                );

                $table->timestamp('created_at');

                $table->unique(
                    [
                        'commerce_post_sale_exchange_selection_id',
                        'sequence',
                    ],
                    'post_sale_exchange_selection_lines_sequence_unique'
                );

                $table->unique(
                    [
                        'commerce_post_sale_exchange_selection_id',
                        'catalog_product_id',
                    ],
                    'post_sale_exchange_selection_lines_product_unique'
                );
            }
        );

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'commerce_post_sale_exchange_selection_lines'
        );

        Schema::dropIfExists(
            'commerce_post_sale_exchange_selections'
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
            'La integridad P8.4.4 no está implementada para '
            .DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach ([
            self::LINE_DELETE_TRIGGER,
            self::LINE_UPDATE_TRIGGER,
            self::LINE_INSERT_TRIGGER,
            self::SELECTION_DELETE_TRIGGER,
            self::SELECTION_UPDATE_TRIGGER,
            self::SELECTION_INSERT_TRIGGER,
        ] as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '.$trigger
            );
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selections_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_selections
WHEN NEW.recognized_amount_minor <= 0
    OR LENGTH(NEW.currency_code) <> 3
    OR UPPER(NEW.currency_code) <> NEW.currency_code
    OR LENGTH(TRIM(NEW.idempotency_key)) = 0
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.selected_at IS NULL
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
          AND resolution.outcome = 'exchange'
          AND resolution.currency_code =
                NEW.currency_code
          AND request.organization_id =
                NEW.organization_id
          AND sale.organization_id =
                NEW.organization_id
          AND sale.status = 'confirmed'
          AND sale.currency_code =
                NEW.currency_code
          AND (
              SELECT COALESCE(
                  SUM(
                      line.recognized_amount_minor
                  ),
                  0
              )
              FROM commerce_post_sale_resolution_lines line
              WHERE line.organization_id =
                    NEW.organization_id
                AND line.commerce_post_sale_resolution_id =
                    resolution.id
          ) = NEW.recognized_amount_minor
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_memberships membership
        WHERE membership.organization_id =
                NEW.organization_id
          AND membership.user_id =
                NEW.selected_by_user_id
          AND membership.active = 1
          AND membership.role = 'admin'
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La selección de cambio no conserva resolución, valor, moneda o autoridad válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selections_guard_update
BEFORE UPDATE ON commerce_post_sale_exchange_selections
BEGIN
    SELECT RAISE(
        ABORT,
        'Una selección de cambio confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selections_guard_delete
BEFORE DELETE ON commerce_post_sale_exchange_selections
BEGIN
    SELECT RAISE(
        ABORT,
        'Una selección de cambio confirmada no puede eliminarse.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selection_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_selection_lines
WHEN NEW.sequence <= 0
    OR NEW.quantity <= 0
    OR NEW.unit_price_minor <= 0
    OR NEW.line_amount_minor <= 0
    OR ABS(
        (NEW.quantity * NEW.unit_price_minor)
        - NEW.line_amount_minor
    ) > 0.000001
    OR NOT EXISTS (
        SELECT 1
        FROM commerce_post_sale_exchange_selections selection
        WHERE selection.id =
                NEW.commerce_post_sale_exchange_selection_id
          AND selection.organization_id =
                NEW.organization_id
    )
    OR NOT EXISTS (
        SELECT 1
        FROM catalog_products product
        WHERE product.id =
                NEW.catalog_product_id
          AND product.active = 1
    )
    OR NOT EXISTS (
        SELECT 1
        FROM organization_product_prices price
        INNER JOIN commerce_post_sale_exchange_selections selection
            ON selection.id =
                NEW.commerce_post_sale_exchange_selection_id
        WHERE price.id =
                NEW.organization_product_price_id
          AND price.organization_id =
                NEW.organization_id
          AND price.catalog_product_id =
                NEW.catalog_product_id
          AND price.currency_code =
                selection.currency_code
          AND price.amount_minor =
                NEW.unit_price_minor
          AND price.valid_from <=
                selection.selected_at
          AND (
              price.valid_until IS NULL
              OR price.valid_until >
                    selection.selected_at
          )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La línea de cambio no conserva producto, precio autorizado, cantidad o importe válidos.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selection_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_exchange_selection_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de reemplazo confirmada es inmutable.'
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selection_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_exchange_selection_lines
BEGIN
    SELECT RAISE(
        ABORT,
        'Una línea de reemplazo confirmada no puede eliminarse.'
    );
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selections_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_selections
FOR EACH ROW
BEGIN
    IF NEW.recognized_amount_minor <= 0
        OR CHAR_LENGTH(NEW.currency_code) <> 3
        OR UPPER(NEW.currency_code) <> NEW.currency_code
        OR CHAR_LENGTH(TRIM(NEW.idempotency_key)) = 0
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.selected_at IS NULL
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
              AND resolution.outcome = 'exchange'
              AND resolution.currency_code =
                    NEW.currency_code
              AND request.organization_id =
                    NEW.organization_id
              AND sale.organization_id =
                    NEW.organization_id
              AND sale.status = 'confirmed'
              AND sale.currency_code =
                    NEW.currency_code
              AND (
                  SELECT COALESCE(
                      SUM(
                          line.recognized_amount_minor
                      ),
                      0
                  )
                  FROM commerce_post_sale_resolution_lines line
                  WHERE line.organization_id =
                        NEW.organization_id
                    AND line.commerce_post_sale_resolution_id =
                        resolution.id
              ) = NEW.recognized_amount_minor
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_memberships membership
            WHERE membership.organization_id =
                    NEW.organization_id
              AND membership.user_id =
                    NEW.selected_by_user_id
              AND membership.active = 1
              AND membership.role = 'admin'
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La selección de cambio no conserva resolución, valor, moneda o autoridad válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selections_guard_update
BEFORE UPDATE ON commerce_post_sale_exchange_selections
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una selección de cambio confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selections_guard_delete
BEFORE DELETE ON commerce_post_sale_exchange_selections
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una selección de cambio confirmada no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selection_lines_guard_insert
BEFORE INSERT ON commerce_post_sale_exchange_selection_lines
FOR EACH ROW
BEGIN
    IF NEW.sequence <= 0
        OR NEW.quantity <= 0
        OR NEW.unit_price_minor <= 0
        OR NEW.line_amount_minor <= 0
        OR (NEW.quantity * NEW.unit_price_minor)
            <> NEW.line_amount_minor
        OR NOT EXISTS (
            SELECT 1
            FROM commerce_post_sale_exchange_selections selection
            WHERE selection.id =
                    NEW.commerce_post_sale_exchange_selection_id
              AND selection.organization_id =
                    NEW.organization_id
        )
        OR NOT EXISTS (
            SELECT 1
            FROM catalog_products product
            WHERE product.id =
                    NEW.catalog_product_id
              AND product.active = 1
        )
        OR NOT EXISTS (
            SELECT 1
            FROM organization_product_prices price
            INNER JOIN commerce_post_sale_exchange_selections selection
                ON selection.id =
                    NEW.commerce_post_sale_exchange_selection_id
            WHERE price.id =
                    NEW.organization_product_price_id
              AND price.organization_id =
                    NEW.organization_id
              AND price.catalog_product_id =
                    NEW.catalog_product_id
              AND price.currency_code =
                    selection.currency_code
              AND price.amount_minor =
                    NEW.unit_price_minor
              AND price.valid_from <=
                    selection.selected_at
              AND (
                  price.valid_until IS NULL
                  OR price.valid_until >
                        selection.selected_at
              )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La línea de cambio no conserva producto, precio autorizado, cantidad o importe válidos.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selection_lines_guard_update
BEFORE UPDATE ON commerce_post_sale_exchange_selection_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de reemplazo confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER commerce_post_sale_exchange_selection_lines_guard_delete
BEFORE DELETE ON commerce_post_sale_exchange_selection_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Una línea de reemplazo confirmada no puede eliminarse.';
END
SQL);
    }
};
