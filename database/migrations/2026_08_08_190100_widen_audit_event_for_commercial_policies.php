<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_LENGTH = 30;
    private const NEW_LENGTH = 64;

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite does not enforce VARCHAR(n) length. Avoid rebuilding the
            // audit table merely to change declared metadata.
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE audit_logs MODIFY event VARCHAR('.self::NEW_LENGTH.') NOT NULL'
            );

            return;
        }

        throw new LogicException(
            "La ampliación de audit_logs.event no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $hasLongEvents = DB::table('audit_logs')
                ->whereRaw('CHAR_LENGTH(event) > ?', [self::LEGACY_LENGTH])
                ->exists();

            if ($hasLongEvents) {
                throw new LogicException(
                    'No se puede reducir audit_logs.event a 30 caracteres porque existen eventos más largos.'
                );
            }

            DB::statement(
                'ALTER TABLE audit_logs MODIFY event VARCHAR('.self::LEGACY_LENGTH.') NOT NULL'
            );

            return;
        }

        throw new LogicException(
            "La reversión de audit_logs.event no está implementada para {$driver}."
        );
    }
};
