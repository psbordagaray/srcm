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
            'financial_provider_connection_health_checks',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId(
                    'financial_provider_connection_id'
                )->constrained(
                    'financial_provider_connections'
                )->restrictOnDelete();
                $table->foreignId(
                    'financial_provider_connection_compatibility_binding_id'
                )->nullable()
                    ->constrained(
                        'financial_provider_connection_compatibility_bindings'
                    )->restrictOnDelete();
                $table->string('capability', 64);
                $table->string('health_status', 32);
                $table->string('source_key', 120);
                $table->string(
                    'diagnostic_code',
                    120
                )->nullable();
                $table->unsignedInteger(
                    'latency_ms'
                )->nullable();
                $table->timestamp('checked_at');
                $table->timestamp('created_at');

                $table->index(
                    [
                        'financial_provider_connection_id',
                        'capability',
                        'checked_at',
                    ],
                    'fp_health_connection_capability_idx'
                );

                $table->index(
                    [
                        'financial_provider_connection_compatibility_binding_id',
                        'capability',
                        'checked_at',
                    ],
                    'fp_health_binding_capability_idx'
                );
            }
        );

        $this->installGuards();
        $this->installImmutability();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'financial_provider_connection_health_checks'
        );
    }

    private function installGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER fp_connection_health_insert_guard
BEFORE INSERT ON financial_provider_connection_health_checks
BEGIN
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM financial_provider_connections AS connection
            WHERE connection.id =
                NEW.financial_provider_connection_id
              AND connection.organization_id =
                NEW.organization_id
        )
        THEN RAISE(
            ABORT,
            'financial provider health organization mismatch'
        )
    END;

    SELECT CASE
        WHEN NEW.financial_provider_connection_compatibility_binding_id
            IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM financial_provider_connection_compatibility_bindings
                AS binding
                WHERE binding.id =
                    NEW.financial_provider_connection_compatibility_binding_id
                  AND binding.financial_provider_connection_id =
                    NEW.financial_provider_connection_id
            )
        THEN RAISE(
            ABORT,
            'financial provider health binding mismatch'
        )
    END;
END
SQL
            );

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER fp_connection_health_insert_guard
BEFORE INSERT ON financial_provider_connection_health_checks
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM financial_provider_connections AS connection
        WHERE connection.id =
            NEW.financial_provider_connection_id
          AND connection.organization_id =
            NEW.organization_id
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'financial provider health organization mismatch';
    END IF;

    IF NEW.financial_provider_connection_compatibility_binding_id
        IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM financial_provider_connection_compatibility_bindings
            AS binding
            WHERE binding.id =
                NEW.financial_provider_connection_compatibility_binding_id
              AND binding.financial_provider_connection_id =
                NEW.financial_provider_connection_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'financial provider health binding mismatch';
    END IF;
END
SQL
            );
        }
    }

    private function installImmutability(): void
    {
        $driver = DB::connection()->getDriverName();
        $table =
            'financial_provider_connection_health_checks';

        if ($driver === 'sqlite') {
            DB::unprepared(
                "CREATE TRIGGER {$table}_immutable_update
                BEFORE UPDATE ON {$table}
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'financial provider health check is immutable'
                    );
                END"
            );

            DB::unprepared(
                "CREATE TRIGGER {$table}_immutable_delete
                BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'financial provider health check is immutable'
                    );
                END"
            );

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(
                "CREATE TRIGGER {$table}_immutable_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'financial provider health check is immutable'"
            );

            DB::unprepared(
                "CREATE TRIGGER {$table}_immutable_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'financial provider health check is immutable'"
            );
        }
    }

    private function dropTriggers(): void
    {
        foreach ([
            'fp_connection_health_insert_guard',
            'financial_provider_connection_health_checks_immutable_update',
            'financial_provider_connection_health_checks_immutable_delete',
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
};
