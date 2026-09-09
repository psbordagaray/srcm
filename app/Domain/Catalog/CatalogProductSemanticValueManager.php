<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditRecorder;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\ProductSchemaStatus;
use App\Models\AttributeBinding;
use App\Models\CatalogProduct;
use App\Models\CatalogProductSemanticValue;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

class CatalogProductSemanticValueManager
{
    private const PAYLOAD_COLUMNS = [
        'value_text',
        'value_boolean',
        'value_integer',
        'value_decimal',
        'value_date',
        'value_datetime',
    ];

    public function __construct(
        private readonly CatalogProductSemanticProfileResolver $profileResolver,
        private readonly AuditRecorder $auditRecorder
    ) {
    }

    public function set(
        CatalogProduct $product,
        string $attributeDefinitionKey,
        mixed $value
    ): CatalogProductSemanticValue {
        return DB::transaction(function () use (
            $product,
            $attributeDefinitionKey,
            $value
        ): CatalogProductSemanticValue {
            $lockedProduct = $this->lockProduct($product);

            [
                $attribute,
                $binding,
            ] = $this->lockCurrentAttribute(
                $lockedProduct,
                $attributeDefinitionKey
            );

            $canonical = $this->canonicalize(
                $attribute->valueType,
                $value
            );

            $payload = $this->payloadFor(
                $attribute->valueType,
                $canonical
            );

            $existing = CatalogProductSemanticValue::query()
                ->where(
                    'catalog_product_id',
                    $lockedProduct->id
                )
                ->where(
                    'attribute_binding_id',
                    $binding->id
                )
                ->lockForUpdate()
                ->first();

            if (
                $existing
                && $existing->canonicalValue() === $canonical
            ) {
                return $existing->fresh();
            }

            $auditContext = $this->auditContext(
                $attribute
            );

            if (! $existing) {
                $created = CatalogProductSemanticValue::query()
                    ->create([
                        'catalog_product_id' =>
                            $lockedProduct->id,
                        'attribute_binding_id' =>
                            $binding->id,
                        ...$payload,
                    ]);

                $this->auditRecorder->record(
                    $lockedProduct,
                    'catalog_product.semantic_value_set',
                    null,
                    [
                        ...$auditContext,
                        'value' => $canonical,
                    ]
                );

                return $created->fresh();
            }

            $oldValue = $existing->canonicalValue();

            $existing->fill($payload)->save();

            $this->auditRecorder->record(
                $lockedProduct,
                'catalog_product.semantic_value_updated',
                [
                    ...$auditContext,
                    'value' => $oldValue,
                ],
                [
                    ...$auditContext,
                    'value' => $canonical,
                ]
            );

            return $existing->fresh();
        });
    }

    public function clear(
        CatalogProduct $product,
        string $attributeDefinitionKey
    ): void {
        DB::transaction(function () use (
            $product,
            $attributeDefinitionKey
        ): void {
            $lockedProduct = $this->lockProduct($product);

            [
                $attribute,
                $binding,
            ] = $this->lockCurrentAttribute(
                $lockedProduct,
                $attributeDefinitionKey
            );

            $existing = CatalogProductSemanticValue::query()
                ->where(
                    'catalog_product_id',
                    $lockedProduct->id
                )
                ->where(
                    'attribute_binding_id',
                    $binding->id
                )
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                return;
            }

            $oldValue = $existing->canonicalValue();
            $auditContext = $this->auditContext(
                $attribute
            );

            $existing->delete();

            $this->auditRecorder->record(
                $lockedProduct,
                'catalog_product.semantic_value_cleared',
                [
                    ...$auditContext,
                    'value' => $oldValue,
                ],
                [
                    ...$auditContext,
                    'value' => null,
                ]
            );
        });
    }

    private function lockProduct(
        CatalogProduct $product
    ): CatalogProduct {
        return CatalogProduct::query()
            ->whereKey($product->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{
     *     EffectiveSemanticAttribute,
     *     AttributeBinding
     * }
     */
    private function lockCurrentAttribute(
        CatalogProduct $product,
        string $attributeDefinitionKey
    ): array {
        $attributeDefinitionKey =
            trim($attributeDefinitionKey);

        SemanticKey::assertValid(
            $attributeDefinitionKey
        );

        $definitionId = (int) $product
            ->getAttribute('product_definition_id');

        if ($definitionId <= 0) {
            throw new DomainException(
                'Un producto sin clasificación semántica no admite valores semánticos.'
            );
        }

        $definition = ProductDefinition::query()
            ->whereKey($definitionId)
            ->lockForUpdate()
            ->first();

        if (! $definition) {
            throw new DomainException(
                'La definición semántica asignada al producto no existe.'
            );
        }

        $published = ProductSchemaVersion::query()
            ->where(
                'product_definition_id',
                $definition->id
            )
            ->where(
                'status',
                ProductSchemaStatus::Published->value
            )
            ->lockForUpdate()
            ->get();

        if ($published->count() !== 1) {
            throw new DomainException(
                'El producto debe resolver exactamente un schema publicado actual.'
            );
        }

        $resolution = $this->profileResolver
            ->current($product);

        if (
            ! $resolution->classified
            || ! $resolution->profile
            || $resolution->productDefinitionId
                !== (int) $definition->id
            || $resolution->profile
                ->productSchemaVersionId
                !== (int) $published->sole()->id
        ) {
            throw new DomainException(
                'La resolución semántica actual no converge con el contexto bloqueado.'
            );
        }

        $matches = array_values(
            array_filter(
                $resolution->profile->attributes,
                static fn (
                    EffectiveSemanticAttribute $attribute
                ): bool =>
                    $attribute->attributeDefinitionKey
                        === $attributeDefinitionKey
            )
        );

        if (count($matches) !== 1) {
            throw new DomainException(
                'El atributo solicitado no pertenece exactamente una vez al perfil semántico actual.'
            );
        }

        $attribute = $matches[0];

        if (
            $attribute->valueScope
                !== AttributeValueScope::Product
        ) {
            throw new DomainException(
                'CSF-6 V1 sólo admite escritura de atributos con scope product.'
            );
        }

        $binding = AttributeBinding::query()
            ->whereKey($attribute->attributeBindingId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            (int) $binding->product_schema_version_id
                !== $resolution->profile
                    ->productSchemaVersionId
            || (int) $binding->attribute_definition_id
                !== $attribute->attributeDefinitionId
            || $binding->value_type
                !== $attribute->valueType
            || $binding->value_scope
                !== $attribute->valueScope
            || $this->nullableInt(
                $binding->measurement_unit_id
            ) !== $attribute->measurementUnitId
        ) {
            throw new DomainException(
                'El binding bloqueado no coincide con la proyección semántica efectiva.'
            );
        }

        return [$attribute, $binding];
    }

    private function canonicalize(
        AttributeValueType $type,
        mixed $value
    ): bool|int|string {
        return match ($type) {
            AttributeValueType::Text =>
                $this->canonicalText($value),
            AttributeValueType::Boolean =>
                $this->canonicalBoolean($value),
            AttributeValueType::Integer =>
                $this->canonicalInteger($value),
            AttributeValueType::ExactDecimal,
            AttributeValueType::Measurement =>
                $this->canonicalDecimal($value),
            AttributeValueType::Date =>
                $this->canonicalDate($value),
            AttributeValueType::DateTime =>
                $this->canonicalDateTime($value),
        };
    }

    private function canonicalText(
        mixed $value
    ): string {
        if (! is_string($value)) {
            throw new DomainException(
                'Un valor text debe recibirse como texto explícito.'
            );
        }

        $canonical = trim($value);

        if ($canonical === '') {
            throw new DomainException(
                'Un valor text no puede quedar vacío.'
            );
        }

        return $canonical;
    }

    private function canonicalBoolean(
        mixed $value
    ): bool {
        if (! is_bool($value)) {
            throw new DomainException(
                'Un valor boolean requiere true o false explícito.'
            );
        }

        return $value;
    }

    private function canonicalInteger(
        mixed $value
    ): int {
        if (is_int($value)) {
            return $value;
        }

        if (
            ! is_string($value)
            || preg_match('/^-?\d+$/', $value) !== 1
        ) {
            throw new DomainException(
                'Un valor integer debe ser un entero decimal exacto.'
            );
        }

        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        if ($validated === false) {
            throw new DomainException(
                'El valor integer está fuera del rango de 64 bits admitido.'
            );
        }

        return (int) $validated;
    }

    private function canonicalDecimal(
        mixed $value
    ): string {
        if (is_float($value)) {
            throw new DomainException(
                'Los valores decimales semánticos no admiten float binario como autoridad.'
            );
        }

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            throw new DomainException(
                'El valor decimal debe recibirse como entero o string decimal exacto.'
            );
        }

        if (
            preg_match(
                '/^-?\d+(?:\.\d+)?$/',
                $raw
            ) !== 1
        ) {
            throw new DomainException(
                'El valor decimal no posee una representación exacta válida.'
            );
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = $negative
            ? substr($raw, 1)
            : $raw;

        $parts = explode('.', $unsigned, 2);
        $integer = ltrim($parts[0], '0');
        $integer = $integer === ''
            ? '0'
            : $integer;
        $fraction = $parts[1] ?? '';

        if (
            strlen($integer) > 20
            || strlen($fraction) > 18
        ) {
            throw new DomainException(
                'El valor decimal excede DECIMAL(38,18).'
            );
        }

        $fraction = str_pad(
            $fraction,
            18,
            '0'
        );

        $nonZero =
            $integer !== '0'
            || trim($fraction, '0') !== '';

        return ($negative && $nonZero ? '-' : '')
            .$integer
            .'.'
            .$fraction;
    }

    private function canonicalDate(
        mixed $value
    ): string {
        if (
            ! is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'Un valor date requiere formato YYYY-MM-DD.'
            );
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone('UTC')
        );

        if (
            ! $date
            || $date->format('Y-m-d') !== $value
        ) {
            throw new DomainException(
                'El valor date no representa una fecha de calendario válida.'
            );
        }

        return $value;
    }

    private function canonicalDateTime(
        mixed $value
    ): string {
        if ($value instanceof DateTimeInterface) {
            $dateTime = DateTimeImmutable::createFromInterface(
                $value
            );
        } elseif (
            is_string($value)
            && preg_match(
                '/^\d{4}-\d{2}-\d{2}T'
                . '\d{2}:\d{2}:\d{2}'
                . '(?:\.\d{1,6})?'
                . '(?:Z|[+-]\d{2}:\d{2})$/',
                $value
            ) === 1
        ) {
            try {
                $dateTime = new DateTimeImmutable(
                    $value
                );
            } catch (\Throwable) {
                throw new DomainException(
                    'El valor datetime no representa un instante válido.'
                );
            }
        } else {
            throw new DomainException(
                'Un valor datetime requiere timezone explícito.'
            );
        }

        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param bool|int|string $canonical
     * @return array<string, mixed>
     */
    private function payloadFor(
        AttributeValueType $type,
        bool|int|string $canonical
    ): array {
        $payload = array_fill_keys(
            self::PAYLOAD_COLUMNS,
            null
        );

        $column = match ($type) {
            AttributeValueType::Text => 'value_text',
            AttributeValueType::Boolean => 'value_boolean',
            AttributeValueType::Integer => 'value_integer',
            AttributeValueType::ExactDecimal,
            AttributeValueType::Measurement =>
                'value_decimal',
            AttributeValueType::Date => 'value_date',
            AttributeValueType::DateTime =>
                'value_datetime',
        };

        $payload[$column] =
            $type === AttributeValueType::DateTime
                ? $this->databaseDateTime(
                    (string) $canonical
                )
                : $canonical;

        return $payload;
    }

    private function databaseDateTime(
        string $canonical
    ): string {
        return substr($canonical, 0, 10)
            .' '
            .substr($canonical, 11, 15);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditContext(
        EffectiveSemanticAttribute $attribute
    ): array {
        return [
            'attribute_binding_id' =>
                $attribute->attributeBindingId,
            'product_schema_version_id' =>
                $attribute->provenance
                    ->productSchemaVersionId,
            'attribute_definition_id' =>
                $attribute->attributeDefinitionId,
            'attribute_definition_key' =>
                $attribute->attributeDefinitionKey,
            'value_type' =>
                $attribute->valueType->value,
            'value_scope' =>
                $attribute->valueScope->value,
            'measurement_unit_id' =>
                $attribute->measurementUnitId,
            'measurement_unit_key' =>
                $attribute->measurementUnitKey,
        ];
    }

    private function nullableInt(
        mixed $value
    ): ?int {
        return $value === null
            ? null
            : (int) $value;
    }
}
