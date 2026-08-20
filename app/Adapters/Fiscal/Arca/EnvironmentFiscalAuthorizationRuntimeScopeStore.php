<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\FiscalAuthorizationRuntimeScopeStore;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaServiceName;
use App\Enums\FiscalEnvironment;
use DomainException;
use JsonException;

final class EnvironmentFiscalAuthorizationRuntimeScopeStore implements
    FiscalAuthorizationRuntimeScopeStore
{
    public function configuredFor(int $organizationId): bool
    {
        if ($organizationId <= 0) {
            return false;
        }

        try {
            $this->accessTicketRequestFor(
                $organizationId,
                FiscalEnvironment::Homologation,
            );
        } catch (DomainException) {
            return false;
        }

        return true;
    }

    public function accessTicketRequestFor(
        int $organizationId,
        FiscalEnvironment $environment,
    ): WsaaAccessTicketRequest {
        if ($organizationId <= 0) {
            throw new DomainException(
                'La organización del runtime fiscal debe ser positiva.'
            );
        }

        if ($environment !== FiscalEnvironment::Homologation) {
            throw new DomainException(
                'El runtime fiscal de producción permanece bloqueado.'
            );
        }

        if (! (bool) config('services.arca.homologation.enabled', false)) {
            throw new DomainException(
                'La homologación fiscal está deshabilitada explícitamente.'
            );
        }

        if ((bool) config('services.arca.production.enabled', false)) {
            throw new DomainException(
                'La producción fiscal permanece bloqueada.'
            );
        }

        $configuredService = config(
            'services.arca.homologation.service_name'
        );

        if (! is_string($configuredService)) {
            throw new DomainException(
                'El servicio WSAA del runtime fiscal debe ser texto.'
            );
        }

        $configuredService = trim($configuredService);
        WsaaServiceName::assertValid($configuredService);

        if ($configuredService !== 'wsfe') {
            throw new DomainException(
                'El runtime de autorización V1 requiere el servicio WSAA wsfe.'
            );
        }

        $entry = $this->entries()[(string) $organizationId] ?? null;

        if (! is_array($entry) || array_is_list($entry)) {
            throw new DomainException(
                'La organización no posee scope WSAA runtime configurado.'
            );
        }

        $this->assertExactEntryShape($entry);

        $service = $entry['service'];
        $issuerCuit = $entry['issuer_cuit'];

        if (! is_string($service) || ! is_string($issuerCuit)) {
            throw new DomainException(
                'El scope WSAA runtime posee tipos inválidos.'
            );
        }

        $service = trim($service);
        $issuerCuit = trim($issuerCuit);

        if ($service !== $configuredService) {
            throw new DomainException(
                'El servicio del scope WSAA runtime no coincide con la configuración.'
            );
        }

        if (preg_match('/^[0-9]{11}$/D', $issuerCuit) !== 1) {
            throw new DomainException(
                'El CUIT emisor del scope WSAA runtime debe contener 11 dígitos.'
            );
        }

        return new WsaaAccessTicketRequest(
            organizationId: $organizationId,
            environment: $environment,
            service: $service,
            issuerCuit: $issuerCuit,
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function entries(): array
    {
        $raw = $this->environmentValue();

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        if (strlen($raw) > 131072) {
            throw new DomainException(
                'El mapa de referencias WSAA excede el tamaño permitido.'
            );
        }

        try {
            $decoded = json_decode(
                $raw,
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new DomainException(
                'El mapa de referencias WSAA no es JSON válido.'
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new DomainException(
                'El mapa de referencias WSAA debe estar indexado por organización.'
            );
        }

        return $decoded;
    }

    /** @param array<string,mixed> $entry */
    private function assertExactEntryShape(array $entry): void
    {
        $allowed = [
            'service',
            'issuer_cuit',
            'certificate_reference',
            'private_key_reference',
            'private_key_passphrase_reference',
        ];

        $keys = array_keys($entry);
        sort($keys);
        sort($allowed);

        if ($keys !== $allowed) {
            throw new DomainException(
                'La entrada runtime WSAA es incompleta o contiene claves desconocidas.'
            );
        }

        foreach ([
            'service',
            'issuer_cuit',
            'certificate_reference',
            'private_key_reference',
        ] as $required) {
            if (! is_string($entry[$required] ?? null)) {
                throw new DomainException(
                    'La entrada runtime WSAA posee tipos inválidos.'
                );
            }
        }

        $passphrase = $entry['private_key_passphrase_reference'] ?? null;

        if ($passphrase !== null && ! is_string($passphrase)) {
            throw new DomainException(
                'La referencia de passphrase runtime WSAA posee un tipo inválido.'
            );
        }
    }

    private function environmentValue(): ?string
    {
        $key = EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY;

        foreach ([
            $_SERVER[$key] ?? null,
            $_ENV[$key] ?? null,
            getenv($key),
        ] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
