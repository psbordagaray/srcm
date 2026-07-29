<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identifier_types', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->string('description')->nullable();

            $table->boolean('is_unique')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identifier_types');
    }
};
