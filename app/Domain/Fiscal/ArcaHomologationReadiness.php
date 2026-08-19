<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use DomainException;

final class ArcaHomologationReadiness
{
    public function __construct(
        private readonly
            WsaaCredentialMaterialReferenceStore $credentialReferences
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config(
            'services.arca.homologation.enabled',
            false
        );
    }

    public function assertReady(): void
    {
        if (! $this->enabled()) {
            throw new DomainException(
                'La homologación fiscal está deshabilitada explícitamente.'
            );
        }

        foreach (
            [
                'wsaa_endpoint',
                'business_endpoint',
                'service_name',
            ] as $key
        ) {
            if (
                blank(
                    config(
                        "services.arca.homologation.$key"
                    )
                )
            ) {
                throw new DomainException(
                    "Falta la configuración de homologación: $key."
                );
            }
        }

        if (
            ! $this->credentialReferences->hasAny(
                FiscalEnvironment::Homologation
            )
        ) {
            throw new DomainException(
                'No existen referencias tenant-scoped de material WSAA para homologación.'
            );
        }

        if (
            (bool) config(
                'services.arca.production.enabled',
                false
            )
        ) {
            throw new DomainException(
                'La producción fiscal permanece bloqueada en este corte.'
            );
        }
    }
}
