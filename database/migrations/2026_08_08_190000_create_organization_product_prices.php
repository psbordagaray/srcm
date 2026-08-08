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
            'organization_product_prices',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('catalog_product_id')
                    ->constrained('catalog_products')
                    ->restrictOnDelete();
                $table->char('currency_code', 3);
                $table->unsignedBigInteger('amount_minor');
                $table->timestampTz('valid_from');
                $table->timestampTz('valid_until')->nullable();
                // Current revisions use 1. Historical revisions use NULL.
                // SQLite and MySQL allow multiple NULLs in a UNIQUE key.
                $table->boolean('is_current')->nullable()->default(true);
                $table->string('reason', 500)->nullable();
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'org_product_prices_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'catalog_product_id',
                        'currency_code',
                        'is_current',
                    ],
                    'org_product_prices_current_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'catalog_product_id',
                        'currency_code',
                        'valid_from',
                    ],
                    'org_product_prices_history_index'
                );
            }
        );

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // Laravel rebuilds SQLite tables when adding a composite FK.
            // commerce_sales_guard_update already references
            // commerce_sale_lines, so that rebuild temporarily removes the
            // referenced table and SQLite refuses the rename. ADD COLUMN is
            // natively supported and preserves the existing immutable-ledger
            // triggers. A direct FK to the immutable price revision still
            // prevents dangling references.
            DB::statement(
                'ALTER TABLE commerce_sale_lines '
                .'ADD COLUMN organization_product_price_id INTEGER NULL '
                .'REFERENCES organization_product_prices(id) '
                .'ON DELETE RESTRICT'
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table(
                'commerce_sale_lines',
                function (Blueprint $table): void {
                    $table->unsignedBigInteger(
                        'organization_product_price_id'
                    )
                        ->nullable()
                        ->after('catalog_product_id');

                    $table->foreign(
                        [
                            'organization_id',
                            'organization_product_price_id',
                        ],
                        'commerce_sale_lines_org_price_fk'
                    )
                        ->references(['organization_id', 'id'])
                        ->on('organization_product_prices')
                        ->restrictOnDelete();
                }
            );

            return;
        }

        throw new LogicException(
            "Los precios comerciales no están implementados para {$driver}."
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'ALTER TABLE commerce_sale_lines '
                .'DROP COLUMN organization_product_price_id'
            );
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table(
                'commerce_sale_lines',
                function (Blueprint $table): void {
                    $table->dropForeign(
                        'commerce_sale_lines_org_price_fk'
                    );
                    $table->dropColumn(
                        'organization_product_price_id'
                    );
                }
            );
        } else {
            throw new LogicException(
                "Los precios comerciales no están implementados para {$driver}."
            );
        }

        Schema::dropIfExists('organization_product_prices');
    }
};
