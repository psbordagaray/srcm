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
            'financial_provider_connection_compatibility_bindings',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId(
                    'financial_provider_connection_id'
                )->constrained(
                    'financial_provider_connections'
                )->restrictOnDelete();
                $table->foreignId(
                    'financial_provider_compatibility_id'
                )->constrained(
                    'financial_provider_compatibilities'
                )->restrictOnDelete();
                $table->foreignId(
                    'previous_binding_id'
                )->nullable()
                    ->unique()
                    ->constrained(
                        'financial_provider_connection_compatibility_bindings'
                    )->restrictOnDelete();
                $table->foreignId(
                    'bound_by_user_id'
                )->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('bound_at');
                $table->timestamp('created_at');

                $table->index(
                    [
                        'financial_provider_connection_id',
                        'financial_provider_compatibility_id',
                    ],
                    'fp_connection_compatibility_idx'
                );
            }
        );

        Schema::create(
            'financial_provider_compatibility_retirements',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId(
                    'financial_provider_compatibility_id'
                )->unique()
                    ->constrained(
                        'financial_provider_compatibilities'
                    )->restrictOnDelete();
                $table->string('reason', 500);
                $table->string('srcm_version', 120);
                $table->timestamp('retired_at');
                $table->timestamp('created_at');
            }
        );

        $this->installBindingGuards();
        $this->installRetirementGuards();
        $this->installImmutability();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists(
            'financial_provider_compatibility_retirements'
        );
        Schema::dropIfExists(
            'financial_provider_connection_compatibility_bindings'
        );
    }

    private function installBindingGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER fp_connection_compatibility_binding_guard
BEFORE INSERT ON financial_provider_connection_compatibility_bindings
BEGIN
    SELECT CASE
        WHEN (
            SELECT provider_key
            FROM financial_provider_connections
            WHERE id = NEW.financial_provider_connection_id
        ) <> (
            SELECT provider_key
            FROM financial_provider_compatibilities
            WHERE id = NEW.financial_provider_compatibility_id
        )
        THEN RAISE(
            ABORT,
            'financial provider compatibility provider mismatch'
        )
    END;

    SELECT CASE
        WHEN EXISTS (
            SELECT 1
            FROM financial_provider_compatibility_retirements
            WHERE financial_provider_compatibility_id =
                NEW.financial_provider_compatibility_id
        )
        THEN RAISE(
            ABORT,
            'retired financial provider compatibility cannot be bound'
        )
    END;

    SELECT CASE
        WHEN NEW.previous_binding_id IS NULL
            AND EXISTS (
                SELECT 1
                FROM financial_provider_connection_compatibility_bindings
                WHERE financial_provider_connection_id =
                    NEW.financial_provider_connection_id
            )
        THEN RAISE(
            ABORT,
            'compatibility binding chain requires previous binding'
        )
    END;

    SELECT CASE
        WHEN NEW.previous_binding_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM financial_provider_connection_compatibility_bindings
                WHERE id = NEW.previous_binding_id
                  AND financial_provider_connection_id =
                    NEW.financial_provider_connection_id
            )
        THEN RAISE(
            ABORT,
            'compatibility previous binding belongs to another connection'
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
CREATE TRIGGER fp_connection_compatibility_binding_guard
BEFORE INSERT ON financial_provider_connection_compatibility_bindings
FOR EACH ROW
BEGIN
    IF (
        SELECT provider_key
        FROM financial_provider_connections
        WHERE id = NEW.financial_provider_connection_id
    ) <> (
        SELECT provider_key
        FROM financial_provider_compatibilities
        WHERE id = NEW.financial_provider_compatibility_id
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'financial provider compatibility provider mismatch';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM financial_provider_compatibility_retirements
        WHERE financial_provider_compatibility_id =
            NEW.financial_provider_compatibility_id
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'retired financial provider compatibility cannot be bound';
    END IF;

    IF NEW.previous_binding_id IS NULL
        AND EXISTS (
            SELECT 1
            FROM financial_provider_connection_compatibility_bindings
            WHERE financial_provider_connection_id =
                NEW.financial_provider_connection_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'compatibility binding chain requires previous binding';
    END IF;

    IF NEW.previous_binding_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM financial_provider_connection_compatibility_bindings
            WHERE id = NEW.previous_binding_id
              AND financial_provider_connection_id =
                NEW.financial_provider_connection_id
        )
    THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'compatibility previous binding belongs to another connection';
    END IF;
END
SQL
            );
        }
    }

    private function installRetirementGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
CREATE TRIGGER fp_compatibility_retirement_active_guard
BEFORE INSERT ON financial_provider_compatibility_retirements
BEGIN
    SELECT CASE
        WHEN EXISTS (
            SELECT 1
            FROM financial_provider_connection_compatibility_bindings AS binding
            INNER JOIN financial_provider_connections AS connection
                ON connection.id =
                    binding.financial_provider_connection_id
            WHERE binding.financial_provider_compatibility_id =
                NEW.financial_provider_compatibility_id
              AND connection.active = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM financial_provider_connection_compatibility_bindings AS successor
                  WHERE successor.previous_binding_id = binding.id
              )
        )
        THEN RAISE(
            ABORT,
            'active connection depends on financial provider compatibility'
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
CREATE TRIGGER fp_compatibility_retirement_active_guard
BEFORE INSERT ON financial_provider_compatibility_retirements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM financial_provider_connection_compatibility_bindings AS binding
        INNER JOIN financial_provider_connections AS connection
            ON connection.id =
                binding.financial_provider_connection_id
        WHERE binding.financial_provider_compatibility_id =
            NEW.financial_provider_compatibility_id
          AND connection.active = 1
          AND NOT EXISTS (
              SELECT 1
              FROM financial_provider_connection_compatibility_bindings AS successor
              WHERE successor.previous_binding_id = binding.id
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'active connection depends on financial provider compatibility';
    END IF;
END
SQL
            );
        }
    }

    private function installImmutability(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach ([
                'financial_provider_connection_compatibility_bindings',
                'financial_provider_compatibility_retirements',
            ] as $table) {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_update
                    BEFORE UPDATE ON {$table}
                    BEGIN
                        SELECT RAISE(
                            ABORT,
                            'financial provider compatibility lifecycle is immutable'
                        );
                    END"
                );

                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_delete
                    BEFORE DELETE ON {$table}
                    BEGIN
                        SELECT RAISE(
                            ABORT,
                            'financial provider compatibility lifecycle is immutable'
                        );
                    END"
                );
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ([
                'financial_provider_connection_compatibility_bindings',
                'financial_provider_compatibility_retirements',
            ] as $table) {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_update
                    BEFORE UPDATE ON {$table}
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT =
                        'financial provider compatibility lifecycle is immutable'"
                );

                DB::unprepared(
                    "CREATE TRIGGER {$table}_immutable_delete
                    BEFORE DELETE ON {$table}
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT =
                        'financial provider compatibility lifecycle is immutable'"
                );
            }
        }
    }

    private function dropTriggers(): void
    {
        foreach ([
            'fp_connection_compatibility_binding_guard',
            'fp_compatibility_retirement_active_guard',
            'financial_provider_connection_compatibility_bindings_immutable_update',
            'financial_provider_connection_compatibility_bindings_immutable_delete',
            'financial_provider_compatibility_retirements_immutable_update',
            'financial_provider_compatibility_retirements_immutable_delete',
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
