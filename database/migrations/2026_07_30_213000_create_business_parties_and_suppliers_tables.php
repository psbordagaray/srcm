<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'business_parties',
            function (Blueprint $table): void {
                $table->id();
                $table->string('party_type', 20);
                $table->string('name');
                $table->string('normalized_name');
                $table->string('tax_id', 64)->nullable();
                $table->string('normalized_tax_id', 64)
                    ->nullable()
                    ->unique();
                $table->string('email')->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('website', 2048)->nullable();
                $table->timestamps();

                $table->index('name');
                $table->index('email');
                $table->index([
                    'party_type',
                    'normalized_name',
                ], 'business_parties_identity_index');
            }
        );

        Schema::create(
            'suppliers',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('business_party_id')
                    ->unique()
                    ->constrained('business_parties')
                    ->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index('active');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('business_parties');
    }
};
