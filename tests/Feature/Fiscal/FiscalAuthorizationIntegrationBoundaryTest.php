<?php
namespace Tests\Feature\Fiscal;
use App\Domain\Fiscal\{FiscalAuthorizationCredentialStore,FiscalAuthorizationTransport};use Illuminate\Foundation\Testing\RefreshDatabase;use ReflectionClass;use Tests\TestCase;
class FiscalAuthorizationIntegrationBoundaryTest extends TestCase {use RefreshDatabase;public function test_boundary_uses_contracts_and_carries_no_http_or_credentials_in_fiscal_models():void{$this->assertTrue((new ReflectionClass(FiscalAuthorizationTransport::class))->isInterface());$this->assertTrue((new ReflectionClass(FiscalAuthorizationCredentialStore::class))->isInterface());$this->assertFileDoesNotExist(app_path('Domain/Fiscal/ArcaHttpClient.php'));}}
