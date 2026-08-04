<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'service_warranty_grants',
            function (Blueprint $table): void {
                $table->unique(
                    ['organization_id', 'id'],
                    'srv_warranties_org_id_unique'
                );
            }
        );

        Schema::create(
            'service_warranty_claims',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger('service_warranty_grant_id');
                $table->unsignedBigInteger('open_warranty_grant_id')
                    ->nullable();
                $table->unsignedBigInteger('original_service_order_id');
                $table->unsignedBigInteger('original_service_delivery_id');
                $table->unsignedBigInteger('corrective_service_order_id');
                $table->unsignedBigInteger('claimant_business_party_id')
                    ->nullable();
                $table->string('claimant_name');
                $table->string('channel', 100);
                $table->string('customer_reference')->nullable();
                $table->text('reported_issue');
                $table->text('reentry_condition_notes');
                $table->text('accessories_snapshot');
                $table->string('warranty_status_at_claim', 20);
                $table->timestampTz('claimed_at');
                $table->timestampTz('received_at');
                $table->foreignId('received_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('intake_location_id');
                $table->string('status', 40)
                    ->default('pending_review');
                $table->timestampTz('closed_at')->nullable();
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_warranty_claims_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'corrective_service_order_id'],
                    'srv_warranty_claims_corrective_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_warranty_claims_org_idem_unique'
                );
                $table->unique(
                    ['organization_id', 'open_warranty_grant_id'],
                    'srv_warranty_claims_open_unique'
                );
                $table->index(
                    ['organization_id', 'service_warranty_grant_id', 'status'],
                    'srv_warranty_claims_grant_status_idx'
                );
                $table->index(
                    ['organization_id', 'original_service_order_id'],
                    'srv_warranty_claims_origin_idx'
                );
                $table->foreign(
                    ['organization_id', 'service_warranty_grant_id'],
                    'srv_warranty_claims_grant_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_warranty_grants')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'open_warranty_grant_id'],
                    'srv_warranty_claims_open_grant_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_warranty_grants')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'original_service_order_id'],
                    'srv_warranty_claims_origin_order_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'original_service_delivery_id'],
                    'srv_warranty_claims_origin_delivery_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_deliveries')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'corrective_service_order_id'],
                    'srv_warranty_claims_corrective_order_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'claimant_business_party_id'],
                    'srv_warranty_claims_claimant_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('business_parties')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'intake_location_id'],
                    'srv_warranty_claims_location_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_locations')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_warranty_claim_status_histories',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_warranty_claim_id');
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->foreignId('changed_by_user_id');
                $table->text('reason');
                $table->timestampTz('changed_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_warranty_claim_hist_idem_unique'
                );
                $table->index(
                    [
                        'organization_id',
                        'service_warranty_claim_id',
                        'changed_at',
                    ],
                    'srv_warranty_claim_hist_claim_idx'
                );
                $table->foreign(
                    ['organization_id', 'service_warranty_claim_id'],
                    'srv_warranty_claim_hist_claim_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_warranty_claims')
                    ->restrictOnDelete();
                $table->foreign(
                    'changed_by_user_id',
                    'srv_warranty_claim_hist_actor_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_warranty_claim_resolutions',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_warranty_claim_id');
                $table->string('outcome', 30);
                $table->text('technical_basis');
                $table->text('covered_scope')->nullable();
                $table->text('excluded_scope')->nullable();
                $table->string('warranty_status_at_resolution', 20);
                $table->boolean('administrative_exception')
                    ->default(false);
                $table->text('exception_reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('resolved_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('resolved_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_warranty_resolutions_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'service_warranty_claim_id'],
                    'srv_warranty_resolutions_claim_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_warranty_resolutions_idem_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_warranty_claim_id'],
                    'srv_warranty_resolutions_claim_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_warranty_claims')
                    ->restrictOnDelete();
            }
        );

        Schema::create(
            'service_warranty_claim_returns',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('service_warranty_claim_id');
                $table->unsignedBigInteger(
                    'service_warranty_claim_resolution_id'
                );
                $table->unsignedBigInteger('corrective_service_order_id');
                $table->unsignedBigInteger('service_custody_event_id');
                $table->unsignedBigInteger('recipient_business_party_id')
                    ->nullable();
                $table->string('recipient_name');
                $table->string('recipient_document')->nullable();
                $table->text('condition_notes');
                $table->text('accessories_snapshot');
                $table->text('notes')->nullable();
                $table->foreignId('returned_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestampTz('returned_at');
                $table->string('idempotency_key', 100);
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'srv_warranty_returns_org_id_unique'
                );
                $table->unique(
                    ['organization_id', 'service_warranty_claim_id'],
                    'srv_warranty_returns_claim_unique'
                );
                $table->unique(
                    [
                        'organization_id',
                        'service_warranty_claim_resolution_id',
                    ],
                    'srv_warranty_returns_resolution_unique'
                );
                $table->unique(
                    ['organization_id', 'corrective_service_order_id'],
                    'srv_warranty_returns_order_unique'
                );
                $table->unique(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_warranty_returns_custody_unique'
                );
                $table->unique(
                    ['organization_id', 'idempotency_key'],
                    'srv_warranty_returns_idem_unique'
                );
                $table->foreign(
                    ['organization_id', 'service_warranty_claim_id'],
                    'srv_warranty_returns_claim_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_warranty_claims')
                    ->restrictOnDelete();
                $table->foreign(
                    [
                        'organization_id',
                        'service_warranty_claim_resolution_id',
                    ],
                    'srv_warranty_returns_resolution_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_warranty_claim_resolutions')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'corrective_service_order_id'],
                    'srv_warranty_returns_order_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_orders')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'service_custody_event_id'],
                    'srv_warranty_returns_custody_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('service_custody_events')
                    ->restrictOnDelete();
                $table->foreign(
                    ['organization_id', 'recipient_business_party_id'],
                    'srv_warranty_returns_recipient_fk'
                )
                    ->references(['organization_id', 'id'])
                    ->on('business_parties')
                    ->restrictOnDelete();
            }
        );

        $dependentTriggers = $this->suspendSqliteDependentTriggers();

        try {
            Schema::table(
                'service_work_items',
                function (Blueprint $table): void {
                    $table->unsignedBigInteger('service_quote_option_id')
                        ->nullable()
                        ->change();
                    $table->unsignedBigInteger(
                        'service_warranty_claim_resolution_id'
                    )
                        ->nullable()
                        ->after('service_quote_option_id');
                    $table->foreign(
                        [
                            'organization_id',
                            'service_warranty_claim_resolution_id',
                        ],
                        'srv_work_items_warranty_resolution_fk'
                    )
                        ->references(['organization_id', 'id'])
                        ->on('service_warranty_claim_resolutions')
                        ->restrictOnDelete();
                }
            );

            Schema::table(
                'service_part_requirements',
                function (Blueprint $table): void {
                    $table->unsignedBigInteger('service_quote_line_id')
                        ->nullable()
                        ->change();
                    $table->unsignedBigInteger(
                        'service_warranty_claim_resolution_id'
                    )
                        ->nullable()
                        ->after('service_quote_line_id');
                    $table->foreign(
                        [
                            'organization_id',
                            'service_warranty_claim_resolution_id',
                        ],
                        'srv_part_req_warranty_resolution_fk'
                    )
                        ->references(['organization_id', 'id'])
                        ->on('service_warranty_claim_resolutions')
                        ->restrictOnDelete();
                }
            );
        } finally {
            $this->restoreSqliteTriggers($dependentTriggers);
        }
    }

    public function down(): void
    {
        $dependentTriggers = $this->suspendSqliteDependentTriggers();

        try {
            Schema::table(
                'service_part_requirements',
                function (Blueprint $table): void {
                    $table->dropForeign(
                        'srv_part_req_warranty_resolution_fk'
                    );
                    $table->dropColumn(
                        'service_warranty_claim_resolution_id'
                    );
                    $table->unsignedBigInteger('service_quote_line_id')
                        ->nullable(false)
                        ->change();
                }
            );

            Schema::table(
                'service_work_items',
                function (Blueprint $table): void {
                    $table->dropForeign(
                        'srv_work_items_warranty_resolution_fk'
                    );
                    $table->dropColumn(
                        'service_warranty_claim_resolution_id'
                    );
                    $table->unsignedBigInteger('service_quote_option_id')
                        ->nullable(false)
                        ->change();
                }
            );
        } finally {
            $this->restoreSqliteTriggers($dependentTriggers);
        }

        Schema::dropIfExists('service_warranty_claim_returns');
        Schema::dropIfExists('service_warranty_claim_resolutions');
        Schema::dropIfExists(
            'service_warranty_claim_status_histories'
        );
        Schema::dropIfExists('service_warranty_claims');

        Schema::table(
            'service_warranty_grants',
            function (Blueprint $table): void {
                $table->dropUnique('srv_warranties_org_id_unique');
            }
        );
    }

    /**
     * SQLite rebuilds altered tables and validates every trigger body while
     * renaming the temporary table. Triggers attached to other service tables
     * may reference the rebuilt tables, so their exact SQL must be suspended
     * and replayed around the schema change.
     *
     * @return list<array{name: string, sql: string}>
     */
    private function suspendSqliteDependentTriggers(): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        $rows = DB::select(<<<'SQL'
SELECT name, sql
FROM sqlite_master
WHERE type = 'trigger'
    AND sql IS NOT NULL
    AND (
        lower(sql) LIKE '%service_work_items%'
        OR lower(sql) LIKE '%service_part_requirements%'
    )
ORDER BY name
SQL);
        $triggers = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $sql = trim((string) $row->sql);

            if ($name === '' || $sql === '') {
                continue;
            }

            $triggers[] = [
                'name' => $name,
                'sql' => $sql,
            ];
            $quotedName = '"'.str_replace('"', '""', $name).'"';

            DB::unprepared("DROP TRIGGER IF EXISTS {$quotedName}");
        }

        return $triggers;
    }

    /**
     * @param  list<array{name: string, sql: string}>  $triggers
     */
    private function restoreSqliteTriggers(array $triggers): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach ($triggers as $trigger) {
            DB::unprepared($trigger['sql']);
        }
    }
};
