<?php

namespace App\Models;

use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticCapabilityActivationMode;
use App\Enums\SemanticCapabilityStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSchemaCapabilityDeclaration extends Model
{
    protected $table = 'catalog_product_schema_capability_declarations';

    protected $fillable = [
        'product_schema_version_id',
        'semantic_capability_definition_id',
        'activation_mode',
        'default_enabled',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            ProductSchemaCapabilityDeclaration $declaration
        ): void {
            static::assertDraft($declaration);
            static::assertConfiguration($declaration);
        });

        static::updating(function (
            ProductSchemaCapabilityDeclaration $declaration
        ): void {
            if (
                $declaration->isDirty([
                    'product_schema_version_id',
                    'semantic_capability_definition_id',
                ])
            ) {
                throw new DomainException(
                    'La identidad de una capability declaration es inmutable.'
                );
            }

            static::assertDraft($declaration);

            if (
                $declaration->isDirty([
                    'activation_mode',
                    'default_enabled',
                ])
            ) {
                static::assertConfiguration($declaration);
            }
        });

        static::deleting(function (
            ProductSchemaCapabilityDeclaration $declaration
        ): void {
            static::assertDraft($declaration);
        });
    }

    protected function casts(): array
    {
        return [
            'activation_mode' => SemanticCapabilityActivationMode::class,
            'default_enabled' => 'boolean',
        ];
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(
            ProductSchemaVersion::class,
            'product_schema_version_id'
        );
    }

    public function capabilityDefinition(): BelongsTo
    {
        return $this->belongsTo(
            SemanticCapabilityDefinition::class,
            'semantic_capability_definition_id'
        );
    }

    private static function assertDraft(
        ProductSchemaCapabilityDeclaration $declaration
    ): void {
        $schema = ProductSchemaVersion::query()
            ->whereKey($declaration->product_schema_version_id)
            ->first();

        if (
            ! $schema
            || $schema->status !== ProductSchemaStatus::Draft
        ) {
            throw new DomainException(
                'Las capability declarations sólo pueden mutarse mientras el schema está en draft.'
            );
        }
    }

    private static function assertConfiguration(
        ProductSchemaCapabilityDeclaration $declaration
    ): void {
        $capability = SemanticCapabilityDefinition::query()
            ->whereKey($declaration->semantic_capability_definition_id)
            ->first();

        if (
            ! $capability
            || $capability->status !== SemanticCapabilityStatus::Active
        ) {
            throw new DomainException(
                'Una capability declaration nueva o reconfigurada requiere una capability activa.'
            );
        }

        $mode = $declaration->activation_mode
            instanceof SemanticCapabilityActivationMode
            ? $declaration->activation_mode
            : SemanticCapabilityActivationMode::tryFrom(
                (string) $declaration->activation_mode
            );

        if (! $mode) {
            throw new DomainException(
                'La capability declaration posee un activation mode inválido.'
            );
        }

        if (
            $mode === SemanticCapabilityActivationMode::FixedEnabled
            && $declaration->default_enabled !== true
            && $declaration->default_enabled !== 1
            && $declaration->default_enabled !== '1'
        ) {
            throw new DomainException(
                'FIXED_ENABLED requiere default_enabled=true.'
            );
        }
    }
}
