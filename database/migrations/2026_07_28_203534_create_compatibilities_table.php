<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compatibilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('left_entity_id')
                ->constrained('entities')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('right_entity_id')
                ->constrained('entities')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('relationship_type', 50)
                ->default('compatible_with');

            $table->unsignedTinyInteger('confidence')
                ->default(50);

            $table->string('source')
                ->nullable();

            $table->text('evidence')
                ->nullable();

            $table->boolean('active')
                ->default(true);

            $table->timestamps();

            $table->index([
                'left_entity_id',
                'relationship_type',
            ]);

            $table->index([
                'right_entity_id',
                'relationship_type',
            ]);

            $table->index('active');

            $table->unique(
                [
                    'left_entity_id',
                    'right_entity_id',
                    'relationship_type',
                ],
                'compatibilities_relation_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compatibilities');
    }
};