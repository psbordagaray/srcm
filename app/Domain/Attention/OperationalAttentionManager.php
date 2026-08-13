<?php

namespace App\Domain\Attention;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\OperationalAttentionReceipt;
use App\Models\User;
use DomainException;

final class OperationalAttentionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly OperationalAttentionReader $reader
    ) {
    }

    public function acknowledge(
        User $actor,
        string $attentionKey
    ): OperationalAttentionReceipt {
        $item = $this->reader->findAcknowledgeable(
            $actor,
            $attentionKey
        );

        if ($item === null) {
            throw new DomainException(
                'El aviso no existe, no pertenece al usuario o no puede marcarse como visto.'
            );
        }

        $organizationId = $this->currentOrganization->id($actor);

        return OperationalAttentionReceipt::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'user_id' => $actor->id,
                'attention_key' => $item['key'],
            ],
            [
                'source_type' => $item['source_type'],
                'source_public_id' => $item['source_public_id'],
                'acknowledged_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
