<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ExplainInventoryNegativeAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('override-inventory-negative')
            ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => Str::of((string) $this->input('reason'))
                ->squish()->toString(),
        ]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' =>
                'El motivo administrativo es obligatorio.',
            'reason.min' =>
                'El motivo debe contener al menos 10 caracteres.',
            'reason.max' =>
                'El motivo no puede superar los 2000 caracteres.',
        ];
    }
}
