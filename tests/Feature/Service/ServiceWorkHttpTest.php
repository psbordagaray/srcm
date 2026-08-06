<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceCompletionManager;
use App\Domain\Service\ServiceDeliveryData;
use App\Domain\Service\ServiceDiagnosticData;
use App\Domain\Service\ServiceDiagnosticFindingData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Domain\Service\ServiceQualityCheckData;
use App\Domain\Service\ServiceQualityInspectionData;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Domain\Service\ServiceWorkItemData;
use App\Domain\Service\ServiceWorkManager;
use App\Domain\Service\ServiceWorkReportData;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQualityOutcome;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\ServiceWorkStatus;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWarrantyClaim;
use App\Models\ServiceWarrantyGrant;
use App\Models\ServiceWorkItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceWorkHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_are_explicit_and_viewer_is_read_only(): void
    {
        $fixture = $this->approvedOrder('routes');
        $viewer = $this->user($fixture['organization'], UserRole::Viewer);
        $routeAbilities = [
            'service-orders.work-items.create' => [
                'GET',
                'can:plan-service-work',
            ],
            'service-orders.work-items.store' => [
                'POST',
                'can:plan-service-work',
            ],
            'service-orders.work-items.start' => [
                'POST',
                'can:execute-service-work',
            ],
            'service-orders.work-items.dispatch.create' => [
                'GET',
                'can:transfer-service-custody',
            ],
            'service-orders.work-items.dispatch.store' => [
                'POST',
                'can:transfer-service-custody',
            ],
            'service-orders.work-items.return.create' => [
                'GET',
                'can:transfer-service-custody',
            ],
            'service-orders.work-items.return.store' => [
                'POST',
                'can:transfer-service-custody',
            ],
            'service-orders.work-items.report.create' => [
                'GET',
                'can:execute-service-work',
            ],
            'service-orders.work-items.report.store' => [
                'POST',
                'can:execute-service-work',
            ],
        ];

        foreach ($routeAbilities as $name => [$method, $ability]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains($method, $route->methods());
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
            $this->assertContains($ability, $route->gatherMiddleware());
        }

        $this->actingAs($viewer)
            ->get(route(
                'service-orders.work-items.create',
                $fixture['order']
            ))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route(
                'service-orders.work-items.store',
                $fixture['order']
            ), $this->workPayload($viewer))
            ->assertForbidden();

        $this->assertDatabaseCount('service_work_items', 0);
    }

    public function test_operator_plans_starts_and_reports_internal_work(): void
    {
        $fixture = $this->approvedOrder('internal-http');

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.work-items.create',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Planificar trabajo')
            ->assertSee('Presupuesto aprobado')
            ->assertSee($fixture['option']->label);

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $fixture['order']
            ), $this->workPayload($fixture['operator']))
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $work = ServiceWorkItem::query()->sole();
        $this->assertSame(ServiceWorkStatus::Planned, $work->status);
        $this->assertSame($fixture['operator']->id, $work->assigned_user_id);
        $this->assertSame($fixture['option']->id, $work->service_quote_option_id);

        $this->actingAs($fixture['operator'])
            ->get(route('service-orders.show', $fixture['order']))
            ->assertOk()
            ->assertSee('Trabajos y custodia')
            ->assertSee('Diagnóstico interno y reparación');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.start',
                [$fixture['order'], $work]
            ), [
                'idempotency_key' => 'service-ui:work-start:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ));

        $this->assertSame(
            ServiceWorkStatus::InProgress,
            $work->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.work-items.report.create',
                [$fixture['order'], $work]
            ))
            ->assertOk()
            ->assertSee('Cerrar trabajo');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.report.store',
                [$fixture['order'], $work]
            ), [
                'outcome' => ServiceWorkOutcome::Completed->value,
                'result_summary' => 'Equipo estable y operativo.',
                'work_performed' => 'Se reparó el circuito y se realizaron pruebas completas.',
                'warranty_days' => 60,
                'warranty_terms' => 'Garantía sobre la intervención realizada.',
                'idempotency_key' => 'service-ui:work-report:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ServiceWorkStatus::Completed,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $fixture['order']->fresh()->status
        );
        $this->assertDatabaseCount('service_work_reports', 1);
        $this->assertDatabaseCount('service_work_status_histories', 3);
    }

    public function test_external_dispatch_return_and_result_are_tenant_scoped(): void
    {
        $fixture = $this->approvedOrder('external-http');
        $provider = $this->party(
            $fixture['organization'],
            'Horacio servicio técnico'
        );
        $payload = $this->workPayload(
            $fixture['operator'],
            [
                'execution_mode' => ServiceWorkExecutionMode::External->value,
                'assigned_user_id' => null,
                'provider_business_party_id' => $provider->id,
                'title' => 'Reparación especializada de placa',
            ]
        );

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $fixture['order']
            ), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $work = ServiceWorkItem::query()->sole();

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.work-items.dispatch.create',
                [$fixture['order'], $work]
            ))
            ->assertOk()
            ->assertSee('Entregar a especialista externo')
            ->assertSee($provider->name);

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.dispatch.store',
                [$fixture['order'], $work]
            ), $this->custodyPayload('dispatch'))
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ServiceWorkStatus::WithProvider,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::WithExternalProvider,
            $fixture['order']->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.work-items.return.create',
                [$fixture['order'], $work]
            ))
            ->assertOk()
            ->assertSee('Registrar retorno del especialista');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.return.store',
                [$fixture['order'], $work]
            ), $this->custodyPayload('return'))
            ->assertRedirect(route(
                'service-orders.show',
                $fixture['order']
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ServiceWorkStatus::InProgress,
            $work->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $fixture['order']->fresh()->status
        );
        $this->assertDatabaseCount('service_work_custody_links', 2);

        $foreign = $this->newOrganization('Servicio HTTP ajeno');
        $foreignOperator = $this->user($foreign, UserRole::Operator);

        $this->actingAs($foreignOperator)
            ->get(route(
                'service-orders.work-items.report.create',
                [$fixture['order'], $work]
            ))
            ->assertNotFound();

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.report.store',
                [$fixture['order'], $work]
            ), [
                'outcome' => ServiceWorkOutcome::Completed->value,
                'result_summary' => 'Trabajo externo recibido y verificado.',
                'work_performed' => 'Reparación de placa realizada por el especialista.',
                'idempotency_key' => 'service-ui:work-report:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ServiceOrderStatus::QualityControl,
            $fixture['order']->fresh()->status
        );
    }

    public function test_validation_and_nested_guards_reject_foreign_assignments(): void
    {
        $fixture = $this->approvedOrder('guards-a');
        $other = $this->approvedOrder('guards-b');
        $foreign = $this->newOrganization('Asignación ajena');
        $foreignUser = $this->user($foreign, UserRole::Operator);
        $foreignProvider = $this->party($foreign, 'Especialista ajeno');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $fixture['order']
            ), $this->workPayload($fixture['operator'], [
                'assigned_user_id' => $foreignUser->id,
            ]))
            ->assertSessionHasErrors('assigned_user_id');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $fixture['order']
            ), $this->workPayload($fixture['operator'], [
                'execution_mode' => ServiceWorkExecutionMode::External->value,
                'assigned_user_id' => null,
                'provider_business_party_id' => $foreignProvider->id,
            ]))
            ->assertSessionHasErrors('provider_business_party_id');

        $this->actingAs($other['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $other['order']
            ), $this->workPayload($other['operator']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $foreignWork = ServiceWorkItem::query()
            ->where('service_order_id', $other['order']->id)
            ->sole();

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.start',
                [$fixture['order'], $foreignWork]
            ), [
                'idempotency_key' => 'service-ui:work-start:'.Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('service_work_items', 1);
    }

    public function test_corrective_warranty_order_uses_resolution_not_quote(): void
    {
        $fixture = $this->deliveredWarranty('corrective-http');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.warranty-claims.store',
                [$fixture['order'], $fixture['warranty']]
            ), $this->claimPayload($fixture))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $claim = ServiceWarrantyClaim::query()->sole();
        $corrective = $claim->correctiveOrder;

        $this->actingAs($fixture['admin'])
            ->post(route(
                'service-orders.warranty-claims.resolution.store',
                [$corrective, $claim]
            ), [
                'outcome' => ServiceWarrantyClaimOutcome::Accepted->value,
                'technical_basis' => 'La falla coincide con la cobertura otorgada.',
                'covered_scope' => 'Corrección integral sin cargo de la falla reclamada.',
                'excluded_scope' => null,
                'exception_reason' => null,
                'notes' => null,
                'idempotency_key' => 'service-ui:warranty-resolution:'.Str::uuid(),
            ])
            ->assertRedirect(route(
                'service-orders.show',
                $corrective
            ));

        $this->assertSame(
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            $claim->fresh()->status
        );
        $this->assertSame(
            ServiceOrderStatus::InProgress,
            $corrective->fresh()->status
        );

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.work-items.create',
                $corrective
            ))
            ->assertOk()
            ->assertSee('Cobertura de garantía')
            ->assertSee('Corrección integral sin cargo');

        $this->actingAs($fixture['operator'])
            ->post(route(
                'service-orders.work-items.store',
                $corrective
            ), $this->workPayload($fixture['operator'], [
                'title' => 'Trabajo correctivo cubierto',
            ]))
            ->assertRedirect(route(
                'service-orders.show',
                $corrective
            ))
            ->assertSessionHasNoErrors();

        $work = ServiceWorkItem::query()
            ->where('service_order_id', $corrective->id)
            ->sole();

        $this->assertNull($work->service_quote_option_id);
        $this->assertSame(
            $claim->resolution->id,
            $work->service_warranty_claim_resolution_id
        );
    }

    /** @param array<string, mixed> $overrides */
    private function workPayload(
        User $assigned,
        array $overrides = []
    ): array {
        return array_replace([
            'title' => 'Diagnóstico interno y reparación',
            'description' => 'Ejecutar el alcance aprobado y documentar el resultado.',
            'execution_mode' => ServiceWorkExecutionMode::Internal->value,
            'assigned_user_id' => $assigned->id,
            'provider_business_party_id' => null,
            'idempotency_key' => 'service-ui:work-plan:'.Str::uuid(),
        ], $overrides);
    }

    private function custodyPayload(string $direction): array
    {
        return [
            'condition_notes' => $direction === 'dispatch'
                ? 'Equipo cerrado, sin golpes nuevos y encendiendo.'
                : 'Equipo retornado armado, encendiendo y sin golpes nuevos.',
            'accessories_snapshot' => 'Equipo y cargador original.',
            'idempotency_key' => 'service-ui:work-'.$direction.':'.Str::uuid(),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function claimPayload(array $fixture): array
    {
        return [
            'intake_location_id' => $fixture['location']->id,
            'claimant_business_party_id' => $fixture['customer']->id,
            'claimant_name' => $fixture['customer']->name,
            'reported_issue' => 'La falla original volvió a presentarse.',
            'reentry_condition_notes' => 'Sin golpes ni daños nuevos.',
            'accessories_snapshot' => 'Equipo sin accesorios.',
            'channel' => 'Presencial',
            'claimed_at' => now()->format('Y-m-d\TH:i'),
            'customer_reference' => 'GAR-WORK-HTTP',
            'idempotency_key' => 'service-ui:warranty-claim:'.Str::uuid(),
        ];
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   order: ServiceOrder,
     *   option: ServiceQuoteOption
     * }
     */
    private function approvedOrder(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        $customer = $this->party(
            $organization,
            'Cliente trabajo '.$suffix
        );
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::Notebook,
                brandName: 'Lenovo',
                modelName: 'IdeaPad '.$suffix,
                identifiers: [new ServiceAssetIdentifierData(
                    ServiceIdentifierType::SerialNumber,
                    'WORK-'.Str::upper(Str::random(12))
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Equipo intermitente y con fallas de encendido.',
                idempotencyKey: 'service:http-work:intake:'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Sin golpes visibles.',
                receivedAccessories: 'Equipo y cargador.'
            ),
            $operator
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Falla electrónica intermitente.',
                recommendation: 'Reparar circuito y verificar estabilidad.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Placa',
                    'La alimentación presenta caídas bajo carga.'
                )],
                idempotencyKey: 'service:http-work:diagnostic:'.$suffix
            ),
            $operator
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Reparación electrónica',
                    lines: [new ServiceQuoteLineData(
                        ServiceQuoteLineType::Labor,
                        'Diagnóstico, reparación y pruebas',
                        '1',
                        5000000
                    )],
                    recommended: true
                )],
                idempotencyKey: 'service:http-work:quote:'.$suffix
            ),
            $operator
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'Presencial',
                idempotencyKey: 'service:http-work:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $operator
        );

        return [
            'organization' => $organization,
            'operator' => $operator,
            'order' => $order->fresh(),
            'option' => $option,
        ];
    }

    /**
     * @return array{
     *   organization: Organization,
     *   operator: User,
     *   admin: User,
     *   order: ServiceOrder,
     *   customer: BusinessParty,
     *   location: InventoryLocation,
     *   warranty: ServiceWarrantyGrant
     * }
     */
    private function deliveredWarranty(string $suffix): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->party(
            $organization,
            'Cliente garantía '.$suffix
        );
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();
        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i',
                identifiers: [new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    str_pad(
                        sprintf('%u', crc32($suffix)),
                        15,
                        '0',
                        STR_PAD_LEFT
                    )
                )],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota y fallas de software.',
                idempotencyKey: 'service:http-work-warranty:intake:'.$suffix,
                customerBusinessPartyId: $customer->id,
                intakeObservations: 'Pantalla anterior no original.',
                receivedAccessories: 'Equipo sin accesorios.'
            ),
            $operator
        );
        $assessment = app(ServiceAssessmentManager::class);
        $assessment->recordDiagnostic(
            new ServiceDiagnosticData(
                serviceOrderId: $order->id,
                summary: 'Módulo dañado y software inestable.',
                recommendation: 'Reemplazar módulo y sanear software.',
                findings: [new ServiceDiagnosticFindingData(
                    ServiceFindingSeverity::Critical,
                    'Pantalla',
                    'El módulo no entrega imagen correctamente.'
                )],
                idempotencyKey: 'service:http-work-warranty:diagnostic:'.$suffix
            ),
            $operator
        );
        $quote = $assessment->issueQuote(
            new ServiceQuoteData(
                serviceOrderId: $order->id,
                options: [new ServiceQuoteOptionData(
                    label: 'Reparación integral',
                    lines: [new ServiceQuoteLineData(
                        ServiceQuoteLineType::Labor,
                        'Cambio de módulo y saneamiento de software',
                        '1',
                        4500000
                    )],
                    recommended: true
                )],
                idempotencyKey: 'service:http-work-warranty:quote:'.$suffix
            ),
            $operator
        );
        $option = $quote->options->sole();
        $assessment->recordDecision(
            new ServiceQuoteDecisionData(
                serviceQuoteId: $quote->id,
                decision: ServiceQuoteDecisionType::Approved,
                customerName: $customer->name,
                channel: 'Presencial',
                idempotencyKey: 'service:http-work-warranty:decision:'.$suffix,
                serviceQuoteOptionId: $option->id
            ),
            $operator
        );
        $workManager = app(ServiceWorkManager::class);
        $work = $workManager->plan(
            new ServiceWorkItemData(
                serviceOrderId: $order->id,
                serviceQuoteOptionId: $option->id,
                title: 'Reparación integral del equipo',
                description: 'Cambio de módulo y limpieza de software.',
                executionMode: ServiceWorkExecutionMode::Internal,
                idempotencyKey: 'service:http-work-warranty:item:'.$suffix,
                assignedUserId: $operator->id
            ),
            $operator
        );
        $workManager->startInternal(
            $work->id,
            'service:http-work-warranty:start:'.$suffix,
            $operator
        );
        $report = $workManager->report(
            new ServiceWorkReportData(
                serviceWorkItemId: $work->id,
                outcome: ServiceWorkOutcome::Completed,
                resultSummary: 'Equipo reparado y estable.',
                workPerformed: 'Módulo instalado y software saneado.',
                idempotencyKey: 'service:http-work-warranty:report:'.$suffix,
                warrantyDays: 90,
                warrantyTerms: 'Garantía sobre mano de obra y componentes instalados.'
            ),
            $operator
        );
        $completion = app(ServiceCompletionManager::class);
        $inspection = $completion->inspect(
            new ServiceQualityInspectionData(
                serviceOrderId: $order->id,
                checks: [
                    new ServiceQualityCheckData(
                        'power',
                        'Encendido y estabilidad',
                        true
                    ),
                    new ServiceQualityCheckData(
                        'display',
                        'Imagen y táctil',
                        true
                    ),
                ],
                conditionNotes: 'Equipo funcional, sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                idempotencyKey: 'service:http-work-warranty:quality:'.$suffix
            ),
            $operator
        );
        $this->assertSame(
            ServiceQualityOutcome::Approved,
            $inspection->outcome
        );
        $delivery = $completion->deliver(
            new ServiceDeliveryData(
                serviceOrderId: $order->id,
                serviceQualityInspectionId: $inspection->id,
                recipientName: $customer->name,
                conditionNotes: 'Equipo encendido, probado y sin daños nuevos.',
                accessoriesSnapshot: 'Equipo sin accesorios.',
                customerConformity: true,
                idempotencyKey: 'service:http-work-warranty:delivery:'.$suffix,
                recipientBusinessPartyId: $customer->id,
                deliveredAt: $inspection->inspected_at
            ),
            $operator
        );
        $warranty = ServiceWarrantyGrant::query()
            ->where('service_delivery_id', $delivery->id)
            ->where('service_work_report_id', $report->id)
            ->sole();

        return [
            'organization' => $organization,
            'operator' => $operator,
            'admin' => $admin,
            'order' => $order->fresh(),
            'customer' => $customer,
            'location' => $location,
            'warranty' => $warranty,
        ];
    }

    private function party(
        Organization $organization,
        string $name
    ): BusinessParty {
        return BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => $name,
        ]);
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
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
                ['role' => $role, 'active' => true]
            )
        );

        return $user;
    }
}
