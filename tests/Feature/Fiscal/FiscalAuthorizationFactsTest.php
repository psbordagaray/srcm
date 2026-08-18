<?php
namespace Tests\Feature\Fiscal;
use App\Enums\{FiscalAuthorizationOutcome,FiscalDocumentState};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class FiscalAuthorizationFactsTest extends TestCase {
 use RefreshDatabase;
 public function test_authorization_facts_are_append_only_schema_and_document_state_vocabulary_is_explicit(): void {
  $this->assertTrue(Schema::hasColumns('fiscal_authorization_attempts',['fiscal_document_id','attempt_number','idempotency_key','fingerprint']));
  $this->assertTrue(Schema::hasColumns('fiscal_authorization_responses',['fiscal_authorization_attempt_id','outcome','result_code','received_at']));
  $this->assertSame('authorized',FiscalAuthorizationOutcome::Authorized->value);
  $this->assertSame('authorized',FiscalDocumentState::Authorized->value);
  $this->assertSame('contingency',FiscalDocumentState::Contingency->value);
 }
}
