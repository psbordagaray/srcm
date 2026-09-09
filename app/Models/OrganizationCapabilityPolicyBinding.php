<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationCapabilityPolicyBinding extends Model
{
    protected $table = 'catalog_organization_capability_policy_bindings';

    protected $fillable = [
        'organization_id',
        'semantic_capability_definition_id',
        'enabled',
    ];

    protected static function booted(): void
    {
        static::updating(function (
            OrganizationCapabilityPolicyBinding $binding
        ): void {
            if (
                $binding->isDirty([
                    'organization_id',
                    'semantic_capability_definition_id',
                ])
            ) {
                throw new DomainException(
                    'La identidad de un organization capability policy binding es inmutable.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function capabilityDefinition(): BelongsTo
    {
        return $this->belongsTo(
            SemanticCapabilityDefinition::class,
            'semantic_capability_definition_id'
        );
    }
}
