<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'operational_attention_receipts',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
                $table->char('attention_key', 64);
                $table->string('source_type', 80);
                $table->string('source_public_id', 80);
                $table->timestamp('acknowledged_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'user_id',
                        'attention_key',
                    ],
                    'operational_attention_receipts_unique'
                );

                $table->index(
                    ['organization_id', 'user_id'],
                    'operational_attention_receipts_actor_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_attention_receipts');
    }
};
