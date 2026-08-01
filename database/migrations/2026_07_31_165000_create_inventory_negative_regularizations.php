<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addLifecycleColumnsAndLineIndex();

        Schema::create(
            'inventory_negative_regularizations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->foreignId('inventory_negative_incident_id');
                $table->foreignId('inventory_negative_incident_line_id');
                $table->foreignId('regularizing_movement_id');
                $table->foreignId('applied_by_user_id');
                $table->decimal('quantity', 20, 6);
                $table->timestampTz('applied_at');
                $table->timestamps();

                $table->unique(
                    [
                        'regularizing_movement_id',
                        'inventory_negative_incident_line_id',
                    ],
                    'inv_neg_regularizations_movement_line_unique'
                );
                $table->index(
                    [
                        'inventory_negative_incident_id',
                        'applied_at',
                    ],
                    'inv_neg_regularizations_incident_time_index'
                );

                $table->foreign(
                    'organization_id',
                    'inv_neg_regularizations_org_fk'
                )->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'inventory_negative_incident_id'],
                    'inv_neg_regularizations_incident_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_negative_incidents')
                    ->restrictOnDelete();
                $table->foreign(
                    [
                        'organization_id',
                        'inventory_negative_incident_id',
                        'inventory_negative_incident_line_id',
                    ],
                    'inv_neg_regularizations_line_fk'
                )->references([
                    'organization_id',
                    'inventory_negative_incident_id',
                    'id',
                ])->on('inventory_negative_incident_lines')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'regularizing_movement_id'],
                    'inv_neg_regularizations_movement_fk'
                )->references(['organization_id', 'id'])
                    ->on('inventory_movements')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'applied_by_user_id'],
                    'inv_neg_regularizations_actor_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_negative_regularizations');

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(
                'DROP INDEX IF EXISTS inv_neg_inc_lines_org_inc_id_unique'
            );
            DB::unprepared(
                'ALTER TABLE inventory_negative_incidents '
                .'DROP COLUMN review_reason'
            );
            DB::unprepared(
                'ALTER TABLE inventory_negative_incidents '
                .'DROP COLUMN reviewed_at'
            );
            DB::unprepared(
                'ALTER TABLE inventory_negative_incidents '
                .'DROP COLUMN reviewed_by_user_id'
            );

            return;
        }

        Schema::table(
            'inventory_negative_incident_lines',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'inv_neg_inc_lines_org_inc_id_unique'
                );
            }
        );

        Schema::table(
            'inventory_negative_incidents',
            function (Blueprint $table): void {
                $table->dropForeign(
                    'inv_neg_incidents_reviewer_fk'
                );
                $table->dropConstrainedForeignId(
                    'reviewed_by_user_id'
                );
                $table->dropColumn([
                    'reviewed_at',
                    'review_reason',
                ]);
            }
        );
    }

    private function addLifecycleColumnsAndLineIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(
                'ALTER TABLE inventory_negative_incidents '
                .'ADD COLUMN reviewed_by_user_id INTEGER NULL'
            );
            DB::unprepared(
                'ALTER TABLE inventory_negative_incidents '
                .'ADD COLUMN reviewed_at DATETIME NULL'
            );
            DB::unprepared(
                'ALTER TABLE inventory_negative_incidents '
                .'ADD COLUMN review_reason TEXT NULL'
            );
            DB::unprepared(
                'CREATE UNIQUE INDEX '
                .'inv_neg_inc_lines_org_inc_id_unique '
                .'ON inventory_negative_incident_lines '
                .'(organization_id, inventory_negative_incident_id, id)'
            );

            return;
        }

        Schema::table(
            'inventory_negative_incidents',
            function (Blueprint $table): void {
                $table->foreignId('reviewed_by_user_id')
                    ->nullable()
                    ->after('regularized_at')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('reviewed_at')
                    ->nullable()
                    ->after('reviewed_by_user_id');
                $table->text('review_reason')
                    ->nullable()
                    ->after('reviewed_at');
                $table->foreign(
                    ['organization_id', 'reviewed_by_user_id'],
                    'inv_neg_incidents_reviewer_fk'
                )->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->restrictOnDelete();
            }
        );

        Schema::table(
            'inventory_negative_incident_lines',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'organization_id',
                        'inventory_negative_incident_id',
                        'id',
                    ],
                    'inv_neg_inc_lines_org_inc_id_unique'
                );
            }
        );
    }
};
