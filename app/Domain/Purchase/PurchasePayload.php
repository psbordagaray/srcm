<?php

namespace App\Domain\Purchase;

use DomainException;
use Illuminate\Support\Str;
use JsonException;

final class PurchasePayload
{
    public static function idempotencyKey(string $value): string
    {
        $value = trim($value);

        if ($value === '' || Str::length($value) > 100) {
            throw new DomainException(
                'La clave de idempotencia de Compras es inválida.'
            );
        }

        return $value;
    }

    public static function currencyCode(string $value): string
    {
        $value = Str::upper(trim($value));

        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw new DomainException(
                'La moneda debe expresarse mediante un código ISO de tres letras.'
            );
        }

        return $value;
    }

    public static function requiredText(
        ?string $value,
        string $label,
        int $maxLength = 255
    ): string {
        $value = Str::of((string) $value)
            ->squish()
            ->toString();

        if ($value === '' || Str::length($value) > $maxLength) {
            throw new DomainException(
                $label.' es obligatorio y no puede superar '
                .$maxLength.' caracteres.'
            );
        }

        return $value;
    }

    public static function optionalText(
        ?string $value,
        string $label,
        int $maxLength = 255
    ): ?string {
        if (blank($value)) {
            return null;
        }

        $value = Str::of((string) $value)
            ->squish()
            ->toString();

        if (Str::length($value) > $maxLength) {
            throw new DomainException(
                $label.' no puede superar '
                .$maxLength.' caracteres.'
            );
        }

        return $value;
    }

    /**
     * @return array{reference: ?string, normalized: ?string}
     */
    public static function documentReference(
        ?string $value
    ): array {
        $reference = self::optionalText(
            $value,
            'La referencia documental',
            255
        );

        if ($reference === null) {
            return [
                'reference' => null,
                'normalized' => null,
            ];
        }

        $normalized = Str::upper(
            Str::ascii($reference)
        );
        $normalized = preg_replace(
            '/[^A-Z0-9]+/',
            '',
            $normalized
        ) ?? '';

        if ($normalized === '') {
            throw new DomainException(
                'La referencia documental no contiene una identidad utilizable.'
            );
        }

        return [
            'reference' => $reference,
            'normalized' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fingerprint(array $payload): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    self::canonicalize($payload),
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'El contenido de Compras no puede serializarse para su huella.',
                previous: $exception
            );
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (
                $value !== null
                && ! is_scalar($value)
            ) {
                throw new DomainException(
                    'La huella de Compras sólo admite valores serializables.'
                );
            }

            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
