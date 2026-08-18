<?php
namespace Tests\Feature\Fiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;use Illuminate\Support\Facades\Schema;use Tests\TestCase;
class FiscalRecipientTaxPolicyTest extends TestCase {use RefreshDatabase;public function test_recipient_profile_and_versioned_tax_policy_are_explicit_schema():void{$this->assertTrue(Schema::hasColumns('fiscal_business_party_profiles',['business_party_id','tax_id','vat_condition_code']));$this->assertTrue(Schema::hasColumns('fiscal_tax_policies',['version','effective_from','default_tax_treatment']));}}
