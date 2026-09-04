<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'commerce_settlement_reviews',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->string(
                    'checkout_idempotency_key',
                    255
                );
                $table->string('review_fingerprint', 64);
                $table->bigInteger('system_total_minor');
                $table->bigInteger('settled_total_minor');
                $table->string('decision', 32);
                $table->bigInteger('final_value_minor');
                $table->text('reason');
                $table->string('warning_code', 128);
                $table->json('runtime_evidence_snapshot');
                $table->json('decision_evidence_snapshot');
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('requested_at');
                $table->timestamp('created_at');

                $table->unique(
                    [
                        'organization_id',
                        'checkout_idempotency_key',
                    ],
                    'commerce_settlement_reviews_org_checkout_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'requested_at',
                    ],
                    'commerce_settlement_reviews_org_requested_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'commerce_settlement_reviews'
        );
    }
};
