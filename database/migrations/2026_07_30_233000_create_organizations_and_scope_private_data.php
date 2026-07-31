<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('slug')->unique();
            $table->string('tax_id', 64)->nullable();
            $table->string('normalized_tax_id', 64)
                ->nullable()
                ->unique();
            $table->string('email')->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('website', 2048)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('normalized_name');
            $table->index('active');
        });

        $now = now();

        $defaultOrganizationId = DB::table('organizations')
            ->insertGetId([
                'name' => 'SULU TV',
                'normalized_name' => 'sulutv',
                'slug' => 'sulu-tv',
                'tax_id' => null,
                'normalized_tax_id' => null,
                'email' => null,
                'phone' => null,
                'website' => null,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        Schema::create(
            'organization_memberships',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('role', 30);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'user_id'],
                    'organization_memberships_identity_unique'
                );
                $table->index(['user_id', 'active']);
                $table->index(['organization_id', 'active']);
            }
        );

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('role')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->softDeletes();
        });

        Schema::table(
            'business_parties',
            function (Blueprint $table): void {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
            }
        );

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->restrictOnDelete();
        });

        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
            }
        );

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->restrictOnDelete();
        });

        DB::table('business_parties')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => $defaultOrganizationId,
            ]);

        DB::table('suppliers')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => $defaultOrganizationId,
            ]);

        DB::table('supplier_offers')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => $defaultOrganizationId,
            ]);

        DB::table('audit_logs')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => $defaultOrganizationId,
            ]);

        foreach (
            DB::table('users')
                ->select(['id', 'role'])
                ->orderBy('id')
                ->cursor()
            as $user
        ) {
            $role = in_array(
                $user->role,
                ['admin', 'operator', 'viewer'],
                true
            )
                ? $user->role
                : 'viewer';

            DB::table('organization_memberships')->insert([
                'organization_id' => $defaultOrganizationId,
                'user_id' => $user->id,
                'role' => $role,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'current_organization_id' =>
                        $defaultOrganizationId,
                ]);
        }

        Schema::table(
            'business_parties',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'business_parties_normalized_tax_id_unique'
                );
                $table->dropForeign([
                    'organization_id',
                ]);
            }
        );

        Schema::table(
            'business_parties',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')
                    ->nullable(false)
                    ->change();
            }
        );

        Schema::table(
            'business_parties',
            function (Blueprint $table): void {
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->unique(
                    ['organization_id', 'normalized_tax_id'],
                    'business_parties_organization_tax_unique'
                );

                $table->unique(
                    ['organization_id', 'id'],
                    'business_parties_organization_id_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'party_type',
                        'normalized_name',
                    ],
                    'business_parties_organization_identity_index'
                );
            }
        );
        Schema::table(
            'suppliers',
            function (Blueprint $table): void {
                $table->dropForeign([
                    'organization_id',
                ]);
            }
        );

        Schema::table(
            'suppliers',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')
                    ->nullable(false)
                    ->change();
            }
        );

        Schema::table(
            'suppliers',
            function (Blueprint $table): void {
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->unique(
                    ['organization_id', 'id'],
                    'suppliers_organization_id_unique'
                );

                $table->foreign(
                    ['organization_id', 'business_party_id'],
                    'suppliers_organization_party_foreign'
                )
                    ->references(['organization_id', 'id'])
                    ->on('business_parties')
                    ->restrictOnDelete();

                $table->index(
                    ['organization_id', 'active'],
                    'suppliers_organization_active_index'
                );
            }
        );

        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->dropForeign([
                    'organization_id',
                ]);
            }
        );

        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')
                    ->nullable(false)
                    ->change();
            }
        );

        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    ['organization_id', 'supplier_id'],
                    'supplier_offers_organization_supplier_foreign'
                )
                    ->references(['organization_id', 'id'])
                    ->on('suppliers')
                    ->restrictOnDelete();

                $table->index(
                    ['organization_id', 'active'],
                    'supplier_offers_organization_active_index'
                );

                $table->index(
                    [
                        'organization_id',
                        'availability_status',
                        'checked_at',
                    ],
                    'supplier_offers_organization_freshness_index'
                );
            }
        );

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER audit_logs_prevent_update
BEFORE UPDATE ON audit_logs
BEGIN
    SELECT RAISE(
        ABORT,
        'Los registros de auditoría no pueden modificarse.'
    );
END
SQL
            );

            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER audit_logs_prevent_delete
BEFORE DELETE ON audit_logs
BEGIN
    SELECT RAISE(
        ABORT,
        'Los registros de auditoría no pueden eliminarse.'
    );
END
SQL
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS audit_logs_prevent_update'
            );
            DB::unprepared(
                'DROP TRIGGER IF EXISTS audit_logs_prevent_delete'
            );
        }

        Schema::table(
            'supplier_offers',
            function (Blueprint $table): void {
                $table->dropForeign(
                    'supplier_offers_organization_supplier_foreign'
                );
                $table->dropIndex(
                    'supplier_offers_organization_freshness_index'
                );
                $table->dropIndex(
                    'supplier_offers_organization_active_index'
                );
                $table->dropConstrainedForeignId('organization_id');
            }
        );

        Schema::table(
            'suppliers',
            function (Blueprint $table): void {
                $table->dropForeign(
                    'suppliers_organization_party_foreign'
                );
                $table->dropUnique(
                    'suppliers_organization_id_unique'
                );
                $table->dropIndex(
                    'suppliers_organization_active_index'
                );
                $table->dropConstrainedForeignId('organization_id');
            }
        );

        Schema::table(
            'business_parties',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'business_parties_organization_identity_index'
                );
                $table->dropUnique(
                    'business_parties_organization_tax_unique'
                );
                $table->dropUnique(
                    'business_parties_organization_id_unique'
                );
                $table->dropConstrainedForeignId('organization_id');
                $table->unique('normalized_tax_id');
            }
        );

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId(
                'current_organization_id'
            );
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
