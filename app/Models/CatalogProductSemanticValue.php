<?php

namespace App\Models;

use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\ProductSchemaStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogProductSemanticValue extends Model
{
    protected $table = 'catalog_product_semantic_values';

    protected $fillable = [
        'catalog_product_id',
        'attribute_binding_id',
        'value_text',
        'value_boolean',
        'value_integer',
        'value_decimal',
        'value_date',
        'value_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (CatalogProductSemanticValue $value) =>
                $value->assertStoredContract()
        );

        static::updating(
            function (CatalogProductSemanticValue $value): void {
                if (
                    $value->isDirty([
                        'catalog_product_id',
                        'attribute_binding_id',
                    ])
                ) {
                    throw new DomainException(
                        'La identidad de un valor semántico de producto es inmutable.'
                    );
                }

                $value->assertStoredContract();
            }
        );
    }

    protected function casts(): array
    {
        return [
            'value_boolean' => 'boolean',
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:18',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'catalog_product_id'
        );
    }

    public function attributeBinding(): BelongsTo
    {
        return $this->belongsTo(
            AttributeBinding::class,
            'attribute_binding_id'
        );
    }

    public function assertStoredContract(): void
    {
        $productId = (int) $this->catalog_product_id;
        $bindingId = (int) $this->attribute_binding_id;

        if ($productId <= 0 || $bindingId <= 0) {
            throw new DomainException(
                'El valor semántico requiere producto y binding válidos.'
            );
        }

        $product = CatalogProduct::query()
            ->whereKey($productId)
            ->first();

        if (! $product) {
            throw new DomainException(
                'El producto del valor semántico no existe.'
            );
        }

        $definitionId = (int) $product->product_definition_id;

        if ($definitionId <= 0) {
            throw new DomainException(
                'Un producto sin clasificación semántica no admite valores semánticos.'
            );
        }

        $binding = AttributeBinding::query()
            ->whereKey($bindingId)
            ->first();

        if (! $binding) {
            throw new DomainException(
                'El binding del valor semántico no existe.'
            );
        }

        if (
            $binding->value_scope
                !== AttributeValueScope::Product
        ) {
            throw new DomainException(
                'CSF-6 V1 sólo admite valores semánticos con scope product.'
            );
        }

        $schema = ProductSchemaVersion::query()
            ->whereKey($binding->product_schema_version_id)
            ->first();

        if (
            ! $schema
            || (int) $schema->product_definition_id
                !== $definitionId
            || $schema->status
                !== ProductSchemaStatus::Published
        ) {
            throw new DomainException(
                'El binding no pertenece al schema publicado actual del producto.'
            );
        }

        $this->assertTypedPayload(
            $binding->value_type
        );
    }

    public function canonicalValue(): bool|int|string
    {
        $binding = $this->attributeBinding()
            ->firstOrFail();

        $this->assertTypedPayload(
            $binding->value_type
        );

        return match ($binding->value_type) {
            AttributeValueType::Text =>
                (string) $this->value_text,
            AttributeValueType::Boolean =>
                (bool) $this->value_boolean,
            AttributeValueType::Integer =>
                (int) $this->value_integer,
            AttributeValueType::ExactDecimal,
            AttributeValueType::Measurement =>
                (string) $this->value_decimal,
            AttributeValueType::Date =>
                (string) $this->value_date,
            AttributeValueType::DateTime =>
                $this->canonicalDateTime(),
        };
    }

    private function assertTypedPayload(
        AttributeValueType $type
    ): void {
        $columns = [
            'value_text',
            'value_boolean',
            'value_integer',
            'value_decimal',
            'value_date',
            'value_datetime',
        ];

        $expected = match ($type) {
            AttributeValueType::Text => 'value_text',
            AttributeValueType::Boolean => 'value_boolean',
            AttributeValueType::Integer => 'value_integer',
            AttributeValueType::ExactDecimal,
            AttributeValueType::Measurement => 'value_decimal',
            AttributeValueType::Date => 'value_date',
            AttributeValueType::DateTime => 'value_datetime',
        };

        foreach ($columns as $column) {
            $present = $this->getAttribute($column) !== null;

            if (
                ($column === $expected && ! $present)
                || ($column !== $expected && $present)
            ) {
                throw new DomainException(
                    'El payload tipado del valor semántico no coincide con su binding.'
                );
            }
        }
    }

    private function canonicalDateTime(): string
    {
        $stored = (string) $this->value_datetime;

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2} '
                . '\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',
                $stored
            ) !== 1
        ) {
            throw new DomainException(
                'El datetime semántico almacenado no es canónico.'
            );
        }

        [$date, $time] = explode(' ', $stored, 2);

        if (! str_contains($time, '.')) {
            $time .= '.000000';
        } else {
            [$seconds, $fraction] = explode('.', $time, 2);
            $time = $seconds.'.'.str_pad(
                substr($fraction, 0, 6),
                6,
                '0'
            );
        }

        return $date.'T'.$time.'Z';
    }
}
