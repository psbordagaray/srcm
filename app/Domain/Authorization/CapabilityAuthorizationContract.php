<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;
use JsonException;

final readonly class CapabilityAuthorizationContract
{
    public const SCHEMA = 'straleon.capability-authorization.v1';

    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'schema',
        'capability',
        'principal_type',
        'principal_id',
        'scope_type',
        'scope_id',
        'environment_id',
        'organization_id',
        'release_sha',
        'decision',
        'authorization_source',
        'evidence_ref',
    ];

    public function __construct(
        public Capability $capability,
        public CapabilityPrincipal $principalType,
        public string $principalId,
        public CapabilityScope $scopeType,
        public string $scopeId,
        public ?string $environmentId,
        public ?int $organizationId,
        public ?string $releaseSha,
        public CapabilityDecision $decision = CapabilityDecision::Deny,
        public ?string $authorizationSource = null,
        public ?string $evidenceRef = null,
    ) {
        self::assertToken($this->principalId, 'principal_id', 128);
        self::assertToken($this->scopeId, 'scope_id', 256);

        if ($this->environmentId !== null) {
            self::assertToken($this->environmentId, 'environment_id', 128);
        }

        if ($this->organizationId !== null && $this->organizationId < 1) {
            throw new InvalidArgumentException(
                'organization_id must be a positive integer when present.'
            );
        }

        if (
            $this->releaseSha !== null
            && preg_match('/^[0-9a-f]{40}$/D', $this->releaseSha) !== 1
        ) {
            throw new InvalidArgumentException(
                'release_sha must be lowercase hexadecimal SHA-1 when present.'
            );
        }

        $this->assertExactScopeBinding();

        if ($this->decision === CapabilityDecision::Allow) {
            if (
                $this->authorizationSource === null
                || $this->evidenceRef === null
            ) {
                throw new InvalidArgumentException(
                    'ALLOW requires authorization_source and evidence_ref.'
                );
            }

            self::assertToken(
                $this->authorizationSource,
                'authorization_source',
                256,
            );
            self::assertToken($this->evidenceRef, 'evidence_ref', 512);
        } else {
            if ($this->authorizationSource !== null) {
                self::assertToken(
                    $this->authorizationSource,
                    'authorization_source',
                    256,
                );
            }

            if ($this->evidenceRef !== null) {
                self::assertToken($this->evidenceRef, 'evidence_ref', 512);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException(
                'Capability authorization schema mismatch fails closed.'
            );
        }

        $extra = array_values(array_diff(array_keys($data), self::REQUIRED_FIELDS));
        if ($extra !== []) {
            throw new InvalidArgumentException(
                'Capability authorization contains uncontracted fields.'
            );
        }

        $requiredInput = array_values(array_diff(
            self::REQUIRED_FIELDS,
            ['decision'],
        ));

        foreach ($requiredInput as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "Capability authorization field [{$field}] is required."
                );
            }
        }

        $scope = CapabilityScope::tryFrom((string) $data['scope_type']);
        $principal = CapabilityPrincipal::tryFrom((string) $data['principal_type']);

        $decisionValue = array_key_exists('decision', $data)
            ? (string) $data['decision']
            : CapabilityDecision::Deny->value;
        $decision = CapabilityDecision::tryFrom($decisionValue);

        if ($scope === null || $principal === null || $decision === null) {
            throw new InvalidArgumentException(
                'Unknown scope, principal or decision fails closed.'
            );
        }

        $organizationId = $data['organization_id'];
        if ($organizationId !== null && ! is_int($organizationId)) {
            throw new InvalidArgumentException(
                'organization_id must be an integer or null.'
            );
        }

        return new self(
            capability: new Capability((string) $data['capability']),
            principalType: $principal,
            principalId: (string) $data['principal_id'],
            scopeType: $scope,
            scopeId: (string) $data['scope_id'],
            environmentId: $data['environment_id'] === null
                ? null
                : (string) $data['environment_id'],
            organizationId: $organizationId,
            releaseSha: $data['release_sha'] === null
                ? null
                : (string) $data['release_sha'],
            decision: $decision,
            authorizationSource: $data['authorization_source'] === null
                ? null
                : (string) $data['authorization_source'],
            evidenceRef: $data['evidence_ref'] === null
                ? null
                : (string) $data['evidence_ref'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'capability' => $this->capability->value,
            'principal_type' => $this->principalType->value,
            'principal_id' => $this->principalId,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'environment_id' => $this->environmentId,
            'organization_id' => $this->organizationId,
            'release_sha' => $this->releaseSha,
            'decision' => $this->decision->value,
            'authorization_source' => $this->authorizationSource,
            'evidence_ref' => $this->evidenceRef,
        ];
    }

    /** @throws JsonException */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    private function assertExactScopeBinding(): void
    {
        match ($this->scopeType) {
            CapabilityScope::Organization => $this->assertOrganizationScope(),
            CapabilityScope::Installation => null,
            CapabilityScope::Environment => $this->assertEnvironmentScope(),
            CapabilityScope::Release => $this->assertReleaseScope(),
        };
    }

    private function assertOrganizationScope(): void
    {
        if (
            $this->organizationId === null
            || $this->scopeId !== (string) $this->organizationId
        ) {
            throw new InvalidArgumentException(
                'ORGANIZATION scope requires scope_id to equal organization_id.'
            );
        }
    }

    private function assertEnvironmentScope(): void
    {
        if (
            $this->environmentId === null
            || $this->scopeId !== $this->environmentId
        ) {
            throw new InvalidArgumentException(
                'ENVIRONMENT scope requires scope_id to equal environment_id.'
            );
        }
    }

    private function assertReleaseScope(): void
    {
        if (
            $this->releaseSha === null
            || $this->scopeId !== $this->releaseSha
        ) {
            throw new InvalidArgumentException(
                'RELEASE scope requires scope_id to equal release_sha.'
            );
        }
    }

    private static function assertToken(
        string $value,
        string $field,
        int $maxLength,
    ): void {
        if (
            $value === ''
            || strlen($value) > $maxLength
            || trim($value) !== $value
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException(
                "{$field} must be a non-empty canonical token."
            );
        }
    }
}
