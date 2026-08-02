<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceDiagnosticData;
use App\Domain\Service\ServiceDiagnosticFindingData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceDiagnostic;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceAssessmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_assessment_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('service_diagnostics', [
            'organization_id',
            'service_order_id',
            'revision',
            'summary',
            'recommendation',
            'data_risk_notes',
            'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasTable('service_diagnostic_findings'));
        $this->assertTrue(Schema::hasTable('service_quotes'));
        $this->assertTrue(Schema::hasTable('service_quote_options'));
        $this->assertTrue(Schema::hasTable('service_quote_lines'));
        $this->assertTrue(Schema::hasTable('service_quote_decisions'));

        $this->assertTrue(UserRole::Admin->canRecordServiceDiagnostics());
        $this->assertTrue(UserRole::Operator->canRecordServiceDiagnostics());
        $this->assertFalse(UserRole::Viewer->canRecordServiceDiagnostics());
        $this->assertTrue(UserRole::Admin->canIssueServiceQuotes());
        $this->assertTrue(UserRole::Operator->canIssueServiceQuotes());
        $this->assertFalse(UserRole::Viewer->canIssueServiceQuotes());
        $this->assertTrue(
            UserRole::Admin->canRecordServiceQuoteDecisions()
        );
        $this->assertFalse(
            UserRole::Viewer->canRecordServiceQuoteDecisions()
        );
    }

    public function test_diagnosis_is_separate_versioned_and_preserves_intake(): void
    {
        [$organization, $actor, $order] = $this->order();
        $manager = app(ServiceAssessmentManager::class);

        $diagnostic = $manager->recordDiagnostic(
            $this->diagnosticData($order),
            $actor
        );

        $this->assertSame(1, (int) $diagnostic->revision);
        $this->assertSame(2, $diagnostic->findings->count());
        $this->assertSame(
            ServiceFindingSeverity::Critical,
            $diagnostic->findings->last()->severity
        );
        $this->assertSame(
            'El cliente informa lentitud.',
            $order->intake->customer_reported_issue
        );
        $this->assertSame(
            ServiceOrderStatus::Diagnosing,
            $order->fresh()->status
        );
        $this->assertDatabaseHas('service_order_status_histories', [
            'organization_id' => $organization->id,
            'service_order_id' => $order->id,
            'from_status' => ServiceOrderStatus::Received->value,
            'to_status' => ServiceOrderStatus::Diagnosing->value,
        ]);

        $this->assertDomainFailure(function () use ($diagnostic): void {
            $diagnostic->summary = 'Alterado';
            $diagnostic->save();
        });
        $this->assertQueryRejected(
            fn () => DB::table('service_diagnostic_findings')
                ->where('id', $diagnostic->findings->first()->id)
                ->update(['description' => 'Alterado'])
        );
    }

    public function test_diagnosis_idempotency_and_revisions_are_atomic(): void
    {
        [, $actor, $order] = $this->order();
        $manager = app(ServiceAssessmentManager::class);
        $data = $this->diagnosticData($order);

        $first = $manager->recordDiagnostic($data, $actor);
        $retry = $manager->recordDiagnostic($data, $actor);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('service_diagnostics', 1);

        $second = $manager->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'El disco presenta sectores reasignados.',
                recommendation: 'Reemplazar por SSD y migrar los datos.',
                findings: [
                    new ServiceDiagnosticFindingData(
                        ServiceFindingSeverity::Critical,
                        'Almacenamiento',
                        'SMART informa sectores reasignados.',
                        'Captura SMART adjunta al legajo físico.'
                    ),
                ],
                idempotencyKey: 'service:diagnostic:notebook:2',
                dataRiskNotes: 'La lectura intensiva puede agravar la falla.'
            ),
            $actor
        );

        $this->assertSame(2, (int) $second->revision);
        $this->assertDatabaseCount('service_diagnostics', 2);

        $conflict = new ServiceDiagnosticData(
            serviceOrderId: $order->id,
            summary: 'Contenido distinto.',
            recommendation: 'No corresponde.',
            findings: [
                new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Attention,
                    'Conflicto',
                    'Debe rechazarse.'
                ),
            ],
            idempotencyKey: 'service:diagnostic:notebook:2'
        );

        $this->assertDomainFailure(
            fn () => $manager->recordDiagnostic($conflict, $actor)
        );
        $this->assertDatabaseCount('service_diagnostics', 2);
    }

    public function test_quote_preserves_alternatives_and_exact_minor_totals(): void
    {
        [, $actor, $order] = $this->diagnosedOrder();
        $manager = app(ServiceAssessmentManager::class);

        $quote = $manager->issueQuote($this->quoteData($order), $actor);

        $this->assertSame(1, (int) $quote->revision);
        $this->assertSame('ARS', $quote->currency_code);
        $this->assertCount(2, $quote->options);
        $this->assertSame(9500000, $quote->options[0]->total_minor);
        $this->assertSame(11300000, $quote->options[1]->total_minor);
        $this->assertTrue($quote->options[1]->recommended);
        $this->assertSame(
            1800000,
            $quote->options[1]->lines[2]->line_total_minor
        );
        $this->assertSame(
            ServiceOrderStatus::AwaitingApproval,
            $order->fresh()->status
        );

        $retry = $manager->issueQuote($this->quoteData($order), $actor);
        $this->assertSame($quote->id, $retry->id);
        $this->assertDatabaseCount('service_quotes', 1);
        $this->assertDatabaseCount('service_quote_options', 2);
        $this->assertDatabaseCount('service_quote_lines', 5);
    }

    public function test_fractional_minor_amount_rolls_back_quote(): void
    {
        [, $actor, $order] = $this->diagnosedOrder();
        $manager = app(ServiceAssessmentManager::class);
        $invalid = new ServiceQuoteData(
            serviceOrderId: $order->id,
            options: [
                new ServiceQuoteOptionData(
                    label: 'Importe inválido',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Media unidad que produce medio centavo.',
                            '0.5',
                            1
                        ),
                    ]
                ),
            ],
            idempotencyKey: 'service:quote:fractional:1'
        );

        $this->assertDomainFailure(
            fn () => $manager->issueQuote($invalid, $actor)
        );
        $this->assertDatabaseCount('service_quotes', 0);
        $this->assertSame(
            ServiceOrderStatus::Diagnosing,
            $order->fresh()->status
        );
    }

    public function test_approval_records_customer_fact_and_starts_work(): void
    {
        [, $actor, $order, $quote] = $this->quotedOrder();
        $manager = app(ServiceAssessmentManager::class);
        $selected = $quote->options->last();
        $data = new ServiceQuoteDecisionData(
            serviceQuoteId: $quote->id,
            decision: ServiceQuoteDecisionType::Approved,
            customerName: 'Cliente notebook',
            channel: 'WhatsApp',
            idempotencyKey: 'service:decision:notebook:1',
            serviceQuoteOptionId: $selected->id,
            customerReference: '+54 9 3447 000000',
            reason: 'Autoriza SSD, teclado alternativo y backup.'
        );

        $decision = $manager->recordDecision($data, $actor);
        $retry = $manager->recordDecision($data, $actor);

        $this->assertSame($decision->id, $retry->id);
        $this->assertSame(
            ServiceQuoteDecisionType::Approved,
            $decision->decision
        );
        $this->assertSame($selected->id, $decision->selectedOption->id);
        $this->assertSame('WhatsApp', $decision->channel);
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $order->fresh()->status
        );
        $this->assertDatabaseCount('service_quote_decisions', 1);
        $this->assertDatabaseHas('service_order_status_histories', [
            'service_order_id' => $order->id,
            'from_status' => ServiceOrderStatus::AwaitingApproval->value,
            'to_status' => ServiceOrderStatus::InProgress->value,
        ]);

        $this->assertQueryRejected(
            fn () => DB::table('service_quote_decisions')
                ->where('id', $decision->id)
                ->update(['customer_name' => 'Otro nombre'])
        );
    }

    public function test_rejection_returns_to_diagnosis_and_allows_new_revision(): void
    {
        [, $actor, $order, $firstQuote] = $this->quotedOrder();
        $manager = app(ServiceAssessmentManager::class);

        $manager->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $firstQuote->id,
                decision: ServiceQuoteDecisionType::Rejected,
                customerName: 'Cliente notebook',
                channel: 'Mostrador',
                idempotencyKey: 'service:decision:rejected:1',
                reason: 'Prefiere una alternativa sin teclado.'
            ),
            $actor
        );

        $this->assertSame(
            ServiceOrderStatus::Diagnosing,
            $order->fresh()->status
        );

        $secondData = new ServiceQuoteData(
            serviceOrderId: $order->id,
            options: [
                new ServiceQuoteOptionData(
                    label: 'Sólo SSD y backup',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'SSD 480 GB',
                            '1',
                            6000000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Instalación y migración',
                            '1',
                            3500000
                        ),
                    ],
                    recommended: true
                ),
            ],
            idempotencyKey: 'service:quote:notebook:2'
        );
        $secondQuote = $manager->issueQuote($secondData, $actor);

        $this->assertSame(2, (int) $secondQuote->revision);
        $this->assertSame(
            ServiceOrderStatus::AwaitingApproval,
            $order->fresh()->status
        );
        $this->assertDatabaseCount('service_quotes', 2);

        $this->assertDomainFailure(fn () => $manager->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $firstQuote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: 'Cliente notebook',
                channel: 'Teléfono',
                idempotencyKey: 'service:decision:stale:1',
                serviceQuoteOptionId: $firstQuote->options->first()->id
            ),
            $actor
        ));
    }

    public function test_tenant_role_and_database_guards_reject_bypasses(): void
    {
        [$organization, $actor, $order] = $this->order();
        $manager = app(ServiceAssessmentManager::class);
        $other = Organization::query()->create([
            'name' => 'Taller ajeno',
            'slug' => 'taller-ajeno-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $otherActor = $this->user($other, UserRole::Admin);
        $viewer = $this->user($organization, UserRole::Viewer);

        $this->assertDomainFailure(fn () => $manager->recordDiagnostic(
            $this->diagnosticData($order),
            $otherActor
        ));
        $this->assertDomainFailure(fn () => $manager->recordDiagnostic(
            $this->diagnosticData($order),
            $viewer
        ));
        $this->assertDatabaseCount('service_diagnostics', 0);

        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->update([
                    'status' => ServiceOrderStatus::Diagnosing->value,
                ])
        );

        $diagnostic = $manager->recordDiagnostic(
            $this->diagnosticData($order),
            $actor
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_quotes')->insert([
                'organization_id' => $organization->id,
                'service_order_id' => $order->id,
                'service_diagnostic_id' => $diagnostic->id + 1000,
                'revision' => 1,
                'currency_code' => 'ARS',
                'issued_by_user_id' => $actor->id,
                'issued_at' => now(),
                'idempotency_key' => 'invalid-direct-quote',
                'fingerprint' => str_repeat('a', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    /**
     * @return array{Organization, User, ServiceOrder}
     */
    private function order(): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => 'Cliente notebook',
        ]);
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->where(
                'normalized_name',
                InventoryLocation::normalizeName('Recepción')
            )
            ->firstOrFail();
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::Notebook,
                brandName: 'Lenovo',
                modelName: 'IdeaPad 3',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'NB-ASSESSMENT-001'
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'El cliente informa lentitud.',
                idempotencyKey: 'service:intake:assessment:1',
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Teclado con dos teclas faltantes.',
                receivedAccessories: 'Notebook y cargador.'
            ),
            $actor
        );

        return [$organization, $actor, $order];
    }

    /**
     * @return array{Organization, User, ServiceOrder}
     */
    private function diagnosedOrder(): array
    {
        [$organization, $actor, $order] = $this->order();
        app(ServiceAssessmentManager::class)->recordDiagnostic(
            $this->diagnosticData($order),
            $actor
        );

        return [$organization, $actor, $order];
    }

    /**
     * @return array{Organization, User, ServiceOrder, ServiceQuote}
     */
    private function quotedOrder(): array
    {
        [$organization, $actor, $order] = $this->diagnosedOrder();
        $quote = app(ServiceAssessmentManager::class)->issueQuote(
            $this->quoteData($order),
            $actor
        );

        return [$organization, $actor, $order, $quote];
    }

    private function diagnosticData(
        ServiceOrder $order
    ): ServiceDiagnosticData {
        return new ServiceDiagnosticData(
            serviceOrderId: $order->id,
            summary: 'La lentitud proviene del disco mecánico degradado.',
            recommendation: 'Instalar SSD y respaldar los datos.',
            findings: [
                new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Attention,
                    'Teclado',
                    'Faltan dos teclas; el teclado es una pieza única.'
                ),
                new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Almacenamiento',
                    'El disco mecánico presenta errores SMART.',
                    'Prueba extendida con sectores pendientes.'
                ),
            ],
            idempotencyKey: 'service:diagnostic:notebook:1',
            dataRiskNotes: 'Se requiere autorización para respaldar datos.'
        );
    }

    private function quoteData(ServiceOrder $order): ServiceQuoteData
    {
        return new ServiceQuoteData(
            serviceOrderId: $order->id,
            options: [
                new ServiceQuoteOptionData(
                    label: 'SSD y reinstalación',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'SSD 480 GB',
                            '1',
                            6000000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Instalación y configuración',
                            '1',
                            3500000
                        ),
                    ],
                    description: 'Conserva el teclado actual.'
                ),
                new ServiceQuoteOptionData(
                    label: 'Solución integral',
                    lines: [
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'SSD 480 GB',
                            '1',
                            6000000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Labor,
                            'Instalación y configuración',
                            '1',
                            3500000
                        ),
                        new ServiceQuoteLineData(
                            ServiceQuoteLineType::Part,
                            'Teclado alternativo compatible',
                            '1',
                            1800000
                        ),
                    ],
                    description: 'Aprovecha el mismo desarme.',
                    recommended: true
                ),
            ],
            idempotencyKey: 'service:quote:notebook:1',
            terms: 'Importes sujetos a disponibilidad de repuestos.'
        );
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una excepción de dominio.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('La base de datos aceptó una operación inválida.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
