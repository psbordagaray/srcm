<?php

namespace App\Http\Requests;

use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFiscalPointOfSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-organization') ?? false;
    }

    public function rules(): array
    {
        return [
            'point_number' => [
                'required',
                'integer',
                'min:1',
                'max:99999',
            ],
            'environment' => [
                'required',
                Rule::enum(FiscalEnvironment::class),
            ],
            'integration_mode' => [
                'required',
                Rule::enum(FiscalIntegrationMode::class),
            ],
        ];
    }
}

