<?php

namespace Tests\Feature\Import;

use App\Domain\Import\ProductImportManager;
use App\Domain\Knowledge\CatalogProductKnowledgeManager;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\CatalogProduct;
use App\Models\InventoryMovement;
use App\Models\Manufacturer;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductImportOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_routes_are_catalog_managed_and_template_is_available(): void
    {
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);
        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get(route('product-imports.create'))
            ->assertOk()
            ->assertSee('Importar productos')
            ->assertSee('Descargar plantilla CSV');

        $template = $this->actingAs($operator)
            ->get(route('product-imports.template'));

        $template
            ->assertOk()
            ->assertHeader(
                'content-type',
                'text/csv; charset=UTF-8'
            )
            ->assertSee(
                'sku;nombre;categoria;marca;fabricante;descripcion;unidad_base;decimales;activo',
                false
            );

        $this->actingAs($viewer)
            ->get(route('product-imports.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('product-imports.preview'), [])
            ->assertForbidden();
    }

    public function test_csv_preview_and_commit_create_products_through_knowledge_manager(): void
    {
        $actor = $this->operator();
        [$category, $brand, $manufacturer] =
            $this->references('CSV');
        $beforeMovements = InventoryMovement::query()
            ->count();

        $csv = implode("\r\n", [
            'sku;nombre;categoria;marca;fabricante;descripcion;unidad_base;decimales;activo',
            'IMP-CSV-001;Cable CSV;'.$category->name.';'.$brand->name.';'.$manufacturer->name.';Cable importado;unidad;0;si',
            'IMP-CSV-002;Líquido CSV;'.$category->name.';;;Producto fraccionable;l;3;1',
        ]);

        $response = $this->actingAs($actor)->post(
            route('product-imports.preview'),
            [
                'file' => UploadedFile::fake()
                    ->createWithContent(
                        'productos.csv',
                        $csv
                    ),
            ]
        );

        $response
            ->assertOk()
            ->assertSee('Listo para importar')
            ->assertSee('IMP-CSV-001')
            ->assertSee('IMP-CSV-002')
            ->assertSee('Confirmar importación');

        $token = $this->tokenFrom($response->getContent());

        $this->actingAs($actor)
            ->post(
                route('product-imports.store'),
                ['token' => $token]
            )
            ->assertRedirect(route('products.index'));

        $first = CatalogProduct::query()
            ->where('sku', 'IMP-CSV-001')
            ->with([
                'knowledgeEntity',
                'knowledgeIdentifier',
            ])
            ->firstOrFail();

        $second = CatalogProduct::query()
            ->where('sku', 'IMP-CSV-002')
            ->firstOrFail();

        $this->assertSame($category->id, $first->product_category_id);
        $this->assertSame($brand->id, $first->brand_id);
        $this->assertSame($manufacturer->id, $first->manufacturer_id);
        $this->assertNotNull($first->knowledgeEntity);
        $this->assertNotNull($first->knowledgeIdentifier);
        $this->assertSame('l', $second->base_unit_code);
        $this->assertSame(3, $second->quantity_scale);
        $this->assertSame(
            $beforeMovements,
            InventoryMovement::query()->count()
        );
        $this->assertFalse(
            Storage::disk('local')->exists(
                'import-previews/products/'.$token.'.json'
            )
        );
    }

    public function test_invalid_file_fails_closed_without_creating_catalog_rows(): void
    {
        $actor = $this->operator();
        [$category] = $this->references('Invalid');
        $before = CatalogProduct::query()->count();

        $csv = implode("\n", [
            'sku,nombre,categoria,unidad_base,decimales',
            'DUP-001,Producto Uno,'.$category->name.',unidad,0',
            'DUP-001,Producto Dos,'.$category->name.',unidad,2',
            'UNKNOWN-001,Producto Tres,Categoría inexistente,unidad,0',
        ]);

        $response = $this->actingAs($actor)->post(
            route('product-imports.preview'),
            [
                'file' => UploadedFile::fake()
                    ->createWithContent(
                        'invalido.csv',
                        $csv
                    ),
            ]
        );

        $response
            ->assertOk()
            ->assertSee('Requiere correcciones')
            ->assertSee('duplica la fila')
            ->assertSee('no existe o está inactiva')
            ->assertSee(
                'Un artículo contado por unidad no admite fracciones.'
            )
            ->assertDontSee('Confirmar importación');

        $this->assertSame(
            $before,
            CatalogProduct::query()->count()
        );
    }

    public function test_xlsx_first_sheet_is_supported_without_ext_zip_or_external_package(): void
    {
        $this->assertTrue(
            function_exists('gzinflate'),
            'PHP zlib debe estar disponible para leer entradas XLSX DEFLATE.'
        );

        $actor = $this->operator();
        [$category, $brand] = $this->references('XLSX');

        $file = $this->xlsx([
            [
                'sku',
                'nombre',
                'categoria',
                'marca',
                'unidad_base',
                'decimales',
                'activo',
            ],
            [
                'IMP-XLSX-001',
                'Producto XLSX',
                $category->name,
                $brand->name,
                'kg',
                '2',
                'activo',
            ],
        ]);

        $response = $this->actingAs($actor)->post(
            route('product-imports.preview'),
            ['file' => $file]
        );

        $response
            ->assertOk()
            ->assertSee('Listo para importar')
            ->assertSee('IMP-XLSX-001');

        $token = $this->tokenFrom($response->getContent());

        $this->actingAs($actor)
            ->post(
                route('product-imports.store'),
                ['token' => $token]
            )
            ->assertRedirect(route('products.index'));

        $product = CatalogProduct::query()
            ->where('sku', 'IMP-XLSX-001')
            ->firstOrFail();

        $this->assertSame('kg', $product->base_unit_code);
        $this->assertSame(2, $product->quantity_scale);
        $this->assertSame($brand->id, $product->brand_id);
    }

    public function test_commit_revalidates_and_rolls_back_entire_batch_on_new_conflict(): void
    {
        $actor = $this->operator();
        [$category] = $this->references('Race');
        $manager = app(ProductImportManager::class);

        $csv = implode("\n", [
            'sku;nombre;categoria',
            'RACE-001;Producto Race Uno;'.$category->name,
            'RACE-002;Producto Race Dos;'.$category->name,
        ]);

        $preview = $manager->preview(
            UploadedFile::fake()->createWithContent(
                'race.csv',
                $csv
            ),
            $actor
        );

        $this->assertTrue($preview['ready']);
        $this->assertNotNull($preview['token']);

        app(CatalogProductKnowledgeManager::class)->create([
            'product_category_id' => $category->id,
            'brand_id' => null,
            'manufacturer_id' => null,
            'sku' => 'RACE-002',
            'name' => 'Conflicto posterior',
            'description' => null,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);

        $response = $this->actingAs($actor)->post(
            route('product-imports.store'),
            ['token' => $preview['token']]
        );

        $response
            ->assertRedirect(route('product-imports.create'))
            ->assertSessionHas(
                'error',
                fn (string $message): bool =>
                    str_contains(
                        $message,
                        'cambió desde la previsualización'
                    )
            );

        $this->assertDatabaseMissing(
            'catalog_products',
            ['sku' => 'RACE-001']
        );
        $this->assertDatabaseHas(
            'catalog_products',
            ['sku' => 'RACE-002']
        );
    }

    private function operator(): User
    {
        return User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @return array{
     *   ProductCategory,
     *   Brand,
     *   Manufacturer
     * }
     */
    private function references(
        string $suffix
    ): array {
        $category = ProductCategory::query()->create([
            'name' => 'Categoría Import '.$suffix,
            'slug' => 'import-category-'
                .Str::lower($suffix)
                .'-'
                .Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $brand = Brand::query()->create([
            'name' => 'Marca Import '.$suffix,
            'active' => true,
        ]);
        $manufacturer = Manufacturer::query()->create([
            'name' => 'Fabricante Import '.$suffix,
            'active' => true,
        ]);

        return [$category, $brand, $manufacturer];
    }

    private function tokenFrom(string $html): string
    {
        $matched = preg_match(
            '/name="token"\s+value="([0-9a-f-]{36})"/i',
            $html,
            $matches
        );

        $this->assertSame(
            1,
            $matched,
            'La previsualización debe incluir el token de confirmación.'
        );

        return $matches[1];
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function xlsx(array $rows): UploadedFile
    {
        $sheetRows = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];

            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName(
                    $columnIndex
                ).($rowIndex + 1);
                $escaped = htmlspecialchars(
                    $value,
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8'
                );
                $cells[] =
                    '<c r="'.$reference.'" t="inlineStr">'
                    .'<is><t>'.$escaped.'</t></is>'
                    .'</c>';
            }

            $sheetRows[] =
                '<row r="'.($rowIndex + 1).'">'
                .implode('', $cells)
                .'</row>';
        }

        $entries = [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'</Types>',
            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Productos" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData>'
                .implode('', $sheetRows)
                .'</sheetData>'
                .'</worksheet>',
        ];

        return UploadedFile::fake()->createWithContent(
            'productos.xlsx',
            $this->zipDeflate($entries)
        );
    }

    /**
     * @param array<string, string> $entries
     */
    private function zipDeflate(array $entries): string
    {
        if (! function_exists('gzdeflate')) {
            $this->fail(
                'PHP zlib debe estar disponible para construir el XLSX de prueba.'
            );
        }

        $body = '';
        $central = '';
        $offset = 0;
        $count = 0;

        foreach ($entries as $name => $content) {
            $compressed = gzdeflate($content, 6);

            if ($compressed === false) {
                $this->fail(
                    'No se pudo comprimir una entrada XLSX de prueba.'
                );
            }

            $nameLength = strlen($name);
            $compressedLength = strlen($compressed);
            $uncompressedLength = strlen($content);
            $crc = crc32($content);

            $local =
                pack(
                    'VvvvvvVVVvv',
                    0x04034b50,
                    20,
                    0,
                    8,
                    0,
                    0,
                    $crc,
                    $compressedLength,
                    $uncompressedLength,
                    $nameLength,
                    0
                )
                .$name
                .$compressed;

            $centralRecord =
                pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014b50,
                    20,
                    20,
                    0,
                    8,
                    0,
                    0,
                    $crc,
                    $compressedLength,
                    $uncompressedLength,
                    $nameLength,
                    0,
                    0,
                    0,
                    0,
                    0,
                    $offset
                )
                .$name;

            $body .= $local;
            $central .= $centralRecord;
            $offset += strlen($local);
            $count++;
        }

        $end =
            pack(
                'VvvvvVVv',
                0x06054b50,
                0,
                0,
                $count,
                $count,
                strlen($central),
                strlen($body),
                0
            );

        return $body.$central.$end;
    }

    private function columnName(int $index): string
    {
        $name = '';
        $number = $index + 1;

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $number = intdiv(
                $number - 1,
                26
            );
        }

        return $name;
    }
}
