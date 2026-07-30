<?php

namespace App\Http\Requests;

use App\Models\BusinessParty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-commerce') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizedInput());
    }

    public function rules(): array
    {
        return [
            'party_type' => [
                'required',
                'string',
                Rule::in([
                    BusinessParty::TYPE_PERSON,
                    BusinessParty::TYPE_ORGANIZATION,
                ]),
            ],
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'email' => [
                'nullable',
                'string',
                'email:rfc',
                'max:255',
            ],
            'phone' => ['nullable', 'string', 'max:80'],
            'website' => [
                'nullable',
                'string',
                'url:http,https',
                'max:2048',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_type.required' => 'El tipo de proveedor es obligatorio.',
            'party_type.in' => 'El tipo de proveedor no es válido.',
            'name.required' => 'El nombre del proveedor es obligatorio.',
            'email.email' => 'El correo no posee un formato válido.',
            'website.url' => 'El sitio web no posee una URL válida.',
            'active.required' => 'El estado es obligatorio.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedInput(): array
    {
        return [
            'party_type' => Str::of(
                (string) $this->input('party_type')
            )->trim()->lower()->toString(),
            'name' => Str::of(
                (string) $this->input('name')
            )->squish()->toString(),
            'tax_id' => $this->filled('tax_id')
                ? Str::of((string) $this->input('tax_id'))
                    ->squish()
                    ->upper()
                    ->toString()
                : null,
            'email' => $this->filled('email')
                ? Str::of((string) $this->input('email'))
                    ->trim()
                    ->lower()
                    ->toString()
                : null,
            'phone' => $this->filled('phone')
                ? Str::of((string) $this->input('phone'))
                    ->squish()
                    ->toString()
                : null,
            'website' => $this->normalizedWebsite(),
            'notes' => $this->filled('notes')
                ? Str::of((string) $this->input('notes'))
                    ->trim()
                    ->toString()
                : null,
            'active' => $this->boolean('active'),
        ];
    }

    private function normalizedWebsite(): ?string
    {
        if (! $this->filled('website')) {
            return null;
        }

        $website = trim((string) $this->input('website'));

        return preg_match('#^https?://#i', $website)
            ? $website
            : 'https://'.$website;
    }
}
