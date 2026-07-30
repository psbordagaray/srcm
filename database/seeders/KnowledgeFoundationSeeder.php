<?php

namespace Database\Seeders;

use App\Models\EntityType;
use App\Models\IdentifierType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KnowledgeFoundationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $entityTypes = [
                [
                    'name' => 'Control remoto',
                    'slug' => 'remote-control',
                    'description' =>
                        'Control remoto original, alternativo o universal.',
                ],
                [
                    'name' => 'Modelo técnico',
                    'slug' => 'technical-model',
                    'description' =>
                        'Modelo de televisor, equipo o dispositivo.',
                ],
                [
                    'name' => 'Producto de catálogo',
                    'slug' => 'catalog-product',
                    'description' =>
                        'Artículo comercial administrado por SRCM.',
                ],
                [
                    'name' => 'Equipo individual',
                    'slug' => 'individual-device',
                    'description' =>
                        'Dispositivo físico identificable por serie o IMEI.',
                ],
                [
                    'name' => 'Repuesto',
                    'slug' => 'spare-part',
                    'description' =>
                        'Pieza, módulo o componente de reemplazo.',
                ],
            ];

            foreach ($entityTypes as $type) {
                $exists = EntityType::query()
                    ->where('slug', $type['slug'])
                    ->orWhere('name', $type['name'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                EntityType::query()->create([
                    'name' => $type['name'],
                    'slug' => $type['slug'],
                    'description' => $type['description'],
                    'active' => true,
                ]);
            }

            $identifierTypes = [
                [
                    'name' => 'Código principal',
                    'slug' => 'main-code',
                    'description' =>
                        'Código comercial principal del objeto.',
                    'is_unique' => false,
                ],
                [
                    'name' => 'Código alternativo',
                    'slug' => 'alternate-code',
                    'description' =>
                        'Código equivalente, reemplazo o denominación alternativa.',
                    'is_unique' => false,
                ],
                [
                    'name' => 'Código de modelo',
                    'slug' => 'model-code',
                    'description' =>
                        'Modelo técnico informado por el fabricante.',
                    'is_unique' => false,
                ],
                [
                    'name' => 'Número de parte',
                    'slug' => 'part-number',
                    'description' =>
                        'Part Number o referencia de fabricación.',
                    'is_unique' => false,
                ],
                [
                    'name' => 'Número de serie',
                    'slug' => 'serial-number',
                    'description' =>
                        'Número de serie único de un equipo físico.',
                    'is_unique' => true,
                ],
                [
                    'name' => 'IMEI',
                    'slug' => 'imei',
                    'description' =>
                        'Identificador único de un dispositivo móvil.',
                    'is_unique' => true,
                ],
                [
                    'name' => 'Código de barras',
                    'slug' => 'barcode',
                    'description' =>
                        'EAN, UPC u otro código de barras único.',
                    'is_unique' => true,
                ],
                [
                    'name' => 'Código QR',
                    'slug' => 'qr-code',
                    'description' =>
                        'Contenido o referencia obtenida desde un código QR.',
                    'is_unique' => false,
                ],
            ];

            foreach ($identifierTypes as $type) {
                $exists = IdentifierType::query()
                    ->where('slug', $type['slug'])
                    ->orWhere('name', $type['name'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                IdentifierType::query()->create([
                    'name' => $type['name'],
                    'slug' => $type['slug'],
                    'description' => $type['description'],
                    'is_unique' => $type['is_unique'],
                    'active' => true,
                ]);
            }
        });
    }
}
