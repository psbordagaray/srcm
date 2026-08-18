<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalDocumentConcept;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiscalDocumentConceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_concept_and_service_period_are_explicit_and_separate_from_sale(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_concepts', [
            'fiscal_document_id', 'concept', 'service_period_from', 'service_period_to',
        ]));
        $this->assertFalse(Schema::hasColumn('commerce_sales', 'service_period_from'));
        $this->assertTrue(FiscalDocumentConcept::Services->requiresServicePeriod());
        $this->assertFalse(FiscalDocumentConcept::Products->requiresServicePeriod());
    }
}
