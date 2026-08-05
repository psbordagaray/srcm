<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceEvidenceData;
use App\Domain\Service\ServiceEvidenceManager;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Enums\InventoryLocationType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceEvidenceContext;
use App\Enums\ServiceIdentifierType;
use App\Enums\UserRole;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceEvidence;
use App\Models\ServiceOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ServiceEvidenceHttpTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sourceFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_routes_and_permissions_are_explicit(): void
    {
        foreach ([
            'service-orders.evidences.create',
            'service-orders.evidences.store',
            'service-orders.evidences.download',
            'service-orders.evidences.verify',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name));
        }

        $downloadRoute = Route::getRoutes()->getByName(
            'service-orders.evidences.download'
        );
        $verifyRoute = Route::getRoutes()->getByName(
            'service-orders.evidences.verify'
        );

        $this->assertStringContainsString(
            '{evidencePublicId}',
            $downloadRoute->uri()
        );
        $this->assertStringContainsString(
            '{evidencePublicId}',
            $verifyRoute->uri()
        );
        $this->assertStringNotContainsString(
            '{evidence:',
            $downloadRoute->uri()
        );
        $this->assertStringNotContainsString(
            '{serviceEvidence:',
            $downloadRoute->uri()
        );
        $this->assertSame(
            ['serviceOrder', 'evidencePublicId'],
            $downloadRoute->parameterNames()
        );

        $fixture = $this->fixture('permissions');

        $this->get(route(
            'service-orders.evidences.create',
            $fixture['order']
        ))->assertRedirect(route('login'));

        $this->actingAs($fixture['viewer'])
            ->post(
                route(
                    'service-orders.evidences.store',
                    $fixture['order']
                ),
                []
            )
            ->assertForbidden();

        $this->actingAs($fixture['viewer'])
            ->get(route(
                'service-orders.evidences.create',
                $fixture['order']
            ))
            ->assertForbidden();

        $this->actingAs($fixture['operator'])
            ->get(route(
                'service-orders.evidences.create',
                $fixture['order']
            ))
            ->assertOk()
            ->assertSee('Adjuntar archivo')
            ->assertSee('Expediente general')
            ->assertSee('Ingreso y estado físico');
    }

    public function test_operator_uploads_and_viewer_lists_downloads_and_verifies(): void
    {
        $fixture = $this->fixture('upload');
        $intake = $fixture['order']->intake()->sole();
        $png = $this->pngBytes();

        $response = $this->actingAs($fixture['operator'])->post(
            route(
                'service-orders.evidences.store',
                $fixture['order']
            ),
            [
                'target' => 'intake:'.$intake->id,
                'evidence_file' => UploadedFile::fake()
                    ->createWithContent('estado-ingreso.png', $png),
                'description' => 'Frente y laterales al momento de la recepción.',
                'captured_at' => now()
                    ->subMinute()
                    ->format('Y-m-d\TH:i:s'),
                'idempotency_key' => 'service-ui:evidence:'.Str::uuid(),
            ]
        );

        $response
            ->assertRedirect(
                route('service-orders.show', $fixture['order'])
                    .'#service-evidence'
            )
            ->assertSessionHasNoErrors();

        $evidence = ServiceEvidence::query()->sole();

        $this->assertSame(
            ServiceEvidenceContext::Intake,
            $evidence->context
        );
        $this->assertSame($intake->id, $evidence->referenceId());
        $this->assertSame(hash('sha256', $png), $evidence->sha256);
        Storage::disk('local')->assertExists($evidence->path);

        $show = $this->actingAs($fixture['viewer'])->get(
            route('service-orders.show', $fixture['order'])
        );

        $show
            ->assertOk()
            ->assertSee('Evidencias del expediente')
            ->assertSee('estado-ingreso.png')
            ->assertSee('Ingreso y estado físico')
            ->assertDontSee($evidence->path)
            ->assertDontSee($evidence->stored_filename);

        $this->actingAs($fixture['viewer'])->post(
            route(
                'service-orders.evidences.verify',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $download = $this->actingAs($fixture['viewer'])->get(
            route(
                'service-orders.evidences.download',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        );

        $download
            ->assertOk()
            ->assertDownload('estado-ingreso.png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader(
                'Cross-Origin-Resource-Policy',
                'same-origin'
            )
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'none'; sandbox"
            );

        $cacheControl = (string) $download->headers->get(
            'Cache-Control'
        );
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_nested_order_and_tenant_boundaries_return_not_found(): void
    {
        $fixture = $this->fixture('tenant');
        $secondOrder = $this->createOrder(
            $fixture['organization'],
            $fixture['operator'],
            'tenant-second'
        );
        $evidence = $this->evidence(
            $fixture['order'],
            $fixture['operator'],
            'tenant'
        );
        $foreign = $this->fixture('tenant-foreign');

        $this->actingAs($fixture['viewer'])->get(
            route(
                'service-orders.evidences.download',
                [
                    'serviceOrder' => $secondOrder,
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )->assertNotFound();

        $this->actingAs($fixture['viewer'])->get(
            route(
                'service-orders.evidences.download',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => (string) Str::uuid(),
                ]
            )
        )->assertNotFound();

        $this->actingAs($foreign['viewer'])->get(
            route(
                'service-orders.evidences.download',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )->assertNotFound();

        $this->actingAs($foreign['viewer'])->post(
            route(
                'service-orders.evidences.verify',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )->assertNotFound();
    }

    public function test_tampered_or_missing_file_cannot_be_downloaded(): void
    {
        $fixture = $this->fixture('integrity');
        $evidence = $this->evidence(
            $fixture['order'],
            $fixture['operator'],
            'integrity'
        );

        Storage::disk('local')->put(
            $evidence->path,
            'contenido alterado'
        );

        $this->actingAs($fixture['viewer'])->get(
            route(
                'service-orders.evidences.download',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )->assertStatus(409);

        $this->actingAs($fixture['viewer'])->post(
            route(
                'service-orders.evidences.verify',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )
            ->assertRedirect()
            ->assertSessionHasErrors('service_evidence');

        Storage::disk('local')->delete($evidence->path);

        $this->actingAs($fixture['viewer'])->get(
            route(
                'service-orders.evidences.download',
                [
                    'serviceOrder' => $fixture['order'],
                    'evidencePublicId' => $evidence->public_id,
                ]
            )
        )->assertStatus(409);
    }

    public function test_disallowed_content_leaves_no_record_or_private_orphan(): void
    {
        $fixture = $this->fixture('invalid');

        $this->actingAs($fixture['operator'])->post(
            route(
                'service-orders.evidences.store',
                $fixture['order']
            ),
            [
                'target' => 'order',
                'evidence_file' => UploadedFile::fake()
                    ->createWithContent(
                        'archivo-falso.png',
                        $this->gifBytes()
                    ),
                'description' => 'Contenido con extensión engañosa.',
                'captured_at' => now()->format('Y-m-d\TH:i:s'),
                'idempotency_key' => 'service-ui:evidence:'.Str::uuid(),
            ]
        )
            ->assertRedirect()
            ->assertSessionHasErrors('service_evidence');

        $this->assertSame(0, ServiceEvidence::query()->count());
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('service-evidence')
        );
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix): array
    {
        $organization = $this->newOrganization(
            'Evidence HTTP '.$suffix
        );
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $order = $this->createOrder(
            $organization,
            $operator,
            $suffix
        );

        return compact(
            'organization',
            'operator',
            'viewer',
            'order'
        );
    }

    private function evidence(
        ServiceOrder $order,
        User $operator,
        string $suffix
    ): ServiceEvidence {
        return app(ServiceEvidenceManager::class)->upload(
            new ServiceEvidenceData(
                serviceOrderId: $order->id,
                context: ServiceEvidenceContext::Order,
                sourcePath: $this->source($this->pngBytes()),
                originalFilename: 'evidencia-'.$suffix.'.png',
                idempotencyKey: 'service:evidence:http:'.$suffix,
                description: 'Evidencia HTTP de prueba.'
            ),
            $operator
        );
    }

    private function createOrder(
        Organization $organization,
        User $operator,
        string $suffix
    ): ServiceOrder {
        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción HTTP '.$suffix,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        return app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'HTTP Evidence',
                modelName: 'Model '.$suffix,
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'HTTP-'.Str::upper($suffix)
                            .'-'.Str::random(8)
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Equipo recibido para evidencias HTTP.',
                idempotencyKey: 'service:evidence:http:order:'.$suffix,
                customerName: 'Cliente HTTP',
                intakeObservations: 'Sin daños adicionales.',
                receivedAccessories: 'Equipo sin accesorios.'
            ),
            $operator
        );
    }

    private function pngBytes(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        if (! is_string($decoded)) {
            throw new RuntimeException(
                'No se pudo construir la imagen PNG.'
            );
        }

        return $decoded;
    }

    private function gifBytes(): string
    {
        return "GIF89a\x01\x00\x01\x00\x80\x00\x00"
            ."\x00\x00\x00\xff\xff\xff!\xf9\x04\x01"
            ."\x00\x00\x00\x00,\x00\x00\x00\x00\x01"
            ."\x00\x01\x00\x00\x02\x02D\x01\x00;";
    }

    private function source(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'srcm-http-evidence-');

        if ($path === false) {
            throw new RuntimeException(
                'No se pudo crear el archivo temporal.'
            );
        }

        file_put_contents($path, $content);
        $this->sourceFiles[] = $path;

        return $path;
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name)
                .'-'.Str::lower(Str::random(6)),
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
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
    }
}
