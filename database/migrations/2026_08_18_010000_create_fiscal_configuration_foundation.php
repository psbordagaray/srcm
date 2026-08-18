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
            'fiscal_organization_profiles',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->unique('fiscal_org_profiles_org_unique')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->string('legal_name', 191);
                $table->char('tax_id', 11);
                $table->string('vat_condition_code', 10);
                $table->string('gross_income_number', 50)->nullable();
                $table->date('activity_started_on');
                $table->string('address_line', 191);
                $table->string('city', 191);
                $table->string('province_code', 10);
                $table->string('postal_code', 20);
                $table->char('country_code', 2)->default('AR');
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('updated_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'fiscal_org_profiles_org_id_unique'
                );
                $table->index(
                    ['tax_id', 'vat_condition_code'],
                    'fiscal_org_profiles_identity_index'
                );
            }
        );

        Schema::create(
            'fiscal_points_of_sale',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('fiscal_organization_profile_id');
                $table->uuid('public_id')->unique();
                $table->string('environment', 20);
                $table->unsignedInteger('point_number');
                $table->string('integration_mode', 20);
                $table->boolean('active')->default(true);
                $table->foreignId('created_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('updated_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->foreign(
                    [
                        'organization_id',
                        'fiscal_organization_profile_id',
                    ],
                    'fiscal_pos_profile_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('fiscal_organization_profiles')
                    ->restrictOnDelete();
                $table->unique(
                    [
                        'organization_id',
                        'environment',
                        'point_number',
                    ],
                    'fiscal_pos_org_env_number_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'environment',
                        'active',
                    ],
                    'fiscal_pos_org_env_active_index'
                );
            }
        );

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('fiscal_points_of_sale');
        Schema::dropIfExists('fiscal_organization_profiles');
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_org_profiles_guard_update
BEFORE UPDATE ON fiscal_organization_profiles
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.created_by_user_id <> OLD.created_by_user_id
    OR (
        NEW.tax_id <> OLD.tax_id
        AND EXISTS (
            SELECT 1
            FROM fiscal_points_of_sale point
            WHERE point.fiscal_organization_profile_id = OLD.id
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'La pertenencia del perfil fiscal es inmutable.'
    );
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_org_profiles_guard_delete
BEFORE DELETE ON fiscal_organization_profiles
BEGIN
    SELECT RAISE(
        ABORT,
        'Un perfil fiscal no puede eliminarse físicamente.'
    );
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_points_of_sale_guard_insert
BEFORE INSERT ON fiscal_points_of_sale
WHEN NEW.environment NOT IN ('homologation', 'production')
    OR NEW.integration_mode NOT IN ('wsfe_v1', 'wsmtxca')
    OR NEW.point_number < 1
    OR NEW.point_number > 99999
    OR NEW.active NOT IN (0, 1)
    OR NOT EXISTS (
        SELECT 1
        FROM fiscal_organization_profiles profile
        WHERE profile.id = NEW.fiscal_organization_profile_id
            AND profile.organization_id = NEW.organization_id
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'El punto de venta fiscal no es válido.'
    );
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_points_of_sale_guard_update
BEFORE UPDATE ON fiscal_points_of_sale
WHEN NEW.organization_id <> OLD.organization_id
    OR NEW.fiscal_organization_profile_id
        <> OLD.fiscal_organization_profile_id
    OR NEW.public_id <> OLD.public_id
    OR NEW.environment <> OLD.environment
    OR NEW.point_number <> OLD.point_number
    OR NEW.integration_mode <> OLD.integration_mode
    OR NEW.created_by_user_id <> OLD.created_by_user_id
    OR NEW.active NOT IN (0, 1)
BEGIN
    SELECT RAISE(
        ABORT,
        'La identidad del punto de venta fiscal es inmutable.'
    );
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_points_of_sale_guard_delete
BEFORE DELETE ON fiscal_points_of_sale
BEGIN
    SELECT RAISE(
        ABORT,
        'Un punto de venta fiscal no puede eliminarse físicamente.'
    );
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_org_profiles_guard_update
BEFORE UPDATE ON fiscal_organization_profiles
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.created_by_user_id <> OLD.created_by_user_id
        OR (
            NEW.tax_id <> OLD.tax_id
            AND EXISTS (
                SELECT 1
                FROM fiscal_points_of_sale point_row
                WHERE point_row.fiscal_organization_profile_id = OLD.id
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La pertenencia del perfil fiscal es inmutable.';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_org_profiles_guard_delete
BEFORE DELETE ON fiscal_organization_profiles
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un perfil fiscal no puede eliminarse físicamente.';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_points_of_sale_guard_insert
BEFORE INSERT ON fiscal_points_of_sale
FOR EACH ROW
BEGIN
    IF NEW.environment NOT IN ('homologation', 'production')
        OR NEW.integration_mode NOT IN ('wsfe_v1', 'wsmtxca')
        OR NEW.point_number < 1
        OR NEW.point_number > 99999
        OR NEW.active NOT IN (0, 1)
        OR NOT EXISTS (
            SELECT 1
            FROM fiscal_organization_profiles profile
            WHERE profile.id = NEW.fiscal_organization_profile_id
                AND profile.organization_id = NEW.organization_id
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'El punto de venta fiscal no es válido.';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_points_of_sale_guard_update
BEFORE UPDATE ON fiscal_points_of_sale
FOR EACH ROW
BEGIN
    IF NEW.organization_id <> OLD.organization_id
        OR NEW.fiscal_organization_profile_id
            <> OLD.fiscal_organization_profile_id
        OR NEW.public_id <> OLD.public_id
        OR NEW.environment <> OLD.environment
        OR NEW.point_number <> OLD.point_number
        OR NEW.integration_mode <> OLD.integration_mode
        OR NEW.created_by_user_id <> OLD.created_by_user_id
        OR NEW.active NOT IN (0, 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'La identidad del punto de venta fiscal es inmutable.';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fiscal_points_of_sale_guard_delete
BEFORE DELETE ON fiscal_points_of_sale
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'Un punto de venta fiscal no puede eliminarse físicamente.';
END
SQL);

            return;
        }

        throw new LogicException(
            "Los guards P10.1 no están implementados para {$driver}."
        );
    }

    private function dropGuards(): void
    {
        foreach ([
            'fiscal_points_of_sale_guard_delete',
            'fiscal_points_of_sale_guard_update',
            'fiscal_points_of_sale_guard_insert',
            'fiscal_org_profiles_guard_delete',
            'fiscal_org_profiles_guard_update',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
