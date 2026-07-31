<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'inventory_locations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')
                    ->constrained('organizations')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('parent_id')
                    ->nullable();
                $table->string('name');
                $table->string('normalized_name');
                $table->string('type', 40);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'id'],
                    'inventory_locations_organization_id_unique'
                );

                $table->foreign(
                    ['organization_id', 'parent_id'],
                    'inventory_locations_organization_parent_foreign'
                )
                    ->references(['organization_id', 'id'])
                    ->on('inventory_locations')
                    ->restrictOnDelete();

                $table->index(
                    ['organization_id', 'parent_id', 'active'],
                    'inventory_locations_organization_parent_index'
                );

                $table->index(
                    ['organization_id', 'type', 'active'],
                    'inventory_locations_organization_type_index'
                );

                $table->index(
                    ['organization_id', 'normalized_name'],
                    'inventory_locations_organization_name_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_locations');
    }
};
