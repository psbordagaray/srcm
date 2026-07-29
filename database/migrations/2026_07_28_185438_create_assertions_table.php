<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assertions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entity_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('attribute', 100);
            $table->string('value_type', 30)->default('text');
            $table->text('value_text')->nullable();

            $table->foreignId('related_entity_id')
                ->nullable()
                ->constrained('entities')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->json('value_json')->nullable();

            $table->unsignedTinyInteger('confidence')->default(50);
            $table->string('status', 30)->default('discovered');

            $table->string('source')->nullable();
            $table->text('evidence')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['entity_id', 'attribute']);
            $table->index('attribute');
            $table->index('related_entity_id');
            $table->index('status');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assertions');
    }
};
