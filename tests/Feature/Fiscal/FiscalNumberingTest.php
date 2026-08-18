<?php
namespace Tests\Feature\Fiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;use Illuminate\Support\Facades\Schema;use Tests\TestCase;
class FiscalNumberingTest extends TestCase {use RefreshDatabase;public function test_numbering_is_a_separate_unique_append_only_fiscal_fact():void{$this->assertTrue(Schema::hasColumns('fiscal_document_numbers',['fiscal_document_id','fiscal_point_of_sale_id','environment','number']));$this->assertFalse(Schema::hasColumn('commerce_sales','fiscal_number'));}}
