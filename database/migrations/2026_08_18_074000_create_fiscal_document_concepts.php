<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_document_concepts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->string('concept', 32);
            $table->date('service_period_from')->nullable();
            $table->date('service_period_to')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('fiscal_document_id');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER fiscal_document_concepts_immutable_update BEFORE UPDATE ON fiscal_document_concepts BEGIN SELECT RAISE(ABORT, 'Fiscal document concept is immutable'); END");
            DB::unprepared("CREATE TRIGGER fiscal_document_concepts_immutable_delete BEFORE DELETE ON fiscal_document_concepts BEGIN SELECT RAISE(ABORT, 'Fiscal document concept cannot be deleted'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_concepts');
    }
};
