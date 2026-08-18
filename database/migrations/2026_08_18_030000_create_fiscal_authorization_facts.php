<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('fiscal_authorization_attempts', function (Blueprint $table): void {
   $table->id(); $table->foreignId('organization_id')->constrained()->restrictOnDelete(); $table->uuid('public_id')->unique(); $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete(); $table->unsignedInteger('attempt_number'); $table->timestamp('requested_at'); $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete(); $table->string('idempotency_key',191); $table->char('fingerprint',64); $table->timestamps();
   $table->unique(['organization_id','idempotency_key']); $table->unique(['fiscal_document_id','attempt_number']);
  });
  Schema::create('fiscal_authorization_responses', function (Blueprint $table): void {
   $table->id(); $table->foreignId('organization_id')->constrained()->restrictOnDelete(); $table->foreignId('fiscal_authorization_attempt_id')->constrained()->restrictOnDelete(); $table->string('outcome',16); $table->string('result_code',100)->nullable(); $table->timestamp('received_at'); $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete(); $table->timestamps(); $table->unique('fiscal_authorization_attempt_id');
  });
  if (DB::getDriverName()==='sqlite') { foreach (['fiscal_authorization_attempts','fiscal_authorization_responses'] as $table) { DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT, 'Fiscal authorization fact is immutable'); END"); DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT, 'Fiscal authorization fact cannot be deleted'); END"); } }
 }
 public function down(): void { if (DB::getDriverName()==='sqlite') { foreach (['fiscal_authorization_attempts','fiscal_authorization_responses'] as $table) { DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update"); DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete"); } } Schema::dropIfExists('fiscal_authorization_responses'); Schema::dropIfExists('fiscal_authorization_attempts'); }
};
