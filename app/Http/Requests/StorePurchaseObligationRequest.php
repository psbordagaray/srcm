<?php

namespace App\Http\Requests;

use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create-purchase-obligations'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'kind' => trim((string) $this->input('kind')),
            'beneficiary_business_party_id' =>
                ctype_digit(
                    (string) $this->input(
                        'beneficiary_business_party_id'
                    )
                )
                    ? (int) $this->input(
                        'beneficiary_business_party_id'
                    )
                    : null,
            'payment_condition' => trim(
                (string) $this->input('payment_condition')
            ),
            'due_on' => filled($this->input('due_on'))
                ? trim((string) $this->input('due_on'))
                : null,
            'condition_note' => filled(
                $this->input('condition_note')
            )
                ? trim((string) $this->input('condition_note'))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'kind' => [
                'required',
                Rule::in(array_map(
                    fn (PurchaseObligationKind $kind): string =>
                        $kind->value,
                    PurchaseObligationKind::cases()
                )),
            ],
            'beneficiary_business_party_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'payment_condition' => [
                'required',
                Rule::in(array_map(
                    fn (
                        PurchaseObligationCondition $condition
                    ): string => $condition->value,
                    PurchaseObligationCondition::cases()
                )),
            ],
            'due_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'condition_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $condition = PurchaseObligationCondition::tryFrom(
                    (string) $this->input('payment_condition')
                );

                if (
                    $condition === PurchaseObligationCondition::DueDate
                    && blank($this->input('due_on'))
                ) {
                    $validator->errors()->add(
                        'due_on',
                        'Indicá la fecha de vencimiento.'
                    );
                }

                if (
                    $condition !== null
                    && $condition
                        !== PurchaseObligationCondition::DueDate
                    && filled($this->input('due_on'))
                ) {
                    $validator->errors()->add(
                        'due_on',
                        'Sólo una obligación con vencimiento usa fecha.'
                    );
                }

                if (
                    $condition === PurchaseObligationCondition::Other
                    && blank($this->input('condition_note'))
                ) {
                    $validator->errors()->add(
                        'condition_note',
                        'Describí la condición de pago.'
                    );
                }
            },
        ];
    }
}
