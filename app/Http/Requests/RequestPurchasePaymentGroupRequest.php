<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestPurchasePaymentGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'request-purchase-payments'
        ) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items', []);

        if (! is_array($items)) {
            $items = [];
        }

        $selected = [];

        foreach ($items as $item) {
            if (
                ! is_array($item)
                || ! filter_var(
                    $item['selected'] ?? false,
                    FILTER_VALIDATE_BOOL
                )
            ) {
                continue;
            }

            $selected[] = [
                'purchase_obligation_id' => trim(
                    (string) ($item['purchase_obligation_id'] ?? '')
                ),
                'amount' => trim(
                    (string) ($item['amount'] ?? '')
                ),
            ];
        }

        $this->merge([
            'origin_financial_account_id' =>
                $this->input('origin_financial_account_id'),
            'items' => $selected,
            'request_note' => filled(
                $this->input('request_note')
            )
                ? trim((string) $this->input('request_note'))
                : null,
            'idempotency_key' => trim(
                (string) $this->input('idempotency_key')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'origin_financial_account_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'items' => [
                'required',
                'array',
                'min:2',
                'max:100',
            ],
            'items.*.purchase_obligation_id' => [
                'required',
                'uuid',
                'distinct',
            ],
            'items.*.amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
            ],
            'request_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^purchase-ui:payment-group-request:[0-9a-f-]{36}$/',
            ],
        ];
    }
}
