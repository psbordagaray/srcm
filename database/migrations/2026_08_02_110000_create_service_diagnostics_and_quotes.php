<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->unsignedBigInteger('service_order_id');
            $table->unsignedInteger('revision');
            $table->text('summary');
            $table->text('recommendation');
            $table->text('data_risk_notes')->nullable();
            $table->foreignId('diagnosed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('diagnosed_at');
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'srv_diagnostics_org_id_unique'
            );
            $table->unique(
                ['organization_id', 'service_order_id', 'revision'],
                'srv_diagnostics_order_revision_unique'
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'srv_diagnostics_org_idempotency_unique'
            );
            $table->foreign(
                ['organization_id', 'service_order_id'],
                'srv_diagnostics_order_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_orders')
                ->restrictOnDelete();
        });

        Schema::create(
            'service_diagnostic_findings',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_diagnostic_id');
                $table->unsignedInteger('position');
                $table->string('severity', 30);
                $table->string('category', 100);
                $table->text('description');
                $table->text('evidence_notes')->nullable();
                $table->timestamps();

                $table->unique(
                    [
                        'organization_id',
                        'service_diagnostic_id',
                        'position',
                    ],
                    'srv_findings_diagnostic_position_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_diagnostic_id'],
                    'srv_findings_diagnostic_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_diagnostics')
                    ->restrictOnDelete();
            }
        );

        Schema::create('service_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->unsignedBigInteger('service_order_id');
            $table->unsignedBigInteger('service_diagnostic_id');
            $table->unsignedInteger('revision');
            $table->char('currency_code', 3);
            $table->timestampTz('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('issued_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'srv_quotes_org_id_unique'
            );
            $table->unique(
                ['organization_id', 'service_order_id', 'revision'],
                'srv_quotes_order_revision_unique'
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'srv_quotes_org_idempotency_unique'
            );
            $table->foreign(
                ['organization_id', 'service_order_id'],
                'srv_quotes_order_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_orders')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'service_diagnostic_id'],
                'srv_quotes_diagnostic_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_diagnostics')
                ->restrictOnDelete();
        });

        Schema::create(
            'service_quote_options',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_quote_id');
                $table->unsignedInteger('option_number');
                $table->string('label');
                $table->text('description')->nullable();
                $table->boolean('recommended')->default(false);
                $table->unsignedBigInteger('total_minor');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_quote_options_org_id_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'service_quote_id',
                        'option_number',
                    ],
                    'srv_quote_options_quote_number_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_quote_id'],
                    'srv_quote_options_quote_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_quotes')
                    ->restrictOnDelete();
            }
        );

        Schema::create('service_quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->unsignedBigInteger('service_quote_option_id');
            $table->unsignedInteger('position');
            $table->string('line_type', 40);
            $table->text('description');
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();

            $table->unique(
                [
                    'organization_id',
                    'service_quote_option_id',
                    'position',
                ],
                'srv_quote_lines_option_position_unique'
            );
            $table->foreign(
                ['organization_id', 'service_quote_option_id'],
                'srv_quote_lines_option_org_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('service_quote_options')
                ->restrictOnDelete();
        });

        Schema::create(
            'service_quote_decisions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_quote_id');
                $table->unsignedBigInteger('service_quote_option_id')
                    ->nullable();
                $table->string('decision', 30);
                $table->string('customer_name');
                $table->string('customer_reference')->nullable();
                $table->string('channel', 50);
                $table->text('reason')->nullable();
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('decided_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'service_quote_id'],
                    'srv_quote_decisions_quote_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_quote_decisions_idempotency_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_quote_id'],
                    'srv_quote_decisions_quote_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_quotes')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_quote_option_id'],
                    'srv_quote_decisions_option_org_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_quote_options')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_quote_decisions');
        Schema::dropIfExists('service_quote_lines');
        Schema::dropIfExists('service_quote_options');
        Schema::dropIfExists('service_quotes');
        Schema::dropIfExists('service_diagnostic_findings');
        Schema::dropIfExists('service_diagnostics');
    }
};
