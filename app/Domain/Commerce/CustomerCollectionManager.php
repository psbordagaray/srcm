<?php

namespace App\Domain\Commerce;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerCollectionStatus;
use App\Enums\FinancialAccountType;
use App\Models\Customer;
use App\Models\CustomerCollection;
use App\Models\CustomerCollectionAllocation;
use App\Models\CustomerReceivable;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CustomerCollectionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CashRegisterSessionManager $cashSessions,
        private readonly CashLedgerRecorder $cashLedger
    ) {
    }

    public function collect(
        Customer $customer,
        CustomerCollectionData $data,
        User $actor
    ): CustomerCollection {
        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $customer,
            $normalized,
            $actor
        ): CustomerCollection {
            $organizationId = $this->currentOrganization->id($actor);
            $role = $this->currentOrganization->roleFor($actor);

            if (! ($role?->canRecordCustomerCollections() ?? false)) {
                throw new DomainException(
                    'El usuario no puede registrar cobranzas de clientes.'
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
                    (int) $lockedCustomer->business_party_id,
                ...$normalized,
            ]);

            $existing = CustomerCollection::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $normalized['idempotency_key']
                )
                ->with([
                    'allocations.receivable.sale',
                    'financialAccount',
                    'cashMovement',
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->business_party_id
                        !== (int) $lockedCustomer->business_party_id
                    || ! hash_equals(
                        $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La misma clave de cobranza fue reutilizada con otros datos.'
                    );
                }

                return $existing;
            }

            if (! $lockedCustomer->active) {
                throw new DomainException(
                    'La cobranza requiere un cliente activo.'
                );
            }

            $account = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($normalized['financial_account_id'])
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
                $cashAccount = $register?->financialAccount;

                if (
                    ! $cashSession
                    || ! $register
                    || ! $register->active
                    || ! $cashAccount
                    || ! $cashAccount->active
                    || $account->type !== FinancialAccountType::CashBox
                    || (int) $cashAccount->id !== (int) $account->id
                    || $cashSession->currency_code
                        !== $normalized['currency_code']
                ) {
                    throw new DomainException(
                        'Para cobrar en efectivo, usá la cuenta de la caja de tu turno abierto.'
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
                    'Una cobranza no efectiva no puede ingresar en una cuenta de efectivo.'
                );
            }

            $receivableIds = collect(
                $normalized['allocations']
            )
                ->pluck('customer_receivable_id')
                ->all();

            $receivables = CustomerReceivable::query()
                ->forOrganization($organizationId)
                ->whereIn('id', $receivableIds)
                ->where(
                    'business_party_id',
                    $lockedCustomer->business_party_id
                )
                ->where(
                    'currency_code',
                    $normalized['currency_code']
                )
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($receivables->count() !== count($receivableIds)) {
                throw new DomainException(
                    'Una deuda seleccionada no pertenece al cliente, a la organización o a la moneda de la cobranza.'
                );
            }

            foreach ($normalized['allocations'] as $allocation) {
                /** @var CustomerReceivable $receivable */
                $receivable = $receivables[
                    $allocation['customer_receivable_id']
                ];

                $alreadyCollected = (int) DB::table(
                    'customer_collection_allocations as allocation'
                )
                    ->join(
                        'customer_collections as collection',
                        'collection.id',
                        '=',
                        'allocation.customer_collection_id'
                    )
                    ->where(
                        'allocation.organization_id',
                        $organizationId
                    )
                    ->where(
                        'allocation.customer_receivable_id',
                        $receivable->id
                    )
                    ->where(
                        'collection.status',
                        CustomerCollectionStatus::Confirmed->value
                    )
                    ->sum('allocation.amount_minor');

                if (
                    $allocation['amount_minor']
                        > $receivable->amount_minor - $alreadyCollected
                ) {
                    throw new DomainException(
                        'Una aplicación supera el saldo pendiente de la deuda.'
                    );
                }
            }

            $now = CarbonImmutable::now();

            $collection = CustomerCollection::query()->create([
                'organization_id' => $organizationId,
                'business_party_id' =>
                    $lockedCustomer->business_party_id,
                'financial_account_id' => $account->id,
                'cash_register_session_id' =>
                    $cashSession?->id,
                'cash_register_id' =>
                    $cashSession?->cash_register_id,
                'status' => CustomerCollectionStatus::Building,
                'method' => $normalized['method'],
                'currency_code' => $normalized['currency_code'],
                'amount_minor' => $normalized['amount_minor'],
                'retain_excess_as_credit' =>
                    $normalized[
                        'retain_excess_as_credit'
                    ],
                'tendered_amount_minor' =>
                    $normalized['tendered_amount_minor'],
                'change_amount_minor' =>
                    $normalized['change_amount_minor'],
                'reference' => $normalized['reference'],
                'notes' => $normalized['notes'],
                'received_by_user_id' => $actor->id,
                'collected_at' => $now,
                'idempotency_key' =>
                    $normalized['idempotency_key'],
                'fingerprint' => $fingerprint,
                'created_at' => $now,
            ]);

            foreach (
                $normalized['allocations']
                as $index => $allocation
            ) {
                CustomerCollectionAllocation::query()->create([
                    'organization_id' => $organizationId,
                    'customer_collection_id' =>
                        $collection->id,
                    'customer_receivable_id' =>
                        $allocation['customer_receivable_id'],
                    'sequence' => $index + 1,
                    'amount_minor' =>
                        $allocation['amount_minor'],
                    'fingerprint' => $this->fingerprint([
                        'collection_public_id' =>
                            $collection->public_id,
                        'sequence' => $index + 1,
                        ...$allocation,
                    ]),
                    'created_at' => $now,
                ]);
            }

            $collection->status =
                CustomerCollectionStatus::Confirmed;
            $collection->save();

            if (
                $collection->method ===
                    CommercePaymentMethod::Cash
            ) {
                if (! $cashSession) {
                    throw new DomainException(
                        'La cobranza en efectivo perdió su turno de caja.'
                    );
                }

                $this->cashLedger->recordCustomerCollection(
                    $cashSession,
                    $collection,
                    $actor
                );
            }

            return $collection->refresh()->load([
                'allocations.receivable.sale',
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
     *     retain_excess_as_credit: bool,
     *     overpayment_minor: int,
     *     financial_account_id: int,
     *     allocations: list<array{
     *         customer_receivable_id: int,
     *         amount_minor: int
     *     }>,
     *     reference: ?string,
     *     tendered_amount_minor: ?int,
     *     change_amount_minor: ?int,
     *     notes: ?string,
     *     idempotency_key: string
     * }
     */
    private function normalize(
        CustomerCollectionData $data
    ): array {
        $currency = strtoupper(trim($data->currencyCode));

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException(
                'La moneda de la cobranza no es válida.'
            );
        }

        if (
            $data->method === CommercePaymentMethod::AccountCredit
        ) {
            throw new DomainException(
                'El saldo a favor del cliente no es un medio de cobranza de una cuenta por cobrar.'
            );
        }

        if (
            $data->amountMinor <= 0
            || $data->financialAccountId <= 0
        ) {
            throw new DomainException(
                'La cobranza requiere importe y cuenta financiera válidos.'
            );
        }

        $reference = $this->optional(
            $data->reference,
            255,
            'La referencia de la cobranza'
        );
        $notes = $this->optional(
            $data->notes,
            1000,
            'Las notas de la cobranza'
        );

        $tendered = $data->tenderedAmountMinor;
        $change = null;

        if ($data->method === CommercePaymentMethod::Cash) {
            if ($reference !== null) {
                throw new DomainException(
                    'El efectivo no utiliza referencia electrónica.'
                );
            }

            if ($tendered !== null) {
                if ($tendered < $data->amountMinor) {
                    throw new DomainException(
                        'El dinero entregado no puede ser menor que el importe cobrado.'
                    );
                }

                $change = $tendered - $data->amountMinor;
            }
        } else {
            if ($tendered !== null) {
                throw new DomainException(
                    'Sólo el efectivo admite dinero entregado y vuelto.'
                );
            }

            if ($reference === null) {
                throw new DomainException(
                    'La cobranza no efectiva requiere una referencia.'
                );
            }
        }

        if ($data->allocations === []) {
            throw new DomainException(
                'La cobranza requiere al menos una aplicación a deuda.'
            );
        }

        $allocations = [];

        foreach ($data->allocations as $allocation) {
            if (
                ! $allocation
                    instanceof CustomerCollectionAllocationData
                || $allocation->customerReceivableId <= 0
                || $allocation->amountMinor <= 0
            ) {
                throw new DomainException(
                    'Una aplicación de cobranza contiene datos inválidos.'
                );
            }

            $allocations[] = [
                'customer_receivable_id' =>
                    $allocation->customerReceivableId,
                'amount_minor' => $allocation->amountMinor,
            ];
        }

        usort(
            $allocations,
            static fn (array $left, array $right): int =>
                $left['customer_receivable_id']
                    <=> $right['customer_receivable_id']
        );

        $ids = array_column(
            $allocations,
            'customer_receivable_id'
        );

        if (count($ids) !== count(array_unique($ids))) {
            throw new DomainException(
                'Una deuda no puede repetirse dentro de la misma cobranza.'
            );
        }

        $allocationTotal = 0;

        foreach ($allocations as $allocation) {
            if (
                $allocationTotal >
                PHP_INT_MAX - $allocation['amount_minor']
            ) {
                throw new DomainException(
                    'El total aplicado supera el importe admitido.'
                );
            }

            $allocationTotal += $allocation['amount_minor'];
        }

        if ($allocationTotal > $data->amountMinor) {
            throw new DomainException(
                'Las aplicaciones no pueden superar el importe recibido.'
            );
        }

        $overpaymentMinor =
            $data->amountMinor - $allocationTotal;

        if (
            $overpaymentMinor > 0
            && ! $data->retainExcessAsCredit
        ) {
            throw new DomainException(
                'El excedente debe confirmarse explícitamente para quedar como saldo a favor.'
            );
        }

        $retainExcessAsCredit =
            $overpaymentMinor > 0;

        $idempotencyKey = Str::of(
            $data->idempotencyKey
        )->squish()->toString();

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave de idempotencia de cobranza no es válida.'
            );
        }

        return [
            'currency_code' => $currency,
            'method' => $data->method->value,
            'amount_minor' => $data->amountMinor,
            'retain_excess_as_credit' =>
                $retainExcessAsCredit,
            'overpayment_minor' =>
                $overpaymentMinor,
            'financial_account_id' => $data->financialAccountId,
            'allocations' => $allocations,
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
            : Str::of($value)->squish()->toString();

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
    private function fingerprint(array $payload): string
    {
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
                'No se pudo consolidar la huella de cobranza.',
                previous: $exception
            );
        }
    }
}
