<?php
namespace Tests\Feature\Fiscal;
use App\Domain\Fiscal\{FiscalDocumentData,FiscalDocumentManager,FiscalOrganizationProfileData,FiscalOrganizationProfileManager,FiscalPointOfSaleData,FiscalPointOfSaleManager};
use App\Enums\{FiscalDocumentState,FiscalDocumentType,FiscalEnvironment,FiscalIntegrationMode,UserRole};
use App\Models\{CommerceSale,FiscalDocument,Organization,OrganizationMembership,User};
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class FiscalDocumentCoreTest extends TestCase {
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }
 public function test_document_core_schema_is_separate_from_sale_and_has_no_authorization_table(): void {
  $this->assertTrue(Schema::hasColumns('fiscal_documents',['public_id','commerce_sale_id','issuer_snapshot','recipient_snapshot','document_type','fingerprint']));
  $this->assertTrue(Schema::hasColumns('fiscal_document_lines',['fiscal_document_id','commerce_sale_line_id','quantity','line_total_minor']));
  $this->assertFalse(Schema::hasColumn('commerce_sales','fiscal_document_id'));
  $this->assertFalse(Schema::hasTable('fiscal_authorizations'));
  $this->assertSame('pending',FiscalDocumentState::Pending->value); $this->assertSame('invoice',FiscalDocumentType::Invoice->value);
 }
 public function test_manager_requires_confirmed_sale_and_does_not_create_a_document_from_building_sale(): void {
  [$admin,$point]=$this->fiscalConfiguration(); $sale=$this->buildingSale($admin,1,'building-sale');
  $this->expectException(DomainException::class);
  app(FiscalDocumentManager::class)->record(new FiscalDocumentData($sale->id,$point->id,FiscalDocumentType::Invoice,'fiscal:building'),$admin);
 }
 public function test_persisted_document_has_derived_pending_state_and_database_immutability(): void {
  [$admin,$point]=$this->fiscalConfiguration(); $sale=$this->buildingSale($admin,2,'snapshot-sale');
  $document=FiscalDocument::query()->create(['organization_id'=>$admin->current_organization_id,'fiscal_organization_profile_id'=>$point->fiscal_organization_profile_id,'fiscal_point_of_sale_id'=>$point->id,'commerce_sale_id'=>$sale->id,'document_type'=>FiscalDocumentType::Invoice,'issuer_snapshot'=>['legal_name'=>'Empresa Fiscal'],'recipient_snapshot'=>['name'=>'Consumidor final'],'currency_code'=>'ARS','service_subtotal_minor'=>0,'product_subtotal_minor'=>1,'total_minor'=>1,'documented_at'=>now(),'created_by_user_id'=>$admin->id,'idempotency_key'=>'manual-snapshot','fingerprint'=>str_repeat('c',64)]);
  $this->assertSame(FiscalDocumentState::Pending,$document->state());
  try { DB::table('fiscal_documents')->where('id',$document->id)->update(['total_minor'=>2]); $this->fail('Se esperaba el trigger de inmutabilidad.'); } catch (QueryException) { $this->addToAssertionCount(1); }
 }
 private function buildingSale(User $admin,int $number,string $key): CommerceSale {
  // Fixture aislado: la guarda comercial exige evidencia de checkout incluso
  // para una venta en preparación. La prueba necesita justamente ese estado
  // inválido para el documento fiscal, sin debilitar la guarda productiva.
  DB::unprepared('DROP TRIGGER IF EXISTS commerce_sales_guard_insert');
  return CommerceSale::query()->create(['organization_id'=>$admin->current_organization_id,'sale_number'=>$number,'status'=>'building','customer_name_snapshot'=>'Consumidor final','currency_code'=>'ARS','service_subtotal_minor'=>0,'product_subtotal_minor'=>1,'total_minor'=>1,'recorded_by_user_id'=>$admin->id,'sold_at'=>now(),'idempotency_key'=>$key,'fingerprint'=>str_repeat('a',64)]);
 }
 private function fiscalConfiguration(): array {
  $organization=Organization::query()->where('slug','sulu-tv')->firstOrFail(); $admin=User::factory()->create(['role'=>UserRole::Admin,'current_organization_id'=>$organization->id]);
  OrganizationMembership::withoutEvents(fn()=>OrganizationMembership::query()->updateOrCreate(['organization_id'=>$organization->id,'user_id'=>$admin->id],['role'=>UserRole::Admin,'active'=>true]));
  app(FiscalOrganizationProfileManager::class)->save(new FiscalOrganizationProfileData('Empresa Fiscal','20-12345678-6','1',null,'2020-01-01','Calle 1','Córdoba','AR-C','5000'),$admin);
  return [$admin,app(FiscalPointOfSaleManager::class)->create(new FiscalPointOfSaleData(1,FiscalEnvironment::Homologation,FiscalIntegrationMode::WsfeV1),$admin)];
 }
}
