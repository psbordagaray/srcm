<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Offline\RestrictedOfflineSignedGrantContract;
use App\Enums\OperationalDeviceCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

final class RestrictedOfflineSignedGrantIssueRequest extends FormRequest
{
    public const SESSION_KEY = 'srcm.restricted_offline.signed_grant.verification';

    protected PublicKeyCredential $publicKeyCredential;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'ttl_seconds' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.RestrictedOfflineSignedGrantContract::HARD_MAX_TTL_SECONDS,
            ],
            'capabilities' => ['sometimes', 'array', 'min:1', 'max:1'],
            'capabilities.*' => [
                'required',
                'string',
                Rule::in([
                    OperationalDeviceCapability::RestrictedOfflineReadModel->value,
                ]),
            ],
        ];
    }

    protected function passedValidation(): void
    {
        try {
            $this->publicKeyCredential = WebAuthn::fromJson(
                json_encode($this->input('credential')) ?: '{}',
                PublicKeyCredential::class
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid passkey credential format.',
            ]);
        }
    }

    public function credential(): PublicKeyCredential
    {
        return $this->publicKeyCredential;
    }

    public function verificationOptionsFor(
        string $bindingPublicId,
        int|string $userId,
        int $organizationId,
    ): PublicKeyCredentialRequestOptions {
        $state = $this->session()->pull(self::SESSION_KEY);

        if (! is_array($state)) {
            throw $this->expired();
        }

        $keys = array_keys($state);
        sort($keys, SORT_STRING);
        if (
            $keys !== [
                'binding_public_id',
                'issued_at',
                'organization_id',
                'serialized',
                'user_id',
            ]
            || ! is_int($state['issued_at'])
            || ! is_string($state['serialized'])
            || ! is_string($state['binding_public_id'])
            || ! is_int($state['organization_id'])
            || ! is_string($state['user_id'])
            || ! hash_equals($state['binding_public_id'], $bindingPublicId)
            || $state['organization_id'] !== $organizationId
            || ! hash_equals($state['user_id'], (string) $userId)
        ) {
            throw $this->expired();
        }

        $now = time();
        $age = $now - $state['issued_at'];
        if (
            $age < 0
            || $age > RestrictedOfflineSignedGrantContract::PASSKEY_CONFIRMATION_MAX_AGE_SECONDS
        ) {
            throw $this->expired();
        }

        try {
            return WebAuthn::fromJson(
                $state['serialized'],
                PublicKeyCredentialRequestOptions::class
            );
        } catch (Throwable) {
            throw $this->expired();
        }
    }

    /** @return list<string> */
    public function requestedCapabilities(): array
    {
        $capabilities = $this->input('capabilities');

        return is_array($capabilities)
            ? array_values($capabilities)
            : [OperationalDeviceCapability::RestrictedOfflineReadModel->value];
    }

    public function requestedTtlSeconds(): ?int
    {
        return $this->filled('ttl_seconds')
            ? $this->integer('ttl_seconds')
            : null;
    }

    private function expired(): ValidationException
    {
        return ValidationException::withMessages([
            'credential' => 'Signed grant passkey verification session expired. Please try again.',
        ]);
    }
}
