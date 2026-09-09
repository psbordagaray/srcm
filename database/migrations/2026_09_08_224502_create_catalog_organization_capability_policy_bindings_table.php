<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'catalog_organization_capability_policy_bindings',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger(
                    'semantic_capability_definition_id'
                );
                $table->boolean('enabled');
                $table->timestamps();

                $table->foreign(
                    'organization_id',
                    'cocpb_organization_fk'
                )
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign(
                    'semantic_capability_definition_id',
                    'cocpb_capability_fk'
                )
                    ->references('id')
                    ->on('catalog_semantic_capability_definitions')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'organization_id',
                        'semantic_capability_definition_id',
                    ],
                    'cocpb_org_capability_unique'
                );

                $table->index(
                    [
                        'semantic_capability_definition_id',
                        'organization_id',
                    ],
                    'cocpb_capability_org_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'catalog_organization_capability_policy_bindings'
        );
    }
};
