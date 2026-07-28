<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identifiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entity_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('identifier_type_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('value', 255);

            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index('value');
            $table->index(['identifier_type_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identifiers');
    }
};
