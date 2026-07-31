<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') !== null) {
                return;
            }

            $organizationId = app(
                CurrentOrganization::class
            )->idOrNull();

            if ($organizationId !== null) {
                $model->setAttribute(
                    'organization_id',
                    $organizationId
                );
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(
        Builder $query,
        Organization|int $organization
    ): Builder {
        return $query->where(
            $this->qualifyColumn('organization_id'),
            $organization instanceof Organization
                ? $organization->getKey()
                : $organization
        );
    }

    public function resolveRouteBindingQuery(
        $query,
        $value,
        $field = null
    ) {
        $organizationId = app(
            CurrentOrganization::class
        )->idOrNull();

        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(
                $field ?? $this->getRouteKeyName(),
                $value
            )
            ->forOrganization($organizationId);
    }
}
