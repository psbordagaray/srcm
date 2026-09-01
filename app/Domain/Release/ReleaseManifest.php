<?php

namespace App\Domain\Release;

use InvalidArgumentException;
use JsonException;

final class ReleaseManifest
{
    public const SCHEMA = 'straleon.release-manifest.v1';

    public function __construct(
        public readonly string $releaseSha,
        public readonly string $artifactSha256,
        public readonly string $sourceRef,
        public readonly EnvironmentIdentity $environmentIdentity,
    ) {
        if (preg_match('/^[0-9a-f]{40}$/D', $releaseSha) !== 1) {
            throw new InvalidArgumentException('Release SHA must be canonical lowercase 40-hex Git identity.');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $artifactSha256) !== 1) {
            throw new InvalidArgumentException('Artifact SHA-256 must be canonical lowercase 64-hex.');
        }

        if (preg_match('#^refs/(heads|tags)/[^\\s]+$#D', $sourceRef) !== 1) {
            throw new InvalidArgumentException('Source ref must be a canonical Git heads/tags ref.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'release_sha' => $this->releaseSha,
            'artifact_sha256' => $this->artifactSha256,
            'source_ref' => $this->sourceRef,
            'environment_identity' => $this->environmentIdentity->toArray(),
            'environment_fingerprint' => $this->environmentIdentity->fingerprint(),
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
