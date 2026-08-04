<?php

namespace App\Domain\Service;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceEvidenceContext;
use App\Models\ServiceEvidence;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ServiceEvidenceManager
{
    /** @var list<string> */
    private const REFERENCE_COLUMNS = [
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
    ];

    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {}

    public function upload(
        ServiceEvidenceData $data,
        User $actor
    ): ServiceEvidence {
        $organizationId = $this->organizationIdForUpload($actor);
        $normalized = $this->normalize($data);

        $existing = $this->existing(
            $organizationId,
            $normalized['idempotency_key']
        );

        if ($existing) {
            $this->guardFingerprint(
                $existing,
                $normalized['fingerprint']
            );
            $this->assertStoredIntegrity($existing);

            return $this->loadEvidence($existing);
        }

        $publicId = (string) Str::uuid();
        $temporaryPath = 'service-evidence/.tmp/'.Str::uuid().'.part';
        $finalPath = sprintf(
            'service-evidence/%d/%d/%s.%s',
            $organizationId,
            $data->serviceOrderId,
            $publicId,
            $normalized['extension']
        );
        $temporaryStored = false;
        $finalStored = false;

        try {
            $this->writeSource(
                $normalized['disk'],
                $temporaryPath,
                $normalized['source_path']
            );
            $temporaryStored = true;

            $this->assertPathIntegrity(
                $normalized['disk'],
                $temporaryPath,
                $normalized['size_bytes'],
                $normalized['sha256']
            );

            return DB::transaction(function () use (
                $data,
                $actor,
                $organizationId,
                $normalized,
                $publicId,
                $temporaryPath,
                $finalPath,
                &$temporaryStored,
                &$finalStored
            ): ServiceEvidence {
                $existing = ServiceEvidence::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $normalized['idempotency_key']
                    )
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    Storage::disk($normalized['disk'])
                        ->delete($temporaryPath);
                    $temporaryStored = false;

                    $this->guardFingerprint(
                        $existing,
                        $normalized['fingerprint']
                    );
                    $this->assertStoredIntegrity($existing);

                    return $this->loadEvidence($existing);
                }

                $order = ServiceOrder::query()
                    ->forOrganization($organizationId)
                    ->whereKey($data->serviceOrderId)
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    throw new DomainException(
                        'La orden no pertenece a la organización activa.'
                    );
                }

                $this->assertReference(
                    $data->context,
                    $data->referenceId,
                    $organizationId,
                    $order->id
                );

                $disk = Storage::disk($normalized['disk']);

                if ($disk->exists($finalPath)) {
                    throw new DomainException(
                        'La ubicación privada de evidencia ya está ocupada.'
                    );
                }

                if (! $disk->move($temporaryPath, $finalPath)) {
                    throw new DomainException(
                        'No fue posible confirmar el archivo privado.'
                    );
                }

                $temporaryStored = false;
                $finalStored = true;

                $this->assertPathIntegrity(
                    $normalized['disk'],
                    $finalPath,
                    $normalized['size_bytes'],
                    $normalized['sha256']
                );

                $referenceAttributes = array_fill_keys(
                    self::REFERENCE_COLUMNS,
                    null
                );
                $referenceColumn = $data->context->referenceColumn();

                if ($referenceColumn !== null) {
                    $referenceAttributes[$referenceColumn] =
                        $data->referenceId;
                }

                $evidence = ServiceEvidence::query()->create([
                    'organization_id' => $organizationId,
                    'service_order_id' => $order->id,
                    'public_id' => $publicId,
                    'context' => $data->context,
                    ...$referenceAttributes,
                    'original_filename' => $normalized['original_filename'],
                    'stored_filename' => basename($finalPath),
                    'disk' => $normalized['disk'],
                    'path' => $finalPath,
                    'path_hash' => hash(
                        'sha256',
                        $normalized['disk'].':'.$finalPath
                    ),
                    'mime_type' => $normalized['mime_type'],
                    'extension' => $normalized['extension'],
                    'size_bytes' => $normalized['size_bytes'],
                    'sha256' => $normalized['sha256'],
                    'description' => $normalized['description'],
                    'captured_at' => $normalized['captured_at'],
                    'uploaded_by_user_id' => $actor->id,
                    'idempotency_key' => $normalized['idempotency_key'],
                    'fingerprint' => $normalized['fingerprint'],
                ]);

                return $this->loadEvidence($evidence);
            }, 1);
        } catch (Throwable $exception) {
            $disk = Storage::disk($normalized['disk']);

            try {
                $existing = $this->existing(
                    $organizationId,
                    $normalized['idempotency_key']
                );
            } catch (Throwable $lookupException) {
                if ($temporaryStored) {
                    $disk->delete($temporaryPath);
                }

                throw new DomainException(
                    'No fue posible reconciliar el almacenamiento privado.',
                    0,
                    $lookupException
                );
            }

            $committedFinal = $existing !== null
                && hash_equals((string) $existing->path, $finalPath);

            if ($temporaryStored) {
                $disk->delete($temporaryPath);
            }

            if ($finalStored && ! $committedFinal) {
                $disk->delete($finalPath);
            }

            if ($existing) {
                $this->guardFingerprint(
                    $existing,
                    $normalized['fingerprint']
                );
                $this->assertStoredIntegrity($existing);

                return $this->loadEvidence($existing);
            }

            if ($exception instanceof DomainException) {
                throw $exception;
            }

            throw new DomainException(
                'No fue posible almacenar la evidencia de forma segura.',
                0,
                $exception
            );
        }
    }

    public function verify(
        ServiceEvidence $evidence,
        User $actor
    ): ServiceEvidenceIntegrity {
        $organizationId = $this->organizationIdForVerification($actor);

        $authorized = ServiceEvidence::query()
            ->forOrganization($organizationId)
            ->whereKey($evidence->id)
            ->first();

        if (! $authorized) {
            throw new DomainException(
                'La evidencia no pertenece a la organización activa.'
            );
        }

        return $this->inspectPath(
            $authorized->disk,
            $authorized->path,
            $authorized->size_bytes,
            $authorized->sha256
        );
    }

    /**
     * @return array{
     *     source_path: string,
     *     original_filename: string,
     *     disk: string,
     *     mime_type: string,
     *     extension: string,
     *     size_bytes: int,
     *     sha256: string,
     *     description: ?string,
     *     captured_at: CarbonImmutable,
     *     idempotency_key: string,
     *     fingerprint: string
     * }
     *
     * @throws JsonException
     */
    private function normalize(ServiceEvidenceData $data): array
    {
        if ($data->serviceOrderId < 1) {
            throw new DomainException(
                'La evidencia requiere una orden válida.'
            );
        }

        if (
            $data->context->requiresReference()
            && ($data->referenceId === null || $data->referenceId < 1)
        ) {
            throw new DomainException(
                'El contexto seleccionado requiere una referencia válida.'
            );
        }

        if (
            ! $data->context->requiresReference()
            && $data->referenceId !== null
        ) {
            throw new DomainException(
                'La evidencia general no admite una referencia específica.'
            );
        }

        $sourcePath = trim($data->sourcePath);

        if (
            $sourcePath === ''
            || str_contains($sourcePath, "\0")
            || is_link($sourcePath)
        ) {
            throw new DomainException(
                'La fuente del archivo no es segura.'
            );
        }

        $realPath = realpath($sourcePath);

        if (
            $realPath === false
            || ! is_file($realPath)
            || ! is_readable($realPath)
        ) {
            throw new DomainException(
                'El archivo de evidencia no existe o no puede leerse.'
            );
        }

        $size = filesize($realPath);
        $maximum = (int) config('service_evidence.max_bytes');

        if (
            $size === false
            || $size < 1
            || $maximum < 1
            || $size > $maximum
        ) {
            throw new DomainException(
                'El archivo está vacío o supera el tamaño permitido.'
            );
        }

        $hash = hash_file('sha256', $realPath);

        if (! is_string($hash) || strlen($hash) !== 64) {
            throw new DomainException(
                'No fue posible calcular la integridad del archivo.'
            );
        }

        $mimeDetector = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $mimeDetector->file($realPath);
        $allowedTypes = config('service_evidence.allowed_mime_types');

        if (
            ! is_string($mimeType)
            || ! is_array($allowedTypes)
            || ! array_key_exists($mimeType, $allowedTypes)
        ) {
            throw new DomainException(
                'El tipo real del archivo no está permitido.'
            );
        }

        $extension = (string) $allowedTypes[$mimeType];
        $originalFilename = $this->normalizeOriginalFilename(
            $data->originalFilename
        );
        $idempotencyKey = $this->normalizeIdempotencyKey(
            $data->idempotencyKey
        );
        $description = $this->normalizeDescription($data->description);
        $timezone = (string) config('app.timezone');
        $capturedAt = $data->capturedAt
            ? CarbonImmutable::parse(
                $data->capturedAt->format(DATE_ATOM),
                $timezone
            )
            : CarbonImmutable::now($timezone);

        if ($capturedAt->isAfter(CarbonImmutable::now($timezone)->addMinute())) {
            throw new DomainException(
                'La fecha de captura no puede estar en el futuro.'
            );
        }

        $disk = (string) config('service_evidence.disk');

        if (
            $disk !== 'local'
            || config('filesystems.disks.local.driver') !== 'local'
            || config('filesystems.disks.local.root')
                !== storage_path('app/private')
        ) {
            throw new DomainException(
                'La evidencia requiere el almacenamiento local privado.'
            );
        }

        $fingerprintPayload = [
            'service_order_id' => $data->serviceOrderId,
            'context' => $data->context->value,
            'reference_id' => $data->referenceId,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => (int) $size,
            'sha256' => $hash,
            'description' => $description,
            'captured_at' => $data->capturedAt === null
                ? null
                : $capturedAt
                    ->utc()
                    ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        return [
            'source_path' => $realPath,
            'original_filename' => $originalFilename,
            'disk' => $disk,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => (int) $size,
            'sha256' => $hash,
            'description' => $description,
            'captured_at' => $capturedAt,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => hash(
                'sha256',
                json_encode(
                    $fingerprintPayload,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            ),
        ];
    }

    private function normalizeOriginalFilename(string $value): string
    {
        $filename = trim($value);

        if (
            $filename === ''
            || str_contains($filename, "\0")
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
        ) {
            throw new DomainException(
                'El nombre original del archivo no es válido.'
            );
        }

        $withoutControls = preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            $filename
        );

        if (
            ! is_string($withoutControls)
            || $withoutControls !== $filename
            || mb_strlen($filename) > 255
            || in_array($filename, ['.', '..'], true)
        ) {
            throw new DomainException(
                'El nombre original del archivo no es válido.'
            );
        }

        return $filename;
    }

    private function normalizeIdempotencyKey(string $value): string
    {
        $key = trim($value);

        if (
            strlen($key) < 8
            || strlen($key) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]+$/', $key)
                !== 1
        ) {
            throw new DomainException(
                'La clave de idempotencia de evidencia no es válida.'
            );
        }

        return $key;
    }

    private function normalizeDescription(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $description = trim($value);

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) > 2000) {
            throw new DomainException(
                'La descripción de evidencia es demasiado extensa.'
            );
        }

        return $description;
    }

    private function organizationIdForUpload(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! $role?->canUploadServiceEvidence()) {
            throw new DomainException(
                'El usuario no puede registrar evidencias de servicio.'
            );
        }

        return $organizationId;
    }

    private function organizationIdForVerification(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! $role?->canVerifyServiceEvidence()) {
            throw new DomainException(
                'El usuario no puede verificar evidencias de servicio.'
            );
        }

        return $organizationId;
    }

    private function existing(
        int $organizationId,
        string $idempotencyKey
    ): ?ServiceEvidence {
        return ServiceEvidence::query()
            ->forOrganization($organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function guardFingerprint(
        ServiceEvidence $evidence,
        string $fingerprint
    ): void {
        if (! hash_equals($evidence->fingerprint, $fingerprint)) {
            throw new DomainException(
                'La clave de idempotencia ya fue utilizada con otra evidencia.'
            );
        }
    }

    private function writeSource(
        string $disk,
        string $path,
        string $sourcePath
    ): void {
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new DomainException(
                'No fue posible abrir el archivo de evidencia.'
            );
        }

        try {
            if (! Storage::disk($disk)->put($path, $stream)) {
                throw new DomainException(
                    'No fue posible escribir el archivo privado temporal.'
                );
            }
        } finally {
            fclose($stream);
        }
    }

    private function assertStoredIntegrity(ServiceEvidence $evidence): void
    {
        $integrity = $this->inspectPath(
            $evidence->disk,
            $evidence->path,
            $evidence->size_bytes,
            $evidence->sha256
        );

        if (! $integrity->valid()) {
            throw new DomainException(
                'El archivo privado no coincide con la evidencia registrada.'
            );
        }
    }

    private function assertPathIntegrity(
        string $disk,
        string $path,
        int $expectedSize,
        string $expectedHash
    ): void {
        if (! $this->inspectPath(
            $disk,
            $path,
            $expectedSize,
            $expectedHash
        )->valid()) {
            throw new DomainException(
                'El archivo almacenado no superó la verificación de integridad.'
            );
        }
    }

    private function inspectPath(
        string $disk,
        string $path,
        int $expectedSize,
        string $expectedHash
    ): ServiceEvidenceIntegrity {
        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($path)) {
                return new ServiceEvidenceIntegrity(
                    false,
                    false,
                    false
                );
            }

            $observedSize = $filesystem->size($path);
            $stream = $filesystem->readStream($path);

            if ($stream === false) {
                return new ServiceEvidenceIntegrity(
                    true,
                    $observedSize === $expectedSize,
                    false,
                    $observedSize
                );
            }

            try {
                $hashContext = hash_init('sha256');
                hash_update_stream($hashContext, $stream);
                $observedHash = hash_final($hashContext);
            } finally {
                fclose($stream);
            }

            return new ServiceEvidenceIntegrity(
                true,
                $observedSize === $expectedSize,
                hash_equals($expectedHash, $observedHash),
                $observedSize,
                $observedHash
            );
        } catch (Throwable) {
            return new ServiceEvidenceIntegrity(
                false,
                false,
                false
            );
        }
    }

    private function assertReference(
        ServiceEvidenceContext $context,
        ?int $referenceId,
        int $organizationId,
        int $serviceOrderId
    ): void {
        if ($context === ServiceEvidenceContext::Order) {
            if ($referenceId !== null) {
                throw new DomainException(
                    'La evidencia general no admite referencia específica.'
                );
            }

            return;
        }

        if (
            $referenceId === null
            || ! $this->referenceExists(
                $context,
                $referenceId,
                $organizationId,
                $serviceOrderId
            )
        ) {
            throw new DomainException(
                'La referencia de evidencia no pertenece al expediente activo.'
            );
        }
    }

    private function referenceExists(
        ServiceEvidenceContext $context,
        int $referenceId,
        int $organizationId,
        int $serviceOrderId
    ): bool {
        $directTable = match ($context) {
            ServiceEvidenceContext::Intake => 'service_order_intakes',
            ServiceEvidenceContext::Diagnostic => 'service_diagnostics',
            ServiceEvidenceContext::WorkItem => 'service_work_items',
            ServiceEvidenceContext::PartRequirement => 'service_part_requirements',
            ServiceEvidenceContext::CustodyEvent => 'service_custody_events',
            ServiceEvidenceContext::QualityInspection => 'service_quality_inspections',
            ServiceEvidenceContext::Delivery => 'service_deliveries',
            ServiceEvidenceContext::CancellationRequest => 'service_cancellation_requests',
            ServiceEvidenceContext::CancellationReturn => 'service_cancellation_returns',
            ServiceEvidenceContext::WarrantyReturn => 'service_warranty_claim_returns',
            default => null,
        };

        if ($directTable !== null) {
            $orderColumn = $context === ServiceEvidenceContext::WarrantyReturn
                ? 'corrective_service_order_id'
                : 'service_order_id';

            return DB::table($directTable)
                ->where('id', $referenceId)
                ->where('organization_id', $organizationId)
                ->where($orderColumn, $serviceOrderId)
                ->exists();
        }

        return match ($context) {
            ServiceEvidenceContext::CancellationResolution => DB::table('service_cancellation_resolutions as resolution')
                ->join(
                    'service_cancellation_requests as request',
                    'request.id',
                    '=',
                    'resolution.service_cancellation_request_id'
                )
                ->where('resolution.id', $referenceId)
                ->where(
                    'resolution.organization_id',
                    $organizationId
                )
                ->where('request.organization_id', $organizationId)
                ->where('request.service_order_id', $serviceOrderId)
                ->exists(),
            ServiceEvidenceContext::WarrantyClaim => DB::table('service_warranty_claims')
                ->where('id', $referenceId)
                ->where('organization_id', $organizationId)
                ->where(
                    'corrective_service_order_id',
                    $serviceOrderId
                )
                ->exists(),
            ServiceEvidenceContext::WarrantyResolution => DB::table('service_warranty_claim_resolutions as resolution')
                ->join(
                    'service_warranty_claims as claim',
                    'claim.id',
                    '=',
                    'resolution.service_warranty_claim_id'
                )
                ->where('resolution.id', $referenceId)
                ->where(
                    'resolution.organization_id',
                    $organizationId
                )
                ->where('claim.organization_id', $organizationId)
                ->where(
                    'claim.corrective_service_order_id',
                    $serviceOrderId
                )
                ->exists(),
            default => false,
        };
    }

    private function loadEvidence(
        ServiceEvidence $evidence
    ): ServiceEvidence {
        return $evidence->load([
            'serviceOrder',
            'uploadedBy',
        ]);
    }
}
