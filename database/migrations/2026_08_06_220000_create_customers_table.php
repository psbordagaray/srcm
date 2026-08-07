<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')->restrictOnDelete();
            $table->unsignedBigInteger('business_party_id')->unique();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign(
                ['organization_id','business_party_id'],
                'customers_organization_party_foreign'
            )->references(['organization_id','id'])
                ->on('business_parties')->restrictOnDelete();
            $table->index(['organization_id','active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
