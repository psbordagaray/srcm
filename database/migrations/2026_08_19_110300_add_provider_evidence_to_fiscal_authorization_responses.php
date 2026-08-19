<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'fiscal_authorization_responses',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'authorization_code',
                        191
                    )
                    ->nullable();

                $table
                    ->date(
                        'authorization_code_expires_on'
                    )
                    ->nullable();

                $table
                    ->json(
                        'provider_evidence'
                    )
                    ->nullable();
            }
        );
    }

    public function down(): void
    {
        $sqlite =
            DB::getDriverName() === 'sqlite';

        if ($sqlite) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS fiscal_authorization_responses_immutable_update'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS fiscal_authorization_responses_immutable_delete'
            );
        }

        Schema::table(
            'fiscal_authorization_responses',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'authorization_code',
                    'authorization_code_expires_on',
                    'provider_evidence',
                ]);
            }
        );

        if ($sqlite) {
            DB::unprepared(
                "CREATE TRIGGER fiscal_authorization_responses_immutable_update
                BEFORE UPDATE ON fiscal_authorization_responses
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'Fiscal authorization fact is immutable'
                    );
                END"
            );

            DB::unprepared(
                "CREATE TRIGGER fiscal_authorization_responses_immutable_delete
                BEFORE DELETE ON fiscal_authorization_responses
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'Fiscal authorization fact cannot be deleted'
                    );
                END"
            );
        }
    }
};
