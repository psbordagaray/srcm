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
            'financial_provider_compatibilities',
            function (Blueprint $table): void {
                $table->id();
                $table->string('registry_key', 191)->unique();
                $table->string('provider_key', 100)->index();
                $table->string('provider_label', 120);
                $table->string(
                    'provider_contract_version',
                    120
                );
                $table->string(
                    'provider_contract_reference',
                    500
                );
                $table->string('adapter_class', 255)
                    ->nullable();
                $table->string(
                    'adapter_contract_version',
                    120
                );
                $table->string(
                    'compatibility_status',
                    32
                )->index();
                $table->boolean('migration_required')
                    ->default(false)
                    ->index();
                $table->string('srcm_version', 120);
                $table->timestamp('verified_at');
                $table->text('notes')->nullable();
                $table->timestamp('created_at');

                $table->index([
                    'provider_key',
                    'provider_contract_version',
                    'adapter_contract_version',
                ], 'fp_compat_provider_contract_idx');
            }
        );

        Schema::create(
            'financial_provider_capability_compatibilities',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId(
                    'financial_provider_compatibility_id'
                )->constrained(
                    'financial_provider_compatibilities'
                )->restrictOnDelete();
                $table->string('capability', 64);
                $table->string(
                    'compatibility_status',
                    32
                )->index();
                $table->boolean('required')
                    ->default(false);
                $table->string(
                    'evidence_reference',
                    500
                );
                $table->text('notes')->nullable();
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'financial_provider_compatibility_id',
                        'capability',
                    ],
                    'fp_capability_unique'
                );
            }
        );

        $this->installImmutability();
    }

    public function down(): void
    {
        $this->dropImmutability();

        Schema::dropIfExists(
            'financial_provider_capability_compatibilities'
        );
        Schema::dropIfExists(
            'financial_provider_compatibilities'
        );
    }

    private function installImmutability(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach ([
                'financial_provider_compatibilities',
                'financial_provider_capability_compatibilities',
            ] as $table) {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_update
                    BEFORE UPDATE ON {$table}
                    BEGIN
                        SELECT RAISE(
                            ABORT,
                            'financial provider compatibility is immutable'
                        );
                    END"
                );

                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_delete
                    BEFORE DELETE ON {$table}
                    BEGIN
                        SELECT RAISE(
                            ABORT,
                            'financial provider compatibility is immutable'
                        );
                    END"
                );
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ([
                'financial_provider_compatibilities',
                'financial_provider_capability_compatibilities',
            ] as $table) {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_update
                    BEFORE UPDATE ON {$table}
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT =
                        'financial provider compatibility is immutable'"
                );

                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_delete
                    BEFORE DELETE ON {$table}
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT =
                        'financial provider compatibility is immutable'"
                );
            }
        }
    }

    private function dropImmutability(): void
    {
        foreach ([
            'financial_provider_compatibilities',
            'financial_provider_capability_compatibilities',
        ] as $table) {
            foreach ([
                "{$table}_immutable_update",
                "{$table}_immutable_delete",
            ] as $trigger) {
                try {
                    DB::unprepared(
                        "DROP TRIGGER IF EXISTS {$trigger}"
                    );
                } catch (Throwable) {
                    // Best effort for cross-driver rollback.
                }
            }
        }
    }
};
