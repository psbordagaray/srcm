<?php

namespace App\Adapters\Finance\MercadoPago;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class MercadoPagoPointOrdersClient
{
    private const BASE_URL = 'https://api.mercadopago.com';

    /**
     * @return array<string, mixed>
     */
    public function createOrder(
        string $accessToken,
        string $terminalId,
        string $externalReference,
        int $amountMinor,
        string $idempotencyKey,
        string $description = 'SRCM Point'
    ): array {
        $token = $this->accessToken($accessToken);
        $terminal = $this->terminalId($terminalId);
        $reference = $this->externalReference($externalReference);
        $idempotency = $this->idempotencyKey($idempotencyKey);
        $amount = $this->minorToDecimal($amountMinor);
        $safeDescription = $this->description($description);

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->withHeaders([
                'X-Idempotency-Key' => $idempotency,
            ])
            ->timeout(20)
            ->post(self::BASE_URL.'/v1/orders', [
                'type' => 'point',
                'external_reference' => $reference,
                'transactions' => [
                    'payments' => [[
                        'amount' => $amount,
                    ]],
                ],
                'config' => [
                    'point' => [
                        'terminal_id' => $terminal,
                        'print_on_terminal' => 'no_ticket',
                    ],
                ],
                'description' => $safeDescription,
            ]);

        return $this->orderResponse($response, 'create order', [201]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $accessToken, string $orderId): array
    {
        $token = $this->accessToken($accessToken);
        $id = $this->orderId($orderId);

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->get(self::BASE_URL.'/v1/orders/'.rawurlencode($id));

        return $this->orderResponse($response, 'get order', [200]);
    }

    private function accessToken(string $value): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 1024) {
            throw new DomainException(
                'La credencial transitoria de Mercado Pago no es válida.'
            );
        }

        return $value;
    }

    private function terminalId(string $value): string
    {
        $value = trim($value);

        if (
            strlen($value) > 191
            || preg_match(
                '/^[A-Za-z0-9_-]{1,100}__[A-Za-z0-9_-]{1,100}$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'El terminal_id de Mercado Pago Point no es válido.'
            );
        }

        return $value;
    }

    private function externalReference(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) !== 1) {
            throw new DomainException(
                'La referencia externa de Mercado Pago no es válida.'
            );
        }

        return $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = strtolower(trim($value));

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'Mercado Pago requiere una X-Idempotency-Key UUID v4 válida.'
            );
        }

        return $value;
    }

    private function minorToDecimal(int $minor): string
    {
        if ($minor <= 0) {
            throw new DomainException(
                'El importe Point debe ser mayor que cero.'
            );
        }

        return intdiv($minor, 100)
            .'.'
            .str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function description(string $value): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 120) {
            throw new DomainException(
                'La descripción Point no es válida.'
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new DomainException(
                'La descripción Point contiene caracteres de control.'
            );
        }

        return $value;
    }

    private function orderId(string $value): string
    {
        $value = trim($value);

        if (
            $value === ''
            || strlen($value) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new DomainException(
                'El ID de la order de Mercado Pago no es válido.'
            );
        }

        return $value;
    }

    /**
     * @param list<int> $expectedStatuses
     * @return array<string, mixed>
     */
    private function orderResponse(
        Response $response,
        string $operation,
        array $expectedStatuses
    ): array {
        if (! in_array($response->status(), $expectedStatuses, true)) {
            throw new DomainException(
                'Mercado Pago '.$operation.' falló '
                .$this->safeProviderFailure($response).'.'
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new DomainException(
                'Mercado Pago '.$operation.' devolvió JSON inválido.'
            );
        }

        if (($json['type'] ?? null) !== 'point') {
            throw new DomainException(
                'Mercado Pago '.$operation.' no devolvió una Point Order.'
            );
        }

        if (! is_string($json['id'] ?? null) || trim($json['id']) === '') {
            throw new DomainException(
                'Mercado Pago '.$operation.' no devolvió ID de order.'
            );
        }

        if (! is_string($json['status'] ?? null) || trim($json['status']) === '') {
            throw new DomainException(
                'Mercado Pago '.$operation.' no devolvió status de order.'
            );
        }

        return $json;
    }

    private function safeProviderFailure(Response $response): string
    {
        $parts = ['HTTP '.$response->status()];
        $json = $response->json();

        if (is_array($json)) {
            $codes = [];
            $fields = [];
            $this->collectSafeDiagnostics($json, $codes, $fields, 0);

            $codes = array_values(array_unique($codes));
            $fields = array_values(array_unique($fields));

            if ($codes !== []) {
                $parts[] = 'code='.implode(',', array_slice($codes, 0, 4));
            }

            if ($fields !== []) {
                $parts[] = 'field='.implode(',', array_slice($fields, 0, 4));
            }
        }

        $requestId = trim((string) $response->header('x-request-id'));

        if (
            $requestId !== ''
            && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $requestId) === 1
        ) {
            $parts[] = 'request_id='.$requestId;
        }

        return '('.implode('; ', $parts).')';
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $codes
     * @param list<string> $fields
     */
    private function collectSafeDiagnostics(
        array $node,
        array &$codes,
        array &$fields,
        int $depth
    ): void {
        if ($depth > 5) {
            return;
        }

        foreach ($node as $key => $value) {
            $keyText = is_string($key) ? strtolower($key) : '';

            if (
                is_string($value)
                && in_array($keyText, ['code', 'error'], true)
                && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $value) === 1
            ) {
                $codes[] = strtolower($value);
            }

            if (
                is_string($value)
                && in_array(
                    $keyText,
                    ['field', 'path', 'property', 'attribute'],
                    true
                )
                && preg_match('/^[A-Za-z0-9_.\[\]-]{1,120}$/D', $value) === 1
            ) {
                $fields[] = $this->canonicalSafeField($value);
            }

            if (is_string($value)) {
                $field = $this->fieldFromProviderMessage($value);

                if ($field !== null) {
                    $fields[] = $field;
                }
            }

            if (is_array($value)) {
                $this->collectSafeDiagnostics(
                    $value,
                    $codes,
                    $fields,
                    $depth + 1
                );
            }
        }
    }

    private function fieldFromProviderMessage(string $message): ?string
    {
        $text = strtolower($message);

        $known = [
            'config.point.terminal_id' => [
                'config.point.terminal_id',
                'terminal_id',
                'terminal id',
                'device_id',
                'device id',
            ],
            'operating_mode' => [
                'operating_mode',
                'operating mode',
            ],
            'transactions.payments.amount' => [
                'transactions.payments',
                'payment amount',
                'payments.amount',
                'amount',
            ],
            'external_reference' => [
                'external_reference',
                'external reference',
            ],
            'config.point.print_on_terminal' => [
                'print_on_terminal',
                'print on terminal',
            ],
            'config.payment_method.default_type' => [
                'default_type',
                'payment method',
                'payment_method',
            ],
            'expiration_time' => [
                'expiration_time',
                'expiration time',
            ],
            'description' => [
                'description',
            ],
            'type' => [
                'order type',
            ],
        ];

        foreach ($known as $canonical => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    private function canonicalSafeField(string $field): string
    {
        $field = strtolower(trim($field));

        if (str_contains($field, 'terminal')) {
            return 'config.point.terminal_id';
        }

        if (str_contains($field, 'operating')) {
            return 'operating_mode';
        }

        if (str_contains($field, 'amount')) {
            return 'transactions.payments.amount';
        }

        if (str_contains($field, 'external_reference')) {
            return 'external_reference';
        }

        if (str_contains($field, 'print_on_terminal')) {
            return 'config.point.print_on_terminal';
        }

        if (str_contains($field, 'default_type')) {
            return 'config.payment_method.default_type';
        }

        if (str_contains($field, 'expiration')) {
            return 'expiration_time';
        }

        if ($field === 'description') {
            return 'description';
        }

        if ($field === 'type') {
            return 'type';
        }

        return preg_match('/^[a-z0-9_.\[\]-]{1,120}$/D', $field) === 1
            ? $field
            : 'unknown';
    }

}