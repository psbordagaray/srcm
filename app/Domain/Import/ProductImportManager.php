<?php

namespace App\Domain\Import;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Knowledge\CatalogProductKnowledgeManager;
use App\Enums\InventoryBaseUnit;
use App\Models\Brand;
use App\Models\CatalogProduct;
use App\Models\Manufacturer;
use App\Models\ProductCategory;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductImportManager
{
    private const DRAFT_DIRECTORY =
        'import-previews/products';

    private const DRAFT_TTL_MINUTES = 30;

    /**
     * @var array<string, array<int, string>>
     */
    private const HEADER_ALIASES = [
        'sku' => [
            'sku',
            'codigo',
            'codigoprincipal',
            'codigoarticulo',
        ],
        'name' => [
            'nombre',
            'name',
            'producto',
        ],
        'category' => [
            'categoria',
            'category',
            'rubro',
        ],
        'brand' => [
            'marca',
            'brand',
        ],
        'manufacturer' => [
            'fabricante',
            'manufacturer',
        ],
        'description' => [
            'descripcion',
            'description',
            'detalle',
        ],
        'base_unit_code' => [
            'unidad',
            'unidadbase',
            'baseunit',
            'baseunitcode',
        ],
        'quantity_scale' => [
            'decimales',
            'precision',
            'quantityscale',
            'escala',
        ],
        'active' => [
            'activo',
            'active',
            'estado',
        ],
    ];

    public function __construct(
        private readonly ProductImportFileReader $reader,
        private readonly CatalogProductKnowledgeManager $products
    ) {
    }

    /**
     * @return array{
     *   file_name: string,
     *   sha256: string,
     *   count: int,
     *   ready: bool,
     *   token: ?string,
     *   ignored_headers: array<int, string>,
     *   rows: array<int, array<string, mixed>>,
     *   errors: array<int, array{row: int, message: string}>
     * }
     */
    public function preview(
        UploadedFile $file,
        User $actor
    ): array {
        $this->cleanupExpiredDrafts();

        $sheet = $this->reader->read($file);
        $rows = $sheet['rows'];
        $header = array_shift($rows) ?? [];

        [
            $columnMap,
            $headerErrors,
            $ignoredHeaders,
        ] = $this->mapHeaders($header);

        $prepared = [];
        $errors = $headerErrors;
        $categories = $this->referenceMap(
            ProductCategory::class
        );
        $brands = $this->referenceMap(
            Brand::class
        );
        $manufacturers = $this->referenceMap(
            Manufacturer::class
        );

        foreach ($rows as $offset => $sourceRow) {
            $rowNumber = $offset + 2;

            if ($this->isBlankRow($sourceRow)) {
                continue;
            }

            [$row, $rowErrors] = $this->prepareRow(
                $rowNumber,
                $sourceRow,
                $columnMap,
                $categories,
                $brands,
                $manufacturers
            );

            $prepared[] = $row;

            foreach ($rowErrors as $message) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $message,
                ];
            }
        }

        if ($prepared === []) {
            $errors[] = [
                'row' => 1,
                'message' => 'No hay filas de productos para importar.',
            ];
        }

        $errors = [
            ...$errors,
            ...$this->validatePreparedRows($prepared),
        ];

        $token = null;

        if ($errors === []) {
            $token = (string) Str::uuid();

            $this->writeDraft(
                $token,
                $actor,
                $sheet['file_name'],
                $sheet['sha256'],
                $prepared
            );
        }

        return [
            'file_name' => $sheet['file_name'],
            'sha256' => $sheet['sha256'],
            'count' => count($prepared),
            'ready' => $errors === [],
            'token' => $token,
            'ignored_headers' => $ignoredHeaders,
            'rows' => array_slice($prepared, 0, 50),
            'errors' => $errors,
        ];
    }

    public function commit(
        string $token,
        User $actor
    ): int {
        $this->cleanupExpiredDrafts();

        $draft = $this->readDraft($token);

        if (
            (int) ($draft['user_id'] ?? 0)
            !== (int) $actor->getKey()
        ) {
            throw new DomainException(
                'La previsualización pertenece a otro usuario.'
            );
        }

        $createdAt = (string) ($draft['created_at'] ?? '');

        if (
            $createdAt === ''
            || now()->diffInMinutes(
                \Illuminate\Support\Carbon::parse($createdAt),
                absolute: true
            ) > self::DRAFT_TTL_MINUTES
        ) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización venció. Volvé a cargar el archivo.'
            );
        }

        $rows = $draft['rows'] ?? null;

        if (! is_array($rows) || $rows === []) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización no contiene filas válidas.'
            );
        }

        $count = DB::transaction(function () use ($rows): int {
            $errors = $this->validatePreparedRows(
                $rows,
                verifyReferences: true
            );

            if ($errors !== []) {
                $first = $errors[0];

                throw new DomainException(
                    'La importación cambió desde la previsualización. '
                    .'Fila '.$first['row'].': '
                    .$first['message']
                );
            }

            $created = 0;

            foreach ($rows as $row) {
                try {
                    $this->products->create(
                        $row['data']
                    );
                } catch (Throwable $exception) {
                    throw new DomainException(
                        'Fila '.$row['row_number']
                        .': no pudo importarse. '
                        .$exception->getMessage(),
                        previous: $exception
                    );
                }

                $created++;
            }

            return $created;
        });

        $this->deleteDraft($token);

        return $count;
    }

    /**
     * @param array<int, string> $header
     * @return array{
     *   array<string, int>,
     *   array<int, array{row: int, message: string}>,
     *   array<int, string>
     * }
     */
    private function mapHeaders(
        array $header
    ): array {
        $aliasToCanonical = [];

        foreach (
            self::HEADER_ALIASES as $canonical => $aliases
        ) {
            foreach ($aliases as $alias) {
                $aliasToCanonical[$alias] = $canonical;
            }
        }

        $map = [];
        $errors = [];
        $ignored = [];

        foreach ($header as $index => $rawHeader) {
            $normalized = $this->normalizeHeader(
                (string) $rawHeader
            );

            if ($normalized === '') {
                continue;
            }

            $canonical = $aliasToCanonical[$normalized]
                ?? null;

            if ($canonical === null) {
                $ignored[] = (string) $rawHeader;

                continue;
            }

            if (array_key_exists($canonical, $map)) {
                $errors[] = [
                    'row' => 1,
                    'message' =>
                        'La columna "'.$rawHeader
                        .'" duplica el campo '
                        .$canonical.'.',
                ];

                continue;
            }

            $map[$canonical] = $index;
        }

        foreach (['sku', 'name', 'category'] as $required) {
            if (! array_key_exists($required, $map)) {
                $errors[] = [
                    'row' => 1,
                    'message' =>
                        'Falta la columna obligatoria '
                        .$required.'.',
                ];
            }
        }

        return [$map, $errors, $ignored];
    }

    /**
     * @param array<int, string> $sourceRow
     * @param array<string, int> $columnMap
     * @param array<string, array{id: ?int, ambiguous: bool}> $categories
     * @param array<string, array{id: ?int, ambiguous: bool}> $brands
     * @param array<string, array{id: ?int, ambiguous: bool}> $manufacturers
     * @return array{
     *   array<string, mixed>,
     *   array<int, string>
     * }
     */
    private function prepareRow(
        int $rowNumber,
        array $sourceRow,
        array $columnMap,
        array $categories,
        array $brands,
        array $manufacturers
    ): array {
        $value = function (string $key) use (
            $sourceRow,
            $columnMap
        ): string {
            if (! array_key_exists($key, $columnMap)) {
                return '';
            }

            return trim(
                (string) (
                    $sourceRow[$columnMap[$key]]
                    ?? ''
                )
            );
        };

        $sku = Str::of($value('sku'))
            ->squish()
            ->upper()
            ->toString();
        $name = Str::of($value('name'))
            ->squish()
            ->toString();
        $categoryName = Str::of($value('category'))
            ->squish()
            ->toString();
        $brandName = Str::of($value('brand'))
            ->squish()
            ->toString();
        $manufacturerName = Str::of(
            $value('manufacturer')
        )->squish()->toString();

        $errors = [];

        $categoryId = $this->resolveReference(
            'categoría',
            $categoryName,
            $categories,
            required: true,
            errors: $errors
        );
        $brandId = $this->resolveReference(
            'marca',
            $brandName,
            $brands,
            required: false,
            errors: $errors
        );
        $manufacturerId = $this->resolveReference(
            'fabricante',
            $manufacturerName,
            $manufacturers,
            required: false,
            errors: $errors
        );

        $baseUnit = $this->baseUnit(
            $value('base_unit_code'),
            $errors
        );
        $quantityScale = $this->quantityScale(
            $value('quantity_scale'),
            $errors
        );
        $active = $this->active(
            $value('active'),
            $errors
        );

        if ($sku === '') {
            $errors[] = 'El SKU es obligatorio.';
        } elseif (Str::length($sku) > 120) {
            $errors[] = 'El SKU supera 120 caracteres.';
        }

        if ($name === '') {
            $errors[] = 'El nombre es obligatorio.';
        } elseif (Str::length($name) > 255) {
            $errors[] = 'El nombre supera 255 caracteres.';
        }

        $description = $value('description');

        if (Str::length($description) > 5000) {
            $errors[] =
                'La descripción supera 5000 caracteres.';
        }

        if (
            $baseUnit === InventoryBaseUnit::Unit
            && $quantityScale !== 0
        ) {
            $errors[] =
                'Un artículo contado por unidad no admite fracciones.';
        }

        return [[
            'row_number' => $rowNumber,
            'source' => [
                'sku' => $sku,
                'name' => $name,
                'category' => $categoryName,
                'brand' => $brandName,
                'manufacturer' => $manufacturerName,
                'base_unit_code' => $baseUnit->value,
                'quantity_scale' => $quantityScale,
                'active' => $active,
            ],
            'data' => [
                'product_category_id' => $categoryId,
                'brand_id' => $brandId,
                'manufacturer_id' => $manufacturerId,
                'sku' => $sku,
                'name' => $name,
                'description' => $description !== ''
                    ? $description
                    : null,
                'base_unit_code' => $baseUnit->value,
                'quantity_scale' => $quantityScale,
                'active' => $active,
            ],
        ], $errors];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{row: int, message: string}>
     */
    private function validatePreparedRows(
        array $rows,
        bool $verifyReferences = false
    ): array {
        $errors = [];
        $skuRows = [];
        $nameKeys = [];

        foreach ($rows as $row) {
            $rowNumber = (int) (
                $row['row_number'] ?? 0
            );
            $data = $row['data'] ?? [];

            if (! is_array($data)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' =>
                        'La fila preparada no es válida.',
                ];

                continue;
            }

            $normalizedSku =
                CatalogProduct::normalizeIdentity(
                    (string) ($data['sku'] ?? '')
                );

            if ($normalizedSku === '') {
                continue;
            }

            if (isset($skuRows[$normalizedSku])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' =>
                        'El SKU duplica la fila '
                        .$skuRows[$normalizedSku].'.',
                ];
            } else {
                $skuRows[$normalizedSku] = $rowNumber;
            }

            $nameKey = implode('|', [
                (string) (
                    $data['product_category_id'] ?? ''
                ),
                (string) ($data['brand_id'] ?? 'null'),
                CatalogProduct::normalizeIdentity(
                    (string) ($data['name'] ?? '')
                ),
            ]);

            if (isset($nameKeys[$nameKey])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' =>
                        'El nombre, marca y categoría duplican la fila '
                        .$nameKeys[$nameKey].'.',
                ];
            } else {
                $nameKeys[$nameKey] = $rowNumber;
            }

            if ($verifyReferences) {
                $errors = [
                    ...$errors,
                    ...$this->validateReferences(
                        $rowNumber,
                        $data
                    ),
                ];
            }
        }

        $normalizedSkus = array_keys($skuRows);

        if ($normalizedSkus !== []) {
            $existing = CatalogProduct::query()
                ->whereIn(
                    'normalized_sku',
                    $normalizedSkus
                )
                ->pluck('normalized_sku')
                ->all();

            foreach ($existing as $normalizedSku) {
                $errors[] = [
                    'row' =>
                        $skuRows[$normalizedSku] ?? 0,
                    'message' =>
                        'Ya existe un producto con un SKU equivalente.',
                ];
            }
        }

        $normalizedNames = collect($rows)
            ->map(
                fn (array $row): string =>
                    CatalogProduct::normalizeIdentity(
                        (string) (
                            $row['data']['name']
                            ?? ''
                        )
                    )
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalizedNames !== []) {
            $existingProducts = CatalogProduct::query()
                ->whereIn(
                    'normalized_name',
                    $normalizedNames
                )
                ->get([
                    'product_category_id',
                    'brand_id',
                    'normalized_name',
                ]);

            foreach ($rows as $row) {
                $data = $row['data'];
                $normalizedName =
                    CatalogProduct::normalizeIdentity(
                        (string) $data['name']
                    );

                $duplicate = $existingProducts->first(
                    fn (CatalogProduct $product): bool =>
                        (int) $product->product_category_id
                            === (int) $data['product_category_id']
                        && (
                            $product->brand_id === null
                                ? $data['brand_id'] === null
                                : (int) $product->brand_id
                                    === (int) $data['brand_id']
                        )
                        && $product->normalized_name
                            === $normalizedName
                );

                if ($duplicate) {
                    $errors[] = [
                        'row' => (int) $row['row_number'],
                        'message' =>
                            'Ya existe un producto con este nombre, marca y categoría.',
                    ];
                }
            }
        }

        return $this->uniqueErrors($errors);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{row: int, message: string}>
     */
    private function validateReferences(
        int $rowNumber,
        array $data
    ): array {
        $checks = [
            [
                ProductCategory::class,
                'product_category_id',
                'La categoría ya no existe o está inactiva.',
                false,
            ],
            [
                Brand::class,
                'brand_id',
                'La marca ya no existe o está inactiva.',
                true,
            ],
            [
                Manufacturer::class,
                'manufacturer_id',
                'El fabricante ya no existe o está inactivo.',
                true,
            ],
        ];
        $errors = [];

        foreach ($checks as [
            $modelClass,
            $key,
            $message,
            $nullable,
        ]) {
            $id = $data[$key] ?? null;

            if ($nullable && $id === null) {
                continue;
            }

            if (
                ! $modelClass::query()
                    ->whereKey($id)
                    ->where('active', true)
                    ->exists()
            ) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $message,
                ];
            }
        }

        return $errors;
    }

    /**
     * @template TModel of Model
     * @param class-string<TModel> $modelClass
     * @return array<string, array{id: ?int, ambiguous: bool}>
     */
    private function referenceMap(
        string $modelClass
    ): array {
        $groups = $modelClass::query()
            ->where('active', true)
            ->get(['id', 'name'])
            ->groupBy(
                fn (Model $model): string =>
                    $this->normalizeReference(
                        (string) $model->name
                    )
            );

        $map = [];

        foreach ($groups as $key => $models) {
            $map[$key] = [
                'id' => $models->count() === 1
                    ? (int) $models->first()->getKey()
                    : null,
                'ambiguous' => $models->count() > 1,
            ];
        }

        return $map;
    }

    /**
     * @param array<string, array{id: ?int, ambiguous: bool}> $map
     * @param array<int, string> $errors
     */
    private function resolveReference(
        string $label,
        string $name,
        array $map,
        bool $required,
        array &$errors
    ): ?int {
        if ($name === '') {
            if ($required) {
                $errors[] =
                    'La '.$label.' es obligatoria.';
            }

            return null;
        }

        $key = $this->normalizeReference($name);
        $match = $map[$key] ?? null;

        if ($match === null) {
            $errors[] =
                'La '.$label.' "'.$name
                .'" no existe o está inactiva.';

            return null;
        }

        if ($match['ambiguous']) {
            $errors[] =
                'La '.$label.' "'.$name
                .'" es ambigua y requiere revisión manual.';

            return null;
        }

        return $match['id'];
    }

    /**
     * @param array<int, string> $errors
     */
    private function baseUnit(
        string $raw,
        array &$errors
    ): InventoryBaseUnit {
        $value = $this->normalizeHeader($raw);

        if ($value === '') {
            return InventoryBaseUnit::Unit;
        }

        $unit = match ($value) {
            'unit', 'unidad', 'unidades', 'u', 'ud' =>
                InventoryBaseUnit::Unit,
            'l', 'litro', 'litros' =>
                InventoryBaseUnit::Liter,
            'm', 'metro', 'metros' =>
                InventoryBaseUnit::Meter,
            'kg', 'kilogramo', 'kilogramos', 'kilo', 'kilos' =>
                InventoryBaseUnit::Kilogram,
            default => null,
        };

        if ($unit === null) {
            $errors[] =
                'La unidad base "'.$raw
                .'" no está admitida.';

            return InventoryBaseUnit::Unit;
        }

        return $unit;
    }

    /**
     * @param array<int, string> $errors
     */
    private function quantityScale(
        string $raw,
        array &$errors
    ): int {
        if ($raw === '') {
            return 0;
        }

        if (! preg_match('/^\d+$/', $raw)) {
            $errors[] =
                'Los decimales deben ser un número entero.';

            return 0;
        }

        $scale = (int) $raw;

        if (
            $scale < 0
            || $scale > InventoryQuantity::SCALE
        ) {
            $errors[] =
                'Los decimales deben estar entre 0 y '
                .InventoryQuantity::SCALE.'.';

            return 0;
        }

        return $scale;
    }

    /**
     * @param array<int, string> $errors
     */
    private function active(
        string $raw,
        array &$errors
    ): bool {
        $value = $this->normalizeHeader($raw);

        if ($value === '') {
            return true;
        }

        if (
            in_array(
                $value,
                [
                    '1',
                    'true',
                    'si',
                    'yes',
                    'activo',
                    'activa',
                ],
                true
            )
        ) {
            return true;
        }

        if (
            in_array(
                $value,
                [
                    '0',
                    'false',
                    'no',
                    'inactivo',
                    'inactiva',
                ],
                true
            )
        ) {
            return false;
        }

        $errors[] =
            'El estado "'.$raw
            .'" no es válido. Usá sí/no, 1/0 o activo/inactivo.';

        return true;
    }

    /**
     * @param array<int, string> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(
        string $value
    ): string {
        $ascii = Str::lower(
            Str::ascii(trim($value))
        );

        return preg_replace(
            '/[^a-z0-9]+/',
            '',
            $ascii
        ) ?? '';
    }

    private function normalizeReference(
        string $value
    ): string {
        return $this->normalizeHeader($value);
    }

    /**
     * @param array<int, array{row: int, message: string}> $errors
     * @return array<int, array{row: int, message: string}>
     */
    private function uniqueErrors(array $errors): array
    {
        $seen = [];
        $unique = [];

        foreach ($errors as $error) {
            $key = $error['row'].'|'.$error['message'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $error;
        }

        return $unique;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeDraft(
        string $token,
        User $actor,
        string $fileName,
        string $sha256,
        array $rows
    ): void {
        $payload = json_encode([
            'version' => 1,
            'user_id' => (int) $actor->getKey(),
            'created_at' => now()->toIso8601String(),
            'file_name' => $fileName,
            'sha256' => $sha256,
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (
            ! Storage::disk('local')->put(
                $this->draftPath($token),
                $payload
            )
        ) {
            throw new DomainException(
                'No se pudo guardar la previsualización privada.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readDraft(
        string $token
    ): array {
        $path = $this->draftPath($token);

        if (! Storage::disk('local')->exists($path)) {
            throw new DomainException(
                'La previsualización no existe o ya fue utilizada.'
            );
        }

        $contents = Storage::disk('local')->get($path);

        try {
            $decoded = json_decode(
                $contents,
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización está dañada.',
                previous: $exception
            );
        }

        if (! is_array($decoded)) {
            throw new DomainException(
                'La previsualización tiene un formato inválido.'
            );
        }

        return $decoded;
    }

    private function cleanupExpiredDrafts(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()
            ->subHour()
            ->getTimestamp();

        foreach (
            $disk->files(self::DRAFT_DIRECTORY)
            as $file
        ) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    private function deleteDraft(string $token): void
    {
        Storage::disk('local')->delete(
            $this->draftPath($token)
        );
    }

    private function draftPath(string $token): string
    {
        if (! Str::isUuid($token)) {
            throw new DomainException(
                'El token de importación no es válido.'
            );
        }

        return self::DRAFT_DIRECTORY.'/'.$token.'.json';
    }
}
