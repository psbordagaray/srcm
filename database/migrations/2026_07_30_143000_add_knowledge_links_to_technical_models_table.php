<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_models', function (Blueprint $table): void {
            $table->foreignId('knowledge_entity_id')
                ->nullable()
                ->after('id')
                ->unique()
                ->constrained('entities')
                ->restrictOnDelete();

            $table->foreignId('knowledge_identifier_id')
                ->nullable()
                ->after('knowledge_entity_id')
                ->unique()
                ->constrained('identifiers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('technical_models', function (Blueprint $table): void {
            $table->dropConstrainedForeignId(
                'knowledge_identifier_id'
            );

            $table->dropConstrainedForeignId(
                'knowledge_entity_id'
            );
        });
    }
};
