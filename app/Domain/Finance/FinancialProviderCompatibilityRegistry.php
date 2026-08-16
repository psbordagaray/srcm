<?php

namespace App\Domain\Finance;

use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Models\FinancialProviderCompatibility;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinancialProviderCompatibilityRegistry
{
    public function register(
        string $registryKey,
        string $providerKey,
        string $providerLabel,
        string $providerContractVersion,
        string $providerContractReference,
        ?string $adapterClass,
        string $adapterContractVersion,
        FinancialProviderCompatibilityStatus $status,
        bool $migrationRequired,
        string $srcmVersion,
        DateTimeInterface $verifiedAt,
        array $capabilities,
        ?string $notes = null
    ): FinancialProviderCompatibility {
        $snapshot = [
            'registry_key' => $this->registryKey($registryKey),
            'provider_key' => $this->providerKey($providerKey),
            'provider_label' => $this->text(
                $providerLabel,
                120,
                'La etiqueta del proveedor no es válida.'
            ),
            'provider_contract_version' => $this->text(
                $providerContractVersion,
                120,
                'La versión del contrato externo no es válida.'
            ),
            'provider_contract_reference' => $this->text(
                $providerContractReference,
                500,
                'La evidencia del contrato externo no es válida.'
            ),
            'adapter_class' => $this->nullableText(
                $adapterClass,
                255,
                'La referencia del adaptador SRCM no es válida.'
            ),
            'adapter_contract_version' => $this->text(
                $adapterContractVersion,
                120,
                'La versión del adaptador no es válida.'
            ),
            'compatibility_status' => $status->value,
            'migration_required' => $migrationRequired,
            'srcm_version' => $this->text(
                $srcmVersion,
                120,
                'La versión SRCM verificada no es válida.'
            ),
            'verified_at' => CarbonImmutable::instance($verifiedAt)
                ->utc(),
            'notes' => $this->nullableText(
                $notes,
                2000,
                'Las notas de compatibilidad no son válidas.'
            ),
        ];

        $capabilities = $this->capabilities($capabilities);

        $this->assertStatusConsistency(
            $status,
            $migrationRequired,
            $capabilities
        );

        return DB::transaction(function () use (
            $snapshot,
            $capabilities
        ): FinancialProviderCompatibility {
            $existing = FinancialProviderCompatibility::query()
                ->where('registry_key', $snapshot['registry_key'])
                ->with('capabilities')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertEquivalent(
                    $existing,
                    $snapshot,
                    $capabilities
                );

                return $existing;
            }

            $createdAt = now()->utc();

            $compatibility =
                FinancialProviderCompatibility::query()->create([
                    ...$snapshot,
                    'created_at' => $createdAt,
                ]);

            foreach ($capabilities as $capability) {
                $compatibility->capabilities()->create([
                    ...$capability,
                    'created_at' => $createdAt,
                ]);
            }

            return $compatibility->load('capabilities');
        }, 3);
    }

    public function seedReferenceRegistry(): array
    {
        $verifiedAt = CarbonImmutable::parse(
            '2026-08-14 00:00:00',
            'America/Argentina/Buenos_Aires'
        );

        $mercadoPago = $this->register(
            registryKey:
                'mercado-pago:orders-v1:point-v1:dc41bda',
            providerKey: 'mercado-pago',
            providerLabel: 'Mercado Pago',
            providerContractVersion: 'orders-v1',
            providerContractReference:
                'Mercado Pago Orders API + Point Webhooks; validación externa P5.3-P5.6.',
            adapterClass:
                'App\Adapters\Finance\MercadoPago\MercadoPagoExternalFinancialProviderAdapter',
            adapterContractVersion: 'point-v1',
            status: FinancialProviderCompatibilityStatus::Compatible,
            migrationRequired: false,
            srcmVersion:
                'dc41bda2323062b7ab4f6e165f42d2388a921306',
            verifiedAt: $verifiedAt,
            capabilities: [
                [
                    'capability' =>
                        FinancialProviderCapability::Create,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'P5.3 Orders create harness.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Read,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'P5.3 canonical GET /v1/orders/{id}.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Webhook,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'P5.6 external HTTPS authenticated webhook GREEN.',
                    'notes' =>
                        'Point HMAC conserva el case exacto de data.id según evidencia externa verificada.',
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Refund,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Unknown,
                    'required' => false,
                    'evidence_reference' =>
                        'No existe contrato de refund validado en P5.1-P5.6.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Reconciliation,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => false,
                    'evidence_reference' =>
                        'P5.1 provider-neutral ingestion + reconciliación financiera existente.',
                    'notes' =>
                        'Compatible a nivel de ingestión normalizada; no implica settlement provider-specific.',
                ],
            ],
            notes:
                'Primer proveedor de referencia del registry. Secretos y credenciales permanecen fuera del registro.'
        );

        $payway = $this->register(
            registryKey:
                'payway:unverified:provider-neutral-reference:dc41bda',
            providerKey: 'payway',
            providerLabel: 'Payway',
            providerContractVersion: 'unverified',
            providerContractReference:
                'Referencia provider-neutral de SRCM; contrato externo Payway pendiente de verificación específica.',
            adapterClass: null,
            adapterContractVersion:
                'provider-neutral-reference',
            status: FinancialProviderCompatibilityStatus::Unknown,
            migrationRequired: false,
            srcmVersion:
                'dc41bda2323062b7ab4f6e165f42d2388a921306',
            verifiedAt: $verifiedAt,
            capabilities: [
                [
                    'capability' =>
                        FinancialProviderCapability::Create,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Unknown,
                    'required' => true,
                    'evidence_reference' =>
                        'Sin adapter Payway verificado.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Read,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Unknown,
                    'required' => true,
                    'evidence_reference' =>
                        'Sin adapter Payway verificado.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Webhook,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Unknown,
                    'required' => true,
                    'evidence_reference' =>
                        'Sin autenticidad Webhook Payway verificada.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Refund,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Unknown,
                    'required' => false,
                    'evidence_reference' =>
                        'Sin contrato Payway verificado.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Reconciliation,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Unknown,
                    'required' => false,
                    'evidence_reference' =>
                        'El core es provider-neutral; falta evidencia Payway específica.',
                    'notes' => null,
                ],
            ],
            notes:
                'Segundo proveedor de referencia para demostrar que el registry no depende de Mercado Pago.'
        );

        return [$mercadoPago, $payway];
    }

    public function registerMercadoPagoPointRefundContractV1():
        FinancialProviderCompatibility {
        return $this->register(
            registryKey:
                'mercado-pago:orders-v1:point-refund-v1:p8.4.3.3',
            providerKey:
                'mercado-pago',
            providerLabel:
                'Mercado Pago',
            providerContractVersion:
                'orders-v1-point-refund-v1',
            providerContractReference:
                'Mercado Pago Point Orders POST /v1/orders/{order_id}/refund; documentación oficial verificada 2026-08-16.',
            adapterClass:
                'App\\Adapters\\Finance\\MercadoPago\\MercadoPagoPointRefundAdapter',
            adapterContractVersion:
                'point-refund-v1',
            status:
                FinancialProviderCompatibilityStatus::Compatible,
            migrationRequired:
                false,
            srcmVersion:
                'p8.4.3.3-contract-harness',
            verifiedAt:
                CarbonImmutable::parse(
                    '2026-08-16 00:00:00',
                    'America/Argentina/Buenos_Aires'
                ),
            capabilities: [
                [
                    'capability' =>
                        FinancialProviderCapability::Create,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' =>
                        true,
                    'evidence_reference' =>
                        'P5.3 Orders create harness vigente.',
                    'notes' =>
                        null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Read,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' =>
                        true,
                    'evidence_reference' =>
                        'GET /v1/orders/{id} vigente y reutilizado como preflight/polling.',
                    'notes' =>
                        null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Webhook,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' =>
                        true,
                    'evidence_reference' =>
                        'P5.6 webhook autenticado vigente.',
                    'notes' =>
                        null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Refund,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' =>
                        true,
                    'evidence_reference' =>
                        'P8.4.3.3 Point Orders refund contract + Http fake harness.',
                    'notes' =>
                        'Total: body vacío. Parcial: transactions[id,amount]. X-Idempotency-Key obligatoria.',
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Reconciliation,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' =>
                        false,
                    'evidence_reference' =>
                        'P8.4.3.2 FinancialExternalMovement evidence bridge.',
                    'notes' =>
                        null,
                ],
            ],
            notes:
                'Snapshot append-only; no migra bindings existentes ni habilita producción automáticamente.'
        );
    }

    private function registryKey(string $value): string
    {
        $value = trim($value);

        if (
            $value === ''
            || mb_strlen($value) > 191
            || preg_match(
                '/^[a-z0-9][a-z0-9._:-]*$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'La clave del registro de compatibilidad no es válida.'
            );
        }

        return $value;
    }

    private function providerKey(string $value): string
    {
        $value = Str::slug(trim($value));

        if (
            $value === ''
            || mb_strlen($value) > 100
            || preg_match(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'La clave del proveedor financiero no es válida.'
            );
        }

        return $value;
    }

    private function text(
        string $value,
        int $max,
        string $message
    ): string {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw new DomainException($message);
        }

        return $value;
    }

    private function nullableText(
        ?string $value,
        int $max,
        string $message
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw new DomainException($message);
        }

        return $value;
    }

    private function capabilities(array $rows): array
    {
        if ($rows === []) {
            throw new DomainException(
                'La evaluación debe declarar capacidades.'
            );
        }

        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException(
                    'La capacidad declarada no es válida.'
                );
            }

            $capability = $row['capability'] ?? null;
            $status = $row['status'] ?? null;
            $required = $row['required'] ?? null;

            if (
                ! $capability instanceof FinancialProviderCapability
                || ! $status
                    instanceof FinancialProviderCompatibilityStatus
                || ! is_bool($required)
            ) {
                throw new DomainException(
                    'La capacidad declarada no es válida.'
                );
            }

            if (isset($seen[$capability->value])) {
                throw new DomainException(
                    'Una capacidad no puede declararse dos veces.'
                );
            }

            $seen[$capability->value] = true;

            $normalized[] = [
                'capability' => $capability->value,
                'compatibility_status' => $status->value,
                'required' => $required,
                'evidence_reference' => $this->text(
                    (string) ($row['evidence_reference'] ?? ''),
                    500,
                    'La evidencia de capacidad no es válida.'
                ),
                'notes' => $this->nullableText(
                    $row['notes'] ?? null,
                    2000,
                    'Las notas de capacidad no son válidas.'
                ),
            ];
        }

        usort(
            $normalized,
            fn (array $a, array $b): int =>
                $a['capability'] <=> $b['capability']
        );

        return $normalized;
    }

    private function assertStatusConsistency(
        FinancialProviderCompatibilityStatus $status,
        bool $migrationRequired,
        array $capabilities
    ): void {
        if (
            $status
                === FinancialProviderCompatibilityStatus::MigrationRequired
            && ! $migrationRequired
        ) {
            throw new DomainException(
                'migration_required debe acompañar el estado migration_required.'
            );
        }

        if (
            $migrationRequired
            && ! in_array(
                $status,
                [
                    FinancialProviderCompatibilityStatus::MigrationRequired,
                    FinancialProviderCompatibilityStatus::Degraded,
                    FinancialProviderCompatibilityStatus::Blocked,
                ],
                true
            )
        ) {
            throw new DomainException(
                'Una migración requerida no puede ocultarse detrás de un estado compatible o desconocido.'
            );
        }

        if ($status === FinancialProviderCompatibilityStatus::Compatible) {
            foreach ($capabilities as $capability) {
                if (
                    $capability['required']
                    && $capability['compatibility_status']
                        !== FinancialProviderCompatibilityStatus::Compatible
                            ->value
                ) {
                    throw new DomainException(
                        'Un proveedor compatible no puede tener una capacidad obligatoria incompatible.'
                    );
                }
            }
        }
    }

    private function assertEquivalent(
        FinancialProviderCompatibility $existing,
        array $snapshot,
        array $capabilities
    ): void {
        $actual = [
            'registry_key' => $existing->registry_key,
            'provider_key' => $existing->provider_key,
            'provider_label' => $existing->provider_label,
            'provider_contract_version' =>
                $existing->provider_contract_version,
            'provider_contract_reference' =>
                $existing->provider_contract_reference,
            'adapter_class' => $existing->adapter_class,
            'adapter_contract_version' =>
                $existing->adapter_contract_version,
            'compatibility_status' =>
                $existing->compatibility_status->value,
            'migration_required' =>
                $existing->migration_required,
            'srcm_version' => $existing->srcm_version,
            'verified_at' => $existing->verified_at->utc()
                ->format('Y-m-d H:i:s'),
            'notes' => $existing->notes,
        ];

        $expected = [
            ...$snapshot,
            'verified_at' => $snapshot['verified_at']->utc()
                ->format('Y-m-d H:i:s'),
        ];

        if ($actual !== $expected) {
            throw new DomainException(
                'La clave del registry ya existe con otra evidencia.'
            );
        }

        $actualCapabilities = $existing->capabilities
            ->map(fn ($row): array => [
                'capability' => $row->capability->value,
                'compatibility_status' =>
                    $row->compatibility_status->value,
                'required' => $row->required,
                'evidence_reference' =>
                    $row->evidence_reference,
                'notes' => $row->notes,
            ])
            ->sortBy('capability')
            ->values()
            ->all();

        if ($actualCapabilities !== $capabilities) {
            throw new DomainException(
                'La clave del registry ya existe con otro contrato de capacidades.'
            );
        }
    }
}
