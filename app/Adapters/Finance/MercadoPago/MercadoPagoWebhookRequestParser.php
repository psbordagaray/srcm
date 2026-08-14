<?php

namespace App\Adapters\Finance\MercadoPago;

use DomainException;
use JsonException;

final class MercadoPagoWebhookRequestParser
{
    /**
     * Parse the raw query string so PHP cannot silently translate
     * "data.id" into "data_id" before signature verification.
     *
     * @return array<string, string>
     */
    public function query(string $rawQuery): array
    {
        if ($rawQuery === '' || strlen($rawQuery) > 4096) {
            throw new DomainException(
                'La query del webhook Mercado Pago no es válida.'
            );
        }

        $values = [];

        foreach (explode('&', $rawQuery) as $segment) {
            if ($segment === '') {
                continue;
            }

            $pair = explode('=', $segment, 2);

            if (count($pair) !== 2) {
                throw new DomainException(
                    'La query del webhook Mercado Pago está malformada.'
                );
            }

            $key = urldecode($pair[0]);
            $value = urldecode($pair[1]);

            if (
                $key === ''
                || strlen($key) > 100
                || strlen($value) > 512
            ) {
                throw new DomainException(
                    'La query del webhook Mercado Pago contiene valores inválidos.'
                );
            }

            if (array_key_exists($key, $values)) {
                throw new DomainException(
                    'La query del webhook Mercado Pago contiene claves repetidas.'
                );
            }

            $values[$key] = $value;
        }

        if (
            ! array_key_exists('data.id', $values)
            || ! array_key_exists('type', $values)
        ) {
            throw new DomainException(
                'La query del webhook Mercado Pago no contiene identidad completa.'
            );
        }

        return [
            'data.id' => $values['data.id'],
            'type' => $values['type'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function body(
        string $rawBody,
        ?string $contentType
    ): array {
        $contentType = strtolower(trim((string) $contentType));

        if (
            $contentType === ''
            || ! str_starts_with(
                $contentType,
                'application/json'
            )
        ) {
            throw new DomainException(
                'Mercado Pago webhook requiere application/json.'
            );
        }

        if (
            $rawBody === ''
            || strlen($rawBody) > 32768
        ) {
            throw new DomainException(
                'El body del webhook Mercado Pago no es válido.'
            );
        }

        try {
            $decoded = json_decode(
                $rawBody,
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new DomainException(
                'El body del webhook Mercado Pago no contiene JSON válido.'
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new DomainException(
                'El body del webhook Mercado Pago debe ser un objeto JSON.'
            );
        }

        return $decoded;
    }
}
