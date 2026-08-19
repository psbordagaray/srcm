<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalAuthorizationOutcome;
use DomainException;

final readonly class FiscalAuthorizationFactData
{
    /**
     * @param array<string,mixed> $providerEvidence
     */
    public function __construct(
        public int $fiscalDocumentId,
        public FiscalAuthorizationOutcome $outcome,
        public ?string $resultCode,
        public string $idempotencyKey,
        public ?string $authorizationCode = null,
        public ?string $authorizationCodeExpiresOn = null,
        public array $providerEvidence = [],
    ) {
        if (
            $this->authorizationCode !== null
            && trim($this->authorizationCode) === ''
        ) {
            throw new DomainException(
                'El código de autorización no puede estar vacío.'
            );
        }

        if (
            $this->authorizationCodeExpiresOn !== null
            && ! self::validIsoDate(
                $this->authorizationCodeExpiresOn
            )
        ) {
            throw new DomainException(
                'El vencimiento del código de autorización debe usar YYYY-MM-DD.'
            );
        }

        json_encode(
            $this->providerEvidence,
            JSON_THROW_ON_ERROR
        );
    }

    public static function fromTransportResult(
        int $fiscalDocumentId,
        FiscalAuthorizationTransportResult $result,
        string $idempotencyKey,
    ): self {
        return new self(
            fiscalDocumentId: $fiscalDocumentId,
            outcome: $result->outcome,
            resultCode: $result->resultCode,
            idempotencyKey: $idempotencyKey,
            authorizationCode: $result->authorizationCode,
            authorizationCodeExpiresOn:
                $result->authorizationCodeExpiresOn,
            providerEvidence: $result->providerEvidence,
        );
    }

    private static function validIsoDate(
        string $value
    ): bool {
        if (
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',
                $value
            ) !== 1
        ) {
            return false;
        }

        return checkdate(
            (int) substr($value, 5, 2),
            (int) substr($value, 8, 2),
            (int) substr($value, 0, 4)
        );
    }
}
