<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(
            'fiscal_document_tax_wsfe_identities',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->foreignId('fiscal_document_id')
                    ->constrained('fiscal_documents')
                    ->cascadeOnDelete();
                $table->foreignId('fiscal_document_tax_id')
                    ->constrained('fiscal_document_taxes')
                    ->cascadeOnDelete();
                $table->string('bucket', 16);
                $table->unsignedInteger('arca_id');
                $table->string(
                    'tribute_description',
                    80
                )->nullable();
                $table->timestamp('recorded_at');
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->unique(
                    'fiscal_document_tax_id',
                    'fiscal_tax_wsfe_identity_tax_unique'
                );

                $table->index(
                    [
                        'organization_id',
                        'fiscal_document_id',
                    ],
                    'fiscal_tax_wsfe_identity_org_document_idx'
                );
            }
        );

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared(
            "CREATE TRIGGER fiscal_tax_wsfe_identity_tenant_insert
            BEFORE INSERT ON fiscal_document_tax_wsfe_identities
            BEGIN
                SELECT CASE
                    WHEN NOT EXISTS (
                        SELECT 1
                        FROM fiscal_document_taxes AS tax
                        WHERE tax.id = NEW.fiscal_document_tax_id
                          AND tax.organization_id = NEW.organization_id
                          AND tax.fiscal_document_id = NEW.fiscal_document_id
                    )
                    THEN RAISE(
                        ABORT,
                        'Fiscal tax WSFE identity tenant/document mismatch'
                    )
                END;
            END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_tax_wsfe_identity_auth_order_insert
            BEFORE INSERT ON fiscal_document_tax_wsfe_identities
            BEGIN
                SELECT CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM fiscal_authorization_attempts AS attempt
                        WHERE attempt.fiscal_document_id = NEW.fiscal_document_id
                    )
                    THEN RAISE(
                        ABORT,
                        'Fiscal tax WSFE identity cannot be added after authorization attempt'
                    )
                END;
            END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_tax_wsfe_identity_shape_insert
            BEFORE INSERT ON fiscal_document_tax_wsfe_identities
            BEGIN
                SELECT CASE
                    WHEN NEW.bucket NOT IN ('IVA', 'TRIBUTO')
                      OR NEW.arca_id < 1
                      OR NEW.arca_id > 99
                      OR (
                          NEW.bucket = 'IVA'
                          AND NEW.tribute_description IS NOT NULL
                      )
                      OR (
                          NEW.tribute_description IS NOT NULL
                          AND (
                              length(trim(NEW.tribute_description)) = 0
                              OR length(NEW.tribute_description) > 80
                          )
                      )
                    THEN RAISE(
                        ABORT,
                        'Invalid Fiscal tax WSFE identity shape'
                    )
                END;
            END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_tax_wsfe_identity_immutable_update
            BEFORE UPDATE ON fiscal_document_tax_wsfe_identities
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'Fiscal tax WSFE identity is immutable'
                );
            END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_tax_wsfe_identity_immutable_delete
            BEFORE DELETE ON fiscal_document_tax_wsfe_identities
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'Fiscal tax WSFE identity cannot be deleted'
                );
            END"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                'fiscal_tax_wsfe_identity_tenant_insert',
                'fiscal_tax_wsfe_identity_auth_order_insert',
                'fiscal_tax_wsfe_identity_shape_insert',
                'fiscal_tax_wsfe_identity_immutable_update',
                'fiscal_tax_wsfe_identity_immutable_delete',
            ] as $trigger) {
                DB::unprepared(
                    "DROP TRIGGER IF EXISTS {$trigger}"
                );
            }
        }

        Schema::dropIfExists(
            'fiscal_document_tax_wsfe_identities'
        );
    }
};
