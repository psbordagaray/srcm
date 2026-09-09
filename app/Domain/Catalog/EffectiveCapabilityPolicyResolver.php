<?php

namespace App\Domain\Catalog;

use App\Enums\SemanticCapabilityActivationMode;
use App\Enums\SemanticCapabilityStatus;
use App\Enums\SemanticProfileResolutionMode;
use App\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EffectiveCapabilityPolicyResolver
{
    /**
     * @return list<EffectiveCapabilityPolicy>
     */
    public function currentForOrganization(
        EffectiveSemanticProfile $profile,
        Organization $organization
    ): array {
        if (
            $profile->resolutionMode
                !== SemanticProfileResolutionMode::CurrentPublished
        ) {
            throw new DomainException(
                'La política operacional corriente requiere un perfil CURRENT_PUBLISHED.'
            );
        }

        $organizationId = (int) $organization->getKey();

        if ($organizationId <= 0) {
            throw new DomainException(
                'La Organization solicitada posee una identidad inválida.'
            );
        }

        if (
            ! DB::table('organizations')
                ->where('id', $organizationId)
                ->exists()
        ) {
            throw new DomainException(
                'La Organization solicitada no existe.'
            );
        }

        return $this->resolve(
            $profile,
            $organizationId
        );
    }

    /**
     * @return list<EffectiveCapabilityPolicy>
     */
    public function schemaDefaults(
        EffectiveSemanticProfile $profile
    ): array {
        return $this->resolve($profile, null);
    }

    /**
     * @return list<EffectiveCapabilityPolicy>
     */
    private function resolve(
        EffectiveSemanticProfile $profile,
        ?int $organizationId
    ): array {
        $policies = [];
        $seenIds = [];
        $seenKeys = [];

        foreach ($profile->capabilities as $capability) {
            if (! $capability instanceof EffectiveSemanticCapability) {
                throw new DomainException(
                    'El perfil contiene una capability projection inválida.'
                );
            }

            if (
                isset(
                    $seenIds[$capability->semanticCapabilityDefinitionId]
                )
                || isset(
                    $seenKeys[$capability->semanticCapabilityKey]
                )
            ) {
                throw new DomainException(
                    'La política efectiva recibió identidad de capability duplicada.'
                );
            }

            $seenIds[$capability->semanticCapabilityDefinitionId] = true;
            $seenKeys[$capability->semanticCapabilityKey] = true;

            $policies[] = $this->resolveOne(
                $profile,
                $capability,
                $organizationId
            );
        }

        return $policies;
    }

    private function resolveOne(
        EffectiveSemanticProfile $profile,
        EffectiveSemanticCapability $capability,
        ?int $organizationId
    ): EffectiveCapabilityPolicy {
        $rows = DB::table(
            'catalog_product_schema_capability_declarations'
        )
            ->where('id', $capability->declarationId)
            ->where(
                'product_schema_version_id',
                $profile->productSchemaVersionId
            )
            ->where(
                'semantic_capability_definition_id',
                $capability->semanticCapabilityDefinitionId
            )
            ->get();

        if ($rows->count() !== 1) {
            throw new DomainException(
                'La capability declaration efectiva no puede resolverse de forma única.'
            );
        }

        $declaration = $rows->first();

        if (! $declaration instanceof stdClass) {
            throw new DomainException(
                'La capability declaration efectiva no existe.'
            );
        }

        $definition = DB::table(
            'catalog_semantic_capability_definitions'
        )
            ->where(
                'id',
                $capability->semanticCapabilityDefinitionId
            )
            ->first();

        if (! $definition instanceof stdClass) {
            throw new DomainException(
                'La capability declaration referencia una definición inexistente.'
            );
        }

        $key = (string) $definition->key;
        SemanticKey::assertValid($key);

        if ($key !== $capability->semanticCapabilityKey) {
            throw new DomainException(
                'La semantic capability key no coincide con el perfil efectivo.'
            );
        }

        if (
            ! SemanticCapabilityStatus::tryFrom(
                (string) $definition->status
            )
        ) {
            throw new DomainException(
                'La capability posee un lifecycle inválido.'
            );
        }

        $mode = SemanticCapabilityActivationMode::tryFrom(
            (string) $declaration->activation_mode
        );

        if (
            ! $mode
            || $mode !== $capability->activationMode
        ) {
            throw new DomainException(
                'La capability declaration posee un activation mode inválido o inconsistente.'
            );
        }

        $defaultEnabled = $this->storedBoolean(
            $declaration->default_enabled,
            'La capability declaration no posee un default boolean válido.'
        );

        if (
            $mode === SemanticCapabilityActivationMode::FixedEnabled
        ) {
            if (! $defaultEnabled) {
                throw new DomainException(
                    'FIXED_ENABLED requiere default_enabled=true.'
                );
            }

            return $this->policy(
                $profile,
                $capability,
                $mode,
                true,
                $defaultEnabled,
                $organizationId,
                null,
                EffectiveCapabilityPolicyProvenance::
                    RESOLVED_SCHEMA_FIXED
            );
        }

        if ($organizationId !== null) {
            $bindings = DB::table(
                'catalog_organization_capability_policy_bindings'
            )
                ->where('organization_id', $organizationId)
                ->where(
                    'semantic_capability_definition_id',
                    $capability->semanticCapabilityDefinitionId
                )
                ->orderBy('id')
                ->get();

            if ($bindings->count() > 1) {
                throw new DomainException(
                    'La Organization posee bindings duplicados para la capability.'
                );
            }

            if ($bindings->count() === 1) {
                $binding = $bindings->first();

                if (! $binding instanceof stdClass) {
                    throw new DomainException(
                        'No se pudo resolver el organization capability binding.'
                    );
                }

                $bindingId = (int) $binding->id;

                if ($bindingId <= 0) {
                    throw new DomainException(
                        'El organization capability binding posee identidad inválida.'
                    );
                }

                $enabled = $this->storedBoolean(
                    $binding->enabled,
                    'El organization capability binding no posee un boolean válido.'
                );

                return $this->policy(
                    $profile,
                    $capability,
                    $mode,
                    $enabled,
                    $defaultEnabled,
                    $organizationId,
                    $bindingId,
                    EffectiveCapabilityPolicyProvenance::
                        RESOLVED_ORGANIZATION_OVERRIDE
                );
            }
        }

        return $this->policy(
            $profile,
            $capability,
            $mode,
            $defaultEnabled,
            $defaultEnabled,
            $organizationId,
            null,
            EffectiveCapabilityPolicyProvenance::
                RESOLVED_SCHEMA_DEFAULT
        );
    }

    private function policy(
        EffectiveSemanticProfile $profile,
        EffectiveSemanticCapability $capability,
        SemanticCapabilityActivationMode $mode,
        bool $enabled,
        bool $schemaDefaultEnabled,
        ?int $organizationId,
        ?int $bindingId,
        string $resolvedFrom
    ): EffectiveCapabilityPolicy {
        $provenance = new EffectiveCapabilityPolicyProvenance(
            productSchemaVersionId: $profile->productSchemaVersionId,
            productSchemaCapabilityDeclarationId:
                $capability->declarationId,
            semanticCapabilityDefinitionId:
                $capability->semanticCapabilityDefinitionId,
            semanticCapabilityKey:
                $capability->semanticCapabilityKey,
            activationMode: $mode,
            schemaDefaultEnabled: $schemaDefaultEnabled,
            organizationId: $organizationId,
            organizationPolicyBindingId: $bindingId,
            resolvedFrom: $resolvedFrom,
        );

        return new EffectiveCapabilityPolicy(
            semanticCapabilityDefinitionId:
                $capability->semanticCapabilityDefinitionId,
            semanticCapabilityKey:
                $capability->semanticCapabilityKey,
            activationMode: $mode,
            enabled: $enabled,
            resolvedFrom: $resolvedFrom,
            schemaDefaultEnabled: $schemaDefaultEnabled,
            organizationPolicyBindingId: $bindingId,
            provenance: $provenance,
        );
    }

    private function storedBoolean(
        mixed $value,
        string $message
    ): bool {
        return match (true) {
            $value === true,
            $value === 1,
            $value === '1' => true,
            $value === false,
            $value === 0,
            $value === '0' => false,
            default => throw new DomainException($message),
        };
    }
}
