<?php

namespace App\Domain\Commerce;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerAdvanceStatus;
use App\Enums\FinancialAccountType;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CustomerAdvanceManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CashRegisterSessionManager $cashSessions,
        private readonly CashLedgerRecorder $cashLedger
    ) {
    }

    public function receive(
        Customer $customer,
        CustomerAdvanceData $data,
        User $actor
    ): CustomerAdvance {
        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $customer,
            $normalized,
            $actor
        ): CustomerAdvance {
            $organizationId =
                $this->currentOrganization->id($actor);
            $role =
                $this->currentOrganization->roleFor($actor);

            if (
                ! (
                    $role?->canRecordCustomerCollections()
                    ?? false
                )
            ) {
                throw new DomainException(
                    'El usuario no puede registrar anticipos de clientes.'
                );
            }

            $this->lockOrganization($organizationId);

            $lockedCustomer = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedCustomer) {
                throw new DomainException(
                    'El cliente no pertenece a la organización activa.'
                );
            }

            $fingerprint = $this->fingerprint([
                'business_party_id' =>
                    (int) $lockedCustomer
                        ->business_party_id,
                ...$normalized,
            ]);

            $existing = CustomerAdvance::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $normalized['idempotency_key']
                )
                ->with([
                    'financialAccount',
                    'cashMovement',
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->business_party_id
                        !== (int) $lockedCustomer
                            ->business_party_id
                    || ! hash_equals(
                        $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La misma clave de anticipo fue reutilizada con otros datos.'
                    );
                }

                return $existing;
            }

            if (! $lockedCustomer->active) {
                throw new DomainException(
                    'El anticipo requiere un cliente activo.'
                );
            }

            $account = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey(
                    $normalized['financial_account_id']
                )
                ->where('active', true)
                ->where(
                    'currency_code',
                    $normalized['currency_code']
                )
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw new DomainException(
                    'La cuenta financiera destino no está activa, no pertenece a la organización o usa otra moneda.'
                );
            }

            $cashSession = null;

            if (
                $normalized['method']
                    === CommercePaymentMethod::Cash->value
            ) {
                $cashSession = $this->cashSessions
                    ->lockCurrentFor($actor);

                $register = $cashSession?->register;
                $cashAccount =
                    $register?->financialAccount;

                if (
                    ! $cashSession
                    || ! $register
                    || ! $register->active
                    || ! $cashAccount
                    || ! $cashAccount->active
                    || $account->type
                        !== FinancialAccountType::CashBox
                    || (int) $cashAccount->id
                        !== (int) $account->id
                    || $cashSession->currency_code
                        !== $normalized['currency_code']
                ) {
                    throw new DomainException(
                        'Para recibir un anticipo en efectivo, usá la cuenta de la caja de tu turno abierto.'
                    );
                }
            } elseif (
                in_array(
                    $account->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'Un anticipo no efectivo no puede ingresar en una cuenta de efectivo.'
                );
            }

            $now = CarbonImmutable::now();

            $advance = CustomerAdvance::query()->create([
                'organization_id' => $organizationId,
                'business_party_id' =>
                    $lockedCustomer->business_party_id,
                'financial_account_id' => $account->id,
                'cash_register_session_id' =>
                    $cashSession?->id,
                'cash_register_id' =>
                    $cashSession?->cash_register_id,
                'status' =>
                    CustomerAdvanceStatus::Building,
                'method' => $normalized['method'],
                'currency_code' =>
                    $normalized['currency_code'],
                'amount_minor' =>
                    $normalized['amount_minor'],
                'tendered_amount_minor' =>
                    $normalized[
                        'tendered_amount_minor'
                    ],
                'change_amount_minor' =>
                    $normalized[
                        'change_amount_minor'
                    ],
                'reference' => $normalized['reference'],
                'notes' => $normalized['notes'],
                'received_by_user_id' => $actor->id,
                'received_at' => $now,
                'idempotency_key' =>
                    $normalized['idempotency_key'],
                'fingerprint' => $fingerprint,
                'created_at' => $now,
            ]);

            $advance->status =
                CustomerAdvanceStatus::Confirmed;
            $advance->save();

            if (
                $advance->method
                    === CommercePaymentMethod::Cash
            ) {
                if (! $cashSession) {
                    throw new DomainException(
                        'El anticipo en efectivo perdió su turno de caja.'
                    );
                }

                $this->cashLedger
                    ->recordCustomerAdvance(
                        $cashSession,
                        $advance,
                        $actor
                    );
            }

            return $advance->refresh()->load([
                'financialAccount',
                'cashMovement',
            ]);
        }, 3);
    }

    /**
     * @return array{
     *     currency_code: string,
     *     method: string,
     *     amount_minor: int,
     *     financial_account_id: int,
     *     reference: ?string,
     *     tendered_amount_minor: ?int,
     *     change_amount_minor: ?int,
     *     notes: ?string,
     *     idempotency_key: string
     * }
     */
    private function normalize(
        CustomerAdvanceData $data
    ): array {
        $currency =
            strtoupper(trim($data->currencyCode));

        if (
            preg_match(
                '/^[A-Z]{3}$/D',
                $currency
            ) !== 1
        ) {
            throw new DomainException(
                'La moneda del anticipo no es válida.'
            );
        }

        if (
            $data->method
                === CommercePaymentMethod::AccountCredit
        ) {
            throw new DomainException(
                'Un saldo a favor existente no puede recibirse otra vez como anticipo.'
            );
        }

        if (
            $data->amountMinor <= 0
            || $data->financialAccountId <= 0
        ) {
            throw new DomainException(
                'El anticipo requiere importe y cuenta financiera válidos.'
            );
        }

        $reference = $this->optional(
            $data->reference,
            255,
            'La referencia del anticipo'
        );
        $notes = $this->optional(
            $data->notes,
            1000,
            'Las notas del anticipo'
        );

        $tendered = $data->tenderedAmountMinor;
        $change = null;

        if (
            $data->method
                === CommercePaymentMethod::Cash
        ) {
            if ($reference !== null) {
                throw new DomainException(
                    'El efectivo no utiliza referencia electrónica.'
                );
            }

            if ($tendered !== null) {
                if ($tendered < $data->amountMinor) {
                    throw new DomainException(
                        'El dinero entregado no puede ser menor que el anticipo.'
                    );
                }

                $change =
                    $tendered - $data->amountMinor;
            }
        } else {
            if ($tendered !== null) {
                throw new DomainException(
                    'Sólo el efectivo admite dinero entregado y vuelto.'
                );
            }

            if ($reference === null) {
                throw new DomainException(
                    'El anticipo no efectivo requiere una referencia.'
                );
            }
        }

        $idempotencyKey = Str::of(
            $data->idempotencyKey
        )->squish()->toString();

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave de idempotencia del anticipo no es válida.'
            );
        }

        return [
            'currency_code' => $currency,
            'method' => $data->method->value,
            'amount_minor' => $data->amountMinor,
            'financial_account_id' =>
                $data->financialAccountId,
            'reference' => $reference,
            'tendered_amount_minor' => $tendered,
            'change_amount_minor' => $change,
            'notes' => $notes,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function lockOrganization(
        int $organizationId
    ): void {
        $exists = Organization::query()
            ->whereKey($organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }

    private function optional(
        ?string $value,
        int $maxLength,
        string $label
    ): ?string {
        $value = $value === null
            ? null
            : Str::of($value)
                ->squish()
                ->toString();

        if ($value === '') {
            return null;
        }

        if (
            $value !== null
            && mb_strlen($value) > $maxLength
        ) {
            throw new DomainException(
                "{$label} supera la longitud admitida."
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(
        array $payload
    ): string {
        try {
            return hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo consolidar la huella del anticipo.',
                previous: $exception
            );
        }
    }
}
