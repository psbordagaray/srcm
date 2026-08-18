<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('fiscal_organization_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_point_of_sale_id')->constrained('fiscal_points_of_sale')->restrictOnDelete();
            $table->foreignId('commerce_sale_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 32);
            $table->json('issuer_snapshot');
            $table->json('recipient_snapshot');
            $table->string('currency_code', 3);
            $table->bigInteger('service_subtotal_minor')->default(0);
            $table->bigInteger('product_subtotal_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->timestamp('documented_at');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 191);
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'commerce_sale_id', 'document_type'], 'fiscal_documents_sale_type_unique');
            $table->index(['organization_id', 'documented_at']);
        });
        Schema::create('fiscal_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('commerce_sale_line_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('line_type', 32);
            $table->string('description');
            $table->decimal('quantity', 18, 6);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            $table->timestamp('created_at');
            $table->unique(['fiscal_document_id', 'position']);
            $table->unique(['fiscal_document_id', 'commerce_sale_line_id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER fiscal_documents_immutable_update BEFORE UPDATE ON fiscal_documents BEGIN SELECT RAISE(ABORT, 'Fiscal document is immutable'); END");
            DB::unprepared("CREATE TRIGGER fiscal_documents_immutable_delete BEFORE DELETE ON fiscal_documents BEGIN SELECT RAISE(ABORT, 'Fiscal document cannot be deleted'); END");
            DB::unprepared("CREATE TRIGGER fiscal_document_lines_immutable_update BEFORE UPDATE ON fiscal_document_lines BEGIN SELECT RAISE(ABORT, 'Fiscal document line is immutable'); END");
            DB::unprepared("CREATE TRIGGER fiscal_document_lines_immutable_delete BEFORE DELETE ON fiscal_document_lines BEGIN SELECT RAISE(ABORT, 'Fiscal document line cannot be deleted'); END");
        }
    }
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['fiscal_documents_immutable_update', 'fiscal_documents_immutable_delete', 'fiscal_document_lines_immutable_update', 'fiscal_document_lines_immutable_delete'] as $trigger) { DB::unprepared("DROP TRIGGER IF EXISTS $trigger"); }
        }
        Schema::dropIfExists('fiscal_document_lines');
        Schema::dropIfExists('fiscal_documents');
    }
};
