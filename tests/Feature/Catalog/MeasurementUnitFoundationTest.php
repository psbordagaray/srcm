<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\MeasurementDimensionManager;
use App\Domain\Catalog\MeasurementUnitManager;
use App\Enums\MeasurementUnitStatus;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeasurementUnitFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_belongs_to_one_immutable_dimension(): void
    {
        $dimensions = app(MeasurementDimensionManager::class);
        $units = $this->manager();

        $length = $dimensions->create(
            'straleon.measurement.dimension.length',
            'Length'
        );

        $mass = $dimensions->create(
            'straleon.measurement.dimension.mass',
            'Mass'
        );

        $unit = $units->create(
            $length,
            'straleon.measurement.unit.millimeter',
            'Millimeter',
            'mm'
        );

        $this->assertSame(
            MeasurementUnitStatus::Active,
            $unit->status
        );

        $this->assertDomainRejected(function () use ($unit, $mass): void {
            $unit->measurement_dimension_id = $mass->id;
            $unit->save();
        });
    }

    public function test_symbol_is_metadata_not_identity(): void
    {
        $dimension = app(MeasurementDimensionManager::class)->create(
            'straleon.measurement.dimension.temperature',
            'Temperature'
        );

        $units = $this->manager();

        $first = $units->create(
            $dimension,
            'straleon.measurement.unit.temp_alpha',
            'Temperature A',
            'T'
        );

        $second = $units->create(
            $dimension,
            'straleon.measurement.unit.temp_beta',
            'Temperature B',
            'T'
        );

        $this->assertSame($first->symbol, $second->symbol);
        $this->assertNotSame($first->key, $second->key);
    }

    public function test_unit_lifecycle_is_one_way_and_inactive_dimension_rejects_new_units(): void
    {
        $dimensions = app(MeasurementDimensionManager::class);
        $units = $this->manager();

        $dimension = $dimensions->create(
            'straleon.measurement.dimension.area',
            'Area'
        );

        $unit = $units->create(
            $dimension,
            'straleon.measurement.unit.square_meter',
            'Square meter',
            'm2'
        );

        $unit = $units->deprecate($unit);
        $dimension = $dimensions->deprecate($dimension);

        $this->assertDomainRejected(
            fn () => $units->create(
                $dimension,
                'straleon.measurement.unit.square_centimeter',
                'Square centimeter',
                'cm2'
            )
        );

        $unit = $units->retire($unit);

        $this->assertSame(
            MeasurementUnitStatus::Retired,
            $unit->status
        );

        $this->assertDomainRejected(
            fn () => $unit->delete()
        );
    }

    public function test_measurement_unit_table_has_no_conversion_engine(): void
    {
        $columns = Schema::getColumnListing(
            'catalog_measurement_units'
        );

        foreach ([
            'conversion_factor',
            'factor_to_base',
            'factor_to_canonical',
            'offset',
            'formula',
            'canonical_unit_id',
            'inventory_base_unit_code',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    private function manager(): MeasurementUnitManager
    {
        return app(MeasurementUnitManager::class);
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
