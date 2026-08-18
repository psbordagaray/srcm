<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiscalVoucherClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_is_explicit_one_per_document_and_separate_from_sale(): void
    {
        $this->assertTrue(Schema::hasColumns('fiscal_document_classifications', [
            'fiscal_document_id', 'voucher_class', 'voucher_code',
        ]));
        $this->assertFalse(Schema::hasColumn('commerce_sales', 'voucher_class'));
        $this->assertTrue(Schema::hasTable('fiscal_document_classifications'));
    }
}
