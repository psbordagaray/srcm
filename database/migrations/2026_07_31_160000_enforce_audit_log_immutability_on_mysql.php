<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            return;
        }

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER audit_logs_prevent_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'Los registros de auditoría no pueden modificarse.'
SQL
        );

        DB::unprepared(
            <<<'SQL'
CREATE TRIGGER audit_logs_prevent_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'Los registros de auditoría no pueden eliminarse.'
SQL
        );
    }

    public function down(): void
    {
        if (! in_array(
            DB::getDriverName(),
            ['mysql', 'mariadb'],
            true
        )) {
            return;
        }

        DB::unprepared(
            'DROP TRIGGER IF EXISTS audit_logs_prevent_update'
        );

        DB::unprepared(
            'DROP TRIGGER IF EXISTS audit_logs_prevent_delete'
        );
    }
};
