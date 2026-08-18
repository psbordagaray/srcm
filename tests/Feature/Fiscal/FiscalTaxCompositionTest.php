<?php
namespace Tests\Feature\Fiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;use Illuminate\Support\Facades\Schema;use Tests\TestCase;
class FiscalTaxCompositionTest extends TestCase {use RefreshDatabase;public function test_tax_composition_is_explicit_and_separate_from_commerce_sale():void{$this->assertTrue(Schema::hasColumns('fiscal_document_taxes',['fiscal_document_id','tax_code','taxable_base_minor','rate_basis_points','tax_amount_minor']));$this->assertFalse(Schema::hasColumn('commerce_sales','tax_amount_minor'));}}
