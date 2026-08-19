<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCredentialMaterialReference;
use App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore;
use App\Enums\FiscalEnvironment;
use DomainException;
use JsonException;

final class EnvironmentWsaaCredentialMaterialReferenceStore implements
    WsaaCredentialMaterialReferenceStore
{
    public const ENVIRONMENT_KEY =
        'SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON';

    public function hasAny(
        FiscalEnvironment $environment
    ): bool {
        if ($environment !== FiscalEnvironment::Homologation) {
            return false;
        }

        $entries = $this->entries();

        if ($entries === []) {
            return false;
        }

        $configuredService = config(
            'services.arca.homologation.service_name'
        );

        if (! is_string($configuredService)) {
            return false;
        }

        $configuredService = trim($configuredService);

        if ($configuredService === '') {
            return false;
        }

        foreach ($entries as $organizationId => $entry) {
            $reference = $this->reference(
                $organizationId,
                $environment,
                $entry
            );

            if ($reference->service === $configuredService) {
                return true;
            }
        }

        return false;
    }

    public function forRequest(
        WsaaAccessTicketRequest $request
    ): WsaaCredentialMaterialReference {
        if (
            $request->environment
                !== FiscalEnvironment::Homologation
        ) {
            throw new DomainException(
                'El material WSAA de producción permanece bloqueado.'
            );
        }

        $entries = $this->entries();
        $key = (string) $request->organizationId;
        $entry = $entries[$key] ?? null;

        if (! is_array($entry)) {
            throw new DomainException(
                'La organización no posee referencias de material WSAA configuradas.'
            );
        }

        $reference = $this->reference(
            $key,
            $request->environment,
            $entry
        );

        $configuredService = config(
            'services.arca.homologation.service_name'
        );

        if (
            ! is_string($configuredService)
            || trim($configuredService) === ''
            || $reference->service
                !== trim($configuredService)
            || $reference->service
                !== $request->service
        ) {
            throw new DomainException(
                'El servicio del material WSAA no coincide con el request.'
            );
        }

        if (
            $reference->issuerCuit
                !== $request->issuerCuit
        ) {
            throw new DomainException(
                'El CUIT del material WSAA no coincide con el request.'
            );
        }

        return $reference;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
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
                JSON_THROW_ON_ERROR
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

        foreach ($decoded as $organizationId => $entry) {
            if (
                ! is_string($organizationId)
                && ! is_int($organizationId)
            ) {
                throw new DomainException(
                    'El mapa WSAA posee una organización inválida.'
                );
            }

            $key = (string) $organizationId;

            if (
                preg_match(
                    '/^[1-9][0-9]*$/D',
                    $key
                ) !== 1
                || ! is_array($entry)
                || array_is_list($entry)
            ) {
                throw new DomainException(
                    'El mapa WSAA posee una entrada de organización inválida.'
                );
            }

            $this->reference(
                $key,
                FiscalEnvironment::Homologation,
                $entry
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function reference(
        string $organizationId,
        FiscalEnvironment $environment,
        array $entry
    ): WsaaCredentialMaterialReference {
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
                'La entrada de referencias WSAA es incompleta o contiene claves desconocidas.'
            );
        }

        foreach (
            [
                'service',
                'issuer_cuit',
                'certificate_reference',
                'private_key_reference',
            ] as $required
        ) {
            if (! is_string($entry[$required] ?? null)) {
                throw new DomainException(
                    'La entrada de referencias WSAA posee tipos inválidos.'
                );
            }
        }

        $passphraseReference =
            $entry['private_key_passphrase_reference']
            ?? null;

        if (
            $passphraseReference !== null
            && ! is_string($passphraseReference)
        ) {
            throw new DomainException(
                'La referencia de passphrase WSAA posee un tipo inválido.'
            );
        }

        return new WsaaCredentialMaterialReference(
            organizationId: (int) $organizationId,
            environment: $environment,
            service: $entry['service'],
            issuerCuit: $entry['issuer_cuit'],
            certificateReference:
                $entry['certificate_reference'],
            privateKeyReference:
                $entry['private_key_reference'],
            privateKeyPassphraseReference:
                $passphraseReference,
        );
    }

    private function environmentValue(): ?string
    {
        foreach (
            [
                $_SERVER[self::ENVIRONMENT_KEY] ?? null,
                $_ENV[self::ENVIRONMENT_KEY] ?? null,
                getenv(self::ENVIRONMENT_KEY),
            ] as $value
        ) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
