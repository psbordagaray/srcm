<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\MeasurementDimensionManager;
use App\Domain\Catalog\MeasurementUnitManager;
use App\Enums\MeasurementDimensionStatus;
use App\Models\MeasurementDimension;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeasurementDimensionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dimension_uses_global_semantic_identity(): void
    {
        $manager = $this->manager();

        $dimension = $manager->create(
            'straleon.measurement.dimension.mass',
            'Mass'
        );

        $this->assertSame(
            MeasurementDimensionStatus::Active,
            $dimension->status
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                'straleon.measurement.dimension.mass',
                'Duplicate'
            )
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                'Invalid Dimension',
                'Invalid'
            )
        );

        $this->assertDomainRejected(function () use ($dimension): void {
            $dimension->key = 'straleon.measurement.dimension.other';
            $dimension->save();
        });
    }

    public function test_dimension_lifecycle_is_one_way_and_non_destructive(): void
    {
        $manager = $this->manager();
        $dimension = $manager->create(
            'straleon.measurement.dimension.length',
            'Length'
        );

        $dimension = $manager->deprecate($dimension);

        $this->assertSame(
            MeasurementDimensionStatus::Deprecated,
            $dimension->status
        );

        $dimension = $manager->retire($dimension);

        $this->assertSame(
            MeasurementDimensionStatus::Retired,
            $dimension->status
        );

        $this->assertDomainRejected(
            fn () => $dimension->delete()
        );

        $this->assertDatabaseHas(
            'catalog_measurement_dimensions',
            ['id' => $dimension->id]
        );
    }

    public function test_dimension_cannot_deprecate_or_retire_a_live_unit_tree(): void
    {
        $dimensionManager = $this->manager();
        $unitManager = app(MeasurementUnitManager::class);

        $dimension = $dimensionManager->create(
            'straleon.measurement.dimension.volume',
            'Volume'
        );

        $unit = $unitManager->create(
            $dimension,
            'straleon.measurement.unit.liter',
            'Liter',
            'L'
        );

        $this->assertDomainRejected(
            fn () => $dimensionManager->deprecate($dimension)
        );

        $unit = $unitManager->deprecate($unit);
        $dimension = $dimensionManager->deprecate($dimension);

        $this->assertDomainRejected(
            fn () => $dimensionManager->retire($dimension)
        );

        $unitManager->retire($unit);
        $dimension = $dimensionManager->retire($dimension);

        $this->assertSame(
            MeasurementDimensionStatus::Retired,
            $dimension->status
        );
    }

    private function manager(): MeasurementDimensionManager
    {
        return app(MeasurementDimensionManager::class);
    }

    private function assertDomainRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba DomainException.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
