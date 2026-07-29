<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->uuid('request_id')->nullable()->index();

            $table->unsignedBigInteger('user_id')
                ->nullable()
                ->index();

            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 30)->nullable();

            $table->string('event', 30)->index();
            $table->string('auditable_type', 180);
            $table->string('auditable_id', 64);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('route_name')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->text('url_path')->nullable();

            $table->timestamp('created_at')
                ->useCurrent()
                ->index();

            $table->index(
                ['auditable_type', 'auditable_id'],
                'audit_logs_auditable_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
