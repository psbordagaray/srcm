<?php

namespace App\Models;

use App\Enums\SemanticCapabilityStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SemanticCapabilityDefinition extends Model
{
    protected $table = 'catalog_semantic_capability_definitions';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (
            SemanticCapabilityDefinition $capability
        ): void {
            if ($capability->isDirty('key')) {
                throw new DomainException(
                    'La semantic key de una capability es inmutable.'
                );
            }

            $original = SemanticCapabilityStatus::from(
                (string) $capability->getRawOriginal('status')
            );

            if ($original === SemanticCapabilityStatus::Retired) {
                throw new DomainException(
                    'Una capability semántica retirada es inmutable.'
                );
            }

            if (! $capability->isDirty('status')) {
                return;
            }

            $next = $capability->status;

            $valid = match ($original) {
                SemanticCapabilityStatus::Active =>
                    $next === SemanticCapabilityStatus::Deprecated,
                SemanticCapabilityStatus::Deprecated =>
                    $next === SemanticCapabilityStatus::Retired,
                SemanticCapabilityStatus::Retired => false,
            };

            if (! $valid) {
                throw new DomainException(
                    'Transición de estado inválida para la capability semántica.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una capability semántica no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => SemanticCapabilityStatus::class,
        ];
    }

    public function schemaDeclarations(): HasMany
    {
        return $this->hasMany(
            ProductSchemaCapabilityDeclaration::class,
            'semantic_capability_definition_id'
        );
    }

    public function organizationPolicyBindings(): HasMany
    {
        return $this->hasMany(
            OrganizationCapabilityPolicyBinding::class,
            'semantic_capability_definition_id'
        );
    }
}
