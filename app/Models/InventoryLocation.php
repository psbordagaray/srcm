<?php

namespace App\Models;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryLocationType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class InventoryLocation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'name',
        'type',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(
            fn (InventoryLocation $location) =>
                $location->assertInvariants()
        );

        static::updating(
            fn (InventoryLocation $location) =>
                $location->assertInvariants()
        );

        static::deleting(function (): void {
            throw new LogicException(
                'Las ubicaciones no pueden eliminarse físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'type' => InventoryLocationType::class,
            'active' => 'boolean',
        ];
    }

    public function setNameAttribute(string $value): void
    {
        $name = Str::of($value)->squish()->toString();

        $this->attributes['name'] = $name;
        $this->attributes['normalized_name'] =
            static::normalizeName($name);
    }

    public static function normalizeName(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            static::class,
            'parent_id'
        );
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            static::class,
            'parent_id'
        );
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull(
            $this->qualifyColumn('parent_id')
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            $this->qualifyColumn('active'),
            true
        );
    }

    private function assertInvariants(): void
    {
        $organizationId = (int) $this->organization_id;

        if ($organizationId < 1) {
            throw new DomainException(
                'La ubicación requiere una organización activa.'
            );
        }

        if (
            $this->exists
            && $this->isDirty('organization_id')
        ) {
            throw new DomainException(
                'La organización de una ubicación no puede cambiarse.'
            );
        }

        $this->assertUniqueActiveSibling($organizationId);
        $this->assertValidAncestry($organizationId);
        $this->assertCanDeactivate();
    }

    private function assertUniqueActiveSibling(
        int $organizationId
    ): void {
        if (! $this->active) {
            return;
        }

        $duplicate = static::query()
            ->forOrganization($organizationId)
            ->where('parent_id', $this->parent_id)
            ->where(
                'normalized_name',
                $this->normalized_name
            )
            ->where('active', true)
            ->when(
                $this->exists,
                fn (Builder $query) => $query->where(
                    $this->getKeyName(),
                    '!=',
                    $this->getKey()
                )
            )
            ->exists();

        if ($duplicate) {
            throw new DomainException(
                'Ya existe una ubicación activa equivalente en ese nivel.'
            );
        }
    }

    private function assertValidAncestry(
        int $organizationId
    ): void {
        if ($this->parent_id === null) {
            return;
        }

        $ancestorId = (int) $this->parent_id;
        $visited = [];

        while ($ancestorId > 0) {
            if (
                $this->getKey() !== null
                && $ancestorId === (int) $this->getKey()
            ) {
                throw new DomainException(
                    'Una ubicación no puede depender de sí misma.'
                );
            }

            if (isset($visited[$ancestorId])) {
                throw new DomainException(
                    'La jerarquía de ubicaciones contiene un ciclo.'
                );
            }

            $visited[$ancestorId] = true;

            $ancestor = static::query()
                ->forOrganization($organizationId)
                ->whereKey($ancestorId)
                ->first(['id', 'parent_id', 'active']);

            if (! $ancestor) {
                throw new DomainException(
                    'La ubicación padre no pertenece a la organización activa.'
                );
            }

            if ($this->active && ! $ancestor->active) {
                throw new DomainException(
                    'Una ubicación activa no puede depender de una ubicación inactiva.'
                );
            }

            $ancestorId = (int) ($ancestor->parent_id ?? 0);
        }
    }

    private function assertCanDeactivate(): void
    {
        if (
            ! $this->exists
            || ! $this->isDirty('active')
            || $this->active
        ) {
            return;
        }

        if ($this->children()->active()->exists()) {
            throw new DomainException(
                'No puede inactivar una ubicación con descendientes activos.'
            );
        }

        $hasNonZeroBalance = InventoryBalance::query()
            ->forOrganization((int) $this->organization_id)
            ->where('inventory_location_id', $this->getKey())
            ->get(['quantity'])
            ->contains(
                fn (InventoryBalance $balance): bool =>
                    ! InventoryQuantity::equal($balance->quantity, '0')
            );

        if ($hasNonZeroBalance) {
            throw new DomainException(
                'No puede inactivar una ubicación con saldo físico distinto de cero.'
            );
        }
    }
}
