<?php

namespace App\Domain\Fiscal;

use App\Enums\WsaaCmsDigestAlgorithm;
use DomainException;

final readonly class WsaaSignedCms
{
    public function __construct(
        private string $base64Cms,
        public WsaaCmsDigestAlgorithm $digest,
    ) {
        if (
            $this->base64Cms === ''
            || preg_match(
                '/^[A-Za-z0-9+\/]+={0,2}$/D',
                $this->base64Cms
            ) !== 1
            || base64_decode(
                $this->base64Cms,
                true
            ) === false
        ) {
            throw new DomainException(
                'El CMS WSAA debe ser Base64 puro, sin wrappers PEM/MIME.'
            );
        }
    }

    public function loginCmsInput(): string
    {
        return $this->base64Cms;
    }

    public function __serialize(): array
    {
        throw new DomainException(
            'El CMS firmado WSAA no es serializable.'
        );
    }

    public function __debugInfo(): array
    {
        return [
            'base64Cms' => '[REDACTED]',
            'digest' => $this->digest->value,
        ];
    }
}
