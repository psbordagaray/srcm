<?php

namespace App\Domain\Fiscal;

use DomainException;

final readonly class ArcaSoapEndpointSet
{
    public string $wsfeWsdlUrl;

    public function __construct(
        public string $wsaaLoginCmsUrl,
        public string $wsfeServiceUrl,
    ) {
        $this->assertHttpsEndpoint($this->wsaaLoginCmsUrl, 'WSAA LoginCms');
        $this->assertHttpsEndpoint($this->wsfeServiceUrl, 'WSFE');

        if (parse_url($this->wsfeServiceUrl, PHP_URL_QUERY) !== null) {
            throw new DomainException(
                'El endpoint base WSFE no debe incluir query string.'
            );
        }

        $this->wsfeWsdlUrl = $this->wsfeServiceUrl . '?WSDL';
    }

    private function assertHttpsEndpoint(string $url, string $label): void
    {
        if (
            $url === ''
            || $url !== trim($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new DomainException("Endpoint {$label} inválido.");
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new DomainException(
                "Endpoint {$label} debe ser HTTPS, sin credenciales ni fragmento."
            );
        }
    }
}
