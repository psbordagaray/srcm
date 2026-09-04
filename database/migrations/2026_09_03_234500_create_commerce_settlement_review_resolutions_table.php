<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'commerce_settlement_review_resolutions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->foreignId(
                    'commerce_settlement_review_id'
                )
                    ->constrained('commerce_settlement_reviews')
                    ->restrictOnDelete();
                $table->string('outcome', 64);
                $table->text('reason');
                $table->text('notes')->nullable();
                $table->foreignId('resolved_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('resolved_at');
                $table->string('idempotency_key', 180);
                $table->string('fingerprint', 64);
                $table->timestamp('created_at');

                $table->unique(
                    'commerce_settlement_review_id',
                    'commerce_settlement_review_resolutions_review_unique'
                );

                $table->unique(
                    [
                        'organization_id',
                        'idempotency_key',
                    ],
                    'commerce_settlement_review_resolutions_org_key_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'resolved_at',
                    ],
                    'commerce_settlement_review_resolutions_org_resolved_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'commerce_settlement_review_resolutions'
        );
    }
};
