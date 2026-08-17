<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect(
            $this->input('lines', [])
        )->map(function (mixed $line): mixed {
            if (! is_array($line)) {
                return $line;
            }

            if (
                array_key_exists(
                    'purchase_order_line_id',
                    $line
                )
                && blank(
                    $line['purchase_order_line_id']
                )
            ) {
                $line['purchase_order_line_id'] =
                    null;
            }

            return $line;
        })->values()->all();

        $this->merge([
            'document_number' =>
                trim(
                    (string) $this->input(
                        'document_number',
                        ''
                    )
                ),
            'idempotency_key' =>
                trim(
                    (string) $this->input(
                        'idempotency_key',
                        ''
                    )
                ),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'document_number' => [
                'required',
                'string',
                'max:255',
            ],
            'issued_on' => [
                'required',
                'date_format:Y-m-d',
            ],
            'due_on' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:issued_on',
            ],
            'logistics_amount' => [
                'required',
                'decimal:0,2',
                'min:0',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:4000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
            ],
            'lines' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'lines.*.purchase_order_line_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'lines.*.description' => [
                'required',
                'string',
                'max:255',
            ],
            'lines.*.supplier_code' => [
                'nullable',
                'string',
                'max:100',
            ],
            'lines.*.quantity' => [
                'required',
                'decimal:0,6',
                'gt:0',
            ],
            'lines.*.unit_cost' => [
                'required',
                'decimal:0,2',
                'min:0',
            ],
        ];
    }
}
