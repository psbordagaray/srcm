<?php

namespace App\Domain\Release;

use InvalidArgumentException;
use JsonException;

final class EnvironmentIdentity
{
    public const SCHEMA = 'straleon.environment-identity.v1';

    public const SCOPE_INSTALLATION = 'installation';

    public const SCOPE_ORGANIZATION = 'organization';

    public function __construct(
        public readonly string $environmentId,
        public readonly string $installationId,
        public readonly string $organizationScope,
        public readonly ?string $organizationId,
        public readonly int $deploymentGeneration,
        public readonly ?string $stableNodeName = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $environmentId) !== 1) {
            throw new InvalidArgumentException('Invalid environment id.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,127}$/D', $installationId) !== 1) {
            throw new InvalidArgumentException('Invalid installation id.');
        }

        if (! in_array($organizationScope, [self::SCOPE_INSTALLATION, self::SCOPE_ORGANIZATION], true)) {
            throw new InvalidArgumentException('Invalid environment identity organization scope.');
        }

        if ($organizationScope === self::SCOPE_INSTALLATION && $organizationId !== null) {
            throw new InvalidArgumentException('Installation scope must not carry an organization id.');
        }

        if ($organizationScope === self::SCOPE_ORGANIZATION) {
            if ($organizationId === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $organizationId) !== 1) {
                throw new InvalidArgumentException('Organization scope requires a valid organization id.');
            }
        }

        if ($deploymentGeneration < 1) {
            throw new InvalidArgumentException('Deployment generation must be at least one.');
        }

        if ($stableNodeName !== null) {
            if (strlen($stableNodeName) > 253 || preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D', $stableNodeName) !== 1) {
                throw new InvalidArgumentException('Invalid stable node name.');
            }
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'environment_id' => $this->environmentId,
            'installation_id' => $this->installationId,
            'organization_scope' => $this->organizationScope,
            'organization_id' => $this->organizationId,
            'deployment_generation' => $this->deploymentGeneration,
            'stable_node_name' => $this->stableNodeName,
        ];
    }

    /** @throws JsonException */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
