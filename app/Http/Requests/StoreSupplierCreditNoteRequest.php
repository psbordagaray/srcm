<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_number' =>
                trim(
                    (string) $this->input(
                        'document_number',
                        ''
                    )
                ),
            'reason' =>
                trim(
                    (string) $this->input(
                        'reason',
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
            'amount' => [
                'required',
                'decimal:0,2',
                'gt:0',
            ],
            'reason' => [
                'required',
                'string',
                'max:1000',
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
        ];
    }
}
