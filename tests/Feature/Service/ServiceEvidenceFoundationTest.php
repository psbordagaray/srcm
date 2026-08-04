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
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ServiceEvidenceFoundationTest extends TestCase
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

        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_schema_contexts_permissions_and_private_disk_are_explicit(): void
    {
        $this->assertSame(
            'service_evidences',
            (new ServiceEvidence)->getTable()
        );

        $this->assertTrue(Schema::hasColumns('service_evidences', [
            'organization_id',
            'service_order_id',
            'public_id',
            'context',
            'service_order_intake_id',
            'service_diagnostic_id',
            'service_work_item_id',
            'service_part_requirement_id',
            'service_custody_event_id',
            'service_quality_inspection_id',
            'service_delivery_id',
            'service_cancellation_request_id',
            'service_cancellation_resolution_id',
            'service_cancellation_return_id',
            'service_warranty_claim_id',
            'service_warranty_claim_resolution_id',
            'service_warranty_claim_return_id',
            'original_filename',
            'stored_filename',
            'disk',
            'path',
            'path_hash',
            'mime_type',
            'extension',
            'size_bytes',
            'sha256',
            'captured_at',
            'uploaded_by_user_id',
            'idempotency_key',
            'fingerprint',
        ]));

        $this->assertCount(14, ServiceEvidenceContext::cases());
        $this->assertNull(
            ServiceEvidenceContext::Order->referenceColumn()
        );
        $this->assertSame(
            'service_order_intake_id',
            ServiceEvidenceContext::Intake->referenceColumn()
        );
        $this->assertSame(
            'service_warranty_claim_resolution_id',
            ServiceEvidenceContext::WarrantyResolution->referenceColumn()
        );

        foreach (ServiceEvidenceContext::cases() as $context) {
            $this->assertSame(
                $context !== ServiceEvidenceContext::Order,
                $context->requiresReference()
            );
            $this->assertNotSame('', $context->label());
        }

        $this->assertTrue(UserRole::Admin->canUploadServiceEvidence());
        $this->assertTrue(UserRole::Operator->canUploadServiceEvidence());
        $this->assertFalse(UserRole::Viewer->canUploadServiceEvidence());
        $this->assertTrue(UserRole::Admin->canViewServiceEvidence());
        $this->assertTrue(UserRole::Operator->canViewServiceEvidence());
        $this->assertTrue(UserRole::Viewer->canViewServiceEvidence());
        $this->assertTrue(UserRole::Viewer->canVerifyServiceEvidence());

        $this->assertSame('local', config('service_evidence.disk'));
        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.local.root')
        );
        $this->assertNotSame(
            'public',
            config('service_evidence.disk')
        );
    }

    public function test_upload_is_private_idempotent_hashed_and_immutable(): void
    {
        $fixture = $this->fixture('upload');
        $manager = app(ServiceEvidenceManager::class);
        $source = $this->pngSource();
        $data = new ServiceEvidenceData(
            serviceOrderId: $fixture['order']->id,
            context: ServiceEvidenceContext::Order,
            sourcePath: $source,
            originalFilename: 'estado-ingreso.png',
            idempotencyKey: 'service:evidence:upload',
            description: 'Estado general recibido sin daños nuevos.'
        );

        $evidence = $manager->upload($data, $fixture['operator']);
        $retry = $manager->upload($data, $fixture['operator']);

        $this->assertSame($evidence->id, $retry->id);
        $this->assertSame(1, ServiceEvidence::query()->count());
        $this->assertSame(
            ServiceEvidenceContext::Order,
            $evidence->context
        );
        $this->assertNull($evidence->referenceId());
        $this->assertSame('local', $evidence->disk);
        $this->assertSame('image/png', $evidence->mime_type);
        $this->assertSame('png', $evidence->extension);
        $this->assertSame(filesize($source), $evidence->size_bytes);
        $this->assertSame(hash_file('sha256', $source), $evidence->sha256);
        $this->assertSame('estado-ingreso.png', $evidence->original_filename);
        $this->assertStringStartsWith(
            'service-evidence/'
                .$fixture['organization']->id.'/'
                .$fixture['order']->id.'/',
            $evidence->path
        );
        $this->assertStringNotContainsString(
            'estado-ingreso',
            $evidence->path
        );
        $this->assertSame(
            basename($evidence->path),
            $evidence->stored_filename
        );
        $this->assertSame(
            hash('sha256', $evidence->disk.':'.$evidence->path),
            $evidence->path_hash
        );
        Storage::disk('local')->assertExists($evidence->path);
        $this->assertCount(
            1,
            Storage::disk('local')->allFiles('service-evidence')
        );
        $this->assertSame(
            $evidence->id,
            $fixture['order']->fresh()->evidences()->sole()->id
        );
        $this->assertTrue(
            $manager->verify($evidence, $fixture['viewer'])->valid()
        );
        $this->assertArrayNotHasKey('path', $evidence->toArray());
        $this->assertArrayNotHasKey('disk', $evidence->toArray());

        $this->assertDomainFailure(function () use ($evidence): void {
            $evidence->forceFill(['description' => 'Reescritura'])->save();
        });
        $this->assertQueryRejected(
            fn () => DB::table('service_evidences')
                ->where('id', $evidence->id)
                ->update(['description' => 'Reescritura SQL'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_evidences')
                ->where('id', $evidence->id)
                ->delete()
        );

        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $source,
                    originalFilename: 'estado-ingreso.png',
                    idempotencyKey: 'service:evidence:upload',
                    description: 'Contenido contradictorio.'
                ),
                $fixture['operator']
            )
        );
        $this->assertSame(1, ServiceEvidence::query()->count());
    }

    public function test_context_reference_tenant_and_database_guards_are_enforced(): void
    {
        $fixture = $this->fixture('context');
        $manager = app(ServiceEvidenceManager::class);
        $source = $this->pngSource();
        $intake = $fixture['order']->intake()->sole();

        $evidence = $manager->upload(
            new ServiceEvidenceData(
                serviceOrderId: $fixture['order']->id,
                context: ServiceEvidenceContext::Intake,
                sourcePath: $source,
                originalFilename: 'ingreso.png',
                idempotencyKey: 'service:evidence:context:intake',
                referenceId: $intake->id
            ),
            $fixture['operator']
        );

        $this->assertSame($intake->id, $evidence->referenceId());
        $this->assertSame($intake->id, $evidence->service_order_intake_id);
        $this->assertNotNull($evidence->orderIntake);

        $secondOrder = $this->createOrder(
            $fixture['organization'],
            $fixture['operator'],
            'context-second'
        );

        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $secondOrder->id,
                    context: ServiceEvidenceContext::Intake,
                    sourcePath: $source,
                    originalFilename: 'cruzada.png',
                    idempotencyKey: 'service:evidence:context:cross-order',
                    referenceId: $intake->id
                ),
                $fixture['operator']
            )
        );

        $foreign = $this->fixture('context-foreign');
        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $source,
                    originalFilename: 'ajena.png',
                    idempotencyKey: 'service:evidence:context:foreign'
                ),
                $foreign['operator']
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $source,
                    originalFilename: 'viewer.png',
                    idempotencyKey: 'service:evidence:context:viewer'
                ),
                $fixture['viewer']
            )
        );

        foreach (ServiceEvidenceContext::cases() as $context) {
            if ($context === ServiceEvidenceContext::Order) {
                continue;
            }

            $this->assertDomainFailure(
                fn () => $manager->upload(
                    new ServiceEvidenceData(
                        serviceOrderId: $fixture['order']->id,
                        context: $context,
                        sourcePath: $source,
                        originalFilename: 'referencia-inexistente.png',
                        idempotencyKey: 'service:evidence:invalid:'
                            .$context->value,
                        referenceId: 999999999
                    ),
                    $fixture['operator']
                )
            );
        }

        $this->assertQueryRejected(fn () => DB::table(
            'service_evidences'
        )->insert($this->rawEvidenceRow($evidence, [
            'service_order_id' => $secondOrder->id,
            'service_order_intake_id' => $intake->id,
        ])));

        $this->assertQueryRejected(fn () => DB::table(
            'service_evidences'
        )->insert($this->rawEvidenceRow($evidence, [
            'context' => 'invalid_context',
            'service_order_intake_id' => null,
        ])));

        $this->assertQueryRejected(fn () => DB::table(
            'service_evidences'
        )->insert($this->rawEvidenceRow($evidence, [
            'uploaded_by_user_id' => $fixture['viewer']->id,
        ])));

        $this->assertSame(1, ServiceEvidence::query()->count());
        $this->assertCount(
            1,
            Storage::disk('local')->allFiles('service-evidence')
        );
    }

    public function test_source_security_and_storage_compensation_leave_no_orphans(): void
    {
        $fixture = $this->fixture('security');
        $manager = app(ServiceEvidenceManager::class);
        $png = $this->pngSource();
        $gif = $this->gifSource();

        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $gif,
                    originalFilename: 'unsafe.gif',
                    idempotencyKey: 'service:evidence:security:mime'
                ),
                $fixture['operator']
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $png,
                    originalFilename: '../traversal.png',
                    idempotencyKey: 'service:evidence:security:name'
                ),
                $fixture['operator']
            )
        );

        config(['service_evidence.max_bytes' => 8]);
        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $png,
                    originalFilename: 'oversized.png',
                    idempotencyKey: 'service:evidence:security:size'
                ),
                $fixture['operator']
            )
        );
        config(['service_evidence.max_bytes' => 20 * 1024 * 1024]);

        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $png,
                    originalFilename: 'future.png',
                    idempotencyKey: 'service:evidence:security:future',
                    capturedAt: CarbonImmutable::now()->addMinutes(5)
                ),
                $fixture['operator']
            )
        );
        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $png.'.missing',
                    originalFilename: 'missing.png',
                    idempotencyKey: 'service:evidence:security:missing'
                ),
                $fixture['operator']
            )
        );

        Event::listen(
            'eloquent.creating: '.ServiceEvidence::class,
            static function (): void {
                throw new RuntimeException('Falla transaccional forzada.');
            }
        );

        $this->assertDomainFailure(
            fn () => $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $fixture['order']->id,
                    context: ServiceEvidenceContext::Order,
                    sourcePath: $png,
                    originalFilename: 'compensated.png',
                    idempotencyKey: 'service:evidence:security:compensation'
                ),
                $fixture['operator']
            )
        );

        $this->assertSame(0, ServiceEvidence::query()->count());
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('service-evidence')
        );
    }

    public function test_verification_detects_tampering_missing_files_and_foreign_access(): void
    {
        $fixture = $this->fixture('verify');
        $foreign = $this->fixture('verify-foreign');
        $manager = app(ServiceEvidenceManager::class);
        $source = $this->pngSource();
        $data = new ServiceEvidenceData(
            serviceOrderId: $fixture['order']->id,
            context: ServiceEvidenceContext::Order,
            sourcePath: $source,
            originalFilename: 'verify.png',
            idempotencyKey: 'service:evidence:verify'
        );
        $evidence = $manager->upload($data, $fixture['operator']);

        $valid = $manager->verify($evidence, $fixture['viewer']);
        $this->assertTrue($valid->valid());
        $this->assertTrue($valid->exists);
        $this->assertTrue($valid->sizeMatches);
        $this->assertTrue($valid->hashMatches);

        Storage::disk('local')->put(
            $evidence->path,
            'contenido alterado deliberadamente'
        );
        $tampered = $manager->verify($evidence, $fixture['viewer']);
        $this->assertTrue($tampered->exists);
        $this->assertFalse($tampered->valid());
        $this->assertFalse($tampered->hashMatches);
        $this->assertDomainFailure(
            fn () => $manager->upload($data, $fixture['operator'])
        );

        Storage::disk('local')->delete($evidence->path);
        $missing = $manager->verify($evidence, $fixture['viewer']);
        $this->assertFalse($missing->exists);
        $this->assertFalse($missing->valid());
        $this->assertNull($missing->observedSizeBytes);
        $this->assertNull($missing->observedSha256);

        $this->assertDomainFailure(
            fn () => $manager->verify(
                $evidence,
                $foreign['viewer']
            )
        );
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix): array
    {
        $organization = $this->newOrganization('Evidence '.$suffix);
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $viewer = $this->user($organization, UserRole::Viewer);
        $order = $this->createOrder($organization, $operator, $suffix);

        return compact(
            'organization',
            'operator',
            'admin',
            'viewer',
            'order'
        );
    }

    private function createOrder(
        Organization $organization,
        User $operator,
        string $suffix
    ): ServiceOrder {
        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción '.$suffix,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        return app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Evidence Brand',
                modelName: 'Evidence Model '.$suffix,
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'EV-'.Str::upper($suffix).'-'.Str::random(8)
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Equipo recibido para documentar evidencia.',
                idempotencyKey: 'service:evidence:order:'.$suffix,
                customerName: 'Cliente Evidence',
                intakeObservations: 'Sin daños adicionales.',
                receivedAccessories: 'Equipo sin accesorios.'
            ),
            $operator
        );
    }

    private function pngSource(): string
    {
        return $this->source((string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));
    }

    private function gifSource(): string
    {
        return $this->source(
            "GIF89a\x01\x00\x01\x00\x80\x00\x00"
            ."\x00\x00\x00\xff\xff\xff!\xf9\x04\x01"
            ."\x00\x00\x00\x00,\x00\x00\x00\x00\x01"
            ."\x00\x01\x00\x00\x02\x02D\x01\x00;"
        );
    }

    private function source(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'srcm-evidence-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }

        file_put_contents($path, $content);
        $this->sourceFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawEvidenceRow(
        ServiceEvidence $evidence,
        array $overrides
    ): array {
        $row = $evidence->getAttributes();
        unset($row['id']);

        $uuid = (string) Str::uuid();
        $row['public_id'] = $uuid;
        $row['stored_filename'] = $uuid.'.png';
        $row['path'] = 'service-evidence/'
            .$evidence->organization_id.'/'
            .$evidence->service_order_id.'/'.$row['stored_filename'];
        $row['path_hash'] = hash(
            'sha256',
            $row['disk'].':'.$row['path']
        );
        $row['idempotency_key'] = 'service:evidence:raw:'.$uuid;
        $row['created_at'] = now();
        $row['updated_at'] = now();

        foreach ($overrides as $key => $value) {
            $row[$key] = $value;
        }

        if (! array_key_exists('path', $overrides)) {
            $row['path'] = 'service-evidence/'
                .$row['organization_id'].'/'
                .$row['service_order_id'].'/'.$row['stored_filename'];
        }

        $row['path_hash'] = hash(
            'sha256',
            $row['disk'].':'.$row['path']
        );

        return $row;
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
