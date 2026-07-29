<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identifiers', function (Blueprint $table) {
            $table->string('normalized_value', 255)
                ->nullable()
                ->after('value');

            $table->index(
                ['identifier_type_id', 'normalized_value'],
                'identifiers_type_normalized_index'
            );

            $table->index(
                [
                    'entity_id',
                    'identifier_type_id',
                    'normalized_value',
                ],
                'identifiers_entity_type_normalized_index'
            );
        });

        DB::table('identifiers')
            ->select(['id', 'value'])
            ->orderBy('id')
            ->chunkById(250, function ($identifiers): void {
                foreach ($identifiers as $identifier) {
                    DB::table('identifiers')
                        ->where('id', $identifier->id)
                        ->update([
                            'value' => trim((string) $identifier->value),
                            'normalized_value' => mb_strtolower(
                                trim((string) $identifier->value)
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('identifiers', function (Blueprint $table) {
            $table->dropIndex(
                'identifiers_entity_type_normalized_index'
            );

            $table->dropIndex(
                'identifiers_type_normalized_index'
            );

            $table->dropColumn('normalized_value');
        });
    }
};
