<?php

namespace App\Domain\Knowledge;

use App\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KnowledgeEngine
{
    public function resolve(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [
                'status' => 'not_found',
                'resolved' => false,
                'query' => $query,
                'candidates' => [],
            ];
        }

        $exactEntity = $this->findExactEntity($query);

        if ($exactEntity) {
            return $this->buildResolvedResponse($exactEntity, $query);
        }

        $candidates = $this->findCandidates($query);

        if ($candidates->isEmpty()) {
            return [
                'status' => 'not_found',
                'resolved' => false,
                'query' => $query,
                'candidates' => [],
            ];
        }

        return [
            'status' => 'candidates',
            'resolved' => false,
            'query' => $query,
            'candidates' => $candidates->values()->all(),
        ];
    }

    private function findExactEntity(string $query): ?Entity
    {
        $normalizedQuery = mb_strtolower($query);

        return Entity::query()
            ->where('active', true)
            ->where(function (Builder $builder) use ($normalizedQuery) {
                $builder
                    ->whereRaw('LOWER(uuid) = ?', [$normalizedQuery])
                    ->orWhereHas(
                        'identifiers',
                        function (Builder $identifierQuery) use (
                            $normalizedQuery
                        ) {
                            $identifierQuery
                                ->where('active', true)
                                ->whereRaw(
                                    'LOWER(value) = ?',
                                    [$normalizedQuery]
                                );
                        }
                    );
            })
            ->with($this->resolvedRelations())
            ->first();
    }

    private function findCandidates(string $query): Collection
    {
        $normalizedQuery = mb_strtolower($query);
        $likeQuery = '%' . $normalizedQuery . '%';

        return Entity::query()
            ->where('active', true)
            ->where(function (Builder $builder) use ($likeQuery) {
                $builder
                    ->whereRaw('LOWER(name) LIKE ?', [$likeQuery])
                    ->orWhereHas(
                        'identifiers',
                        function (Builder $identifierQuery) use (
                            $likeQuery
                        ) {
                            $identifierQuery
                                ->where('active', true)
                                ->whereRaw(
                                    'LOWER(value) LIKE ?',
                                    [$likeQuery]
                                );
                        }
                    );
            })
            ->with([
                'entityType',
                'identifiers' => fn ($identifierQuery) =>
                    $identifierQuery->where('active', true),
                'identifiers.identifierType',
            ])
            ->limit(25)
            ->get()
            ->map(function (Entity $entity) use ($normalizedQuery) {
                $match = $this->calculateCandidateMatch(
                    $entity,
                    $normalizedQuery
                );

                return [
                    'uuid' => $entity->uuid,
                    'name' => $entity->name,
                    'type' => $entity->entityType?->name,
                    'score' => $match['score'],
                    'matched_by' => $match['matched_by'],
                    'matched_value' => $match['matched_value'],
                    'identifiers' => $entity->identifiers
                        ->map(fn ($identifier) => [
                            'value' => $identifier->value,
                            'type' => $identifier->identifierType?->name,
                            'is_primary' => $identifier->is_primary,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('score');
    }

    private function calculateCandidateMatch(
        Entity $entity,
        string $normalizedQuery
    ): array {
        $bestMatch = [
            'score' => 0,
            'matched_by' => 'entity',
            'matched_value' => $entity->name,
        ];

        $normalizedName = mb_strtolower((string) $entity->name);

        if ($normalizedName !== '') {
            if (str_starts_with($normalizedName, $normalizedQuery)) {
                $bestMatch = [
                    'score' => 80,
                    'matched_by' => 'name_starts_with',
                    'matched_value' => $entity->name,
                ];
            } elseif (str_contains($normalizedName, $normalizedQuery)) {
                $bestMatch = [
                    'score' => 60,
                    'matched_by' => 'name_contains',
                    'matched_value' => $entity->name,
                ];
            }
        }

        foreach ($entity->identifiers as $identifier) {
            $normalizedValue = mb_strtolower(
                (string) $identifier->value
            );

            if (str_starts_with($normalizedValue, $normalizedQuery)) {
                $score = 90;
                $matchedBy = 'identifier_starts_with';
            } elseif (str_contains($normalizedValue, $normalizedQuery)) {
                $score = 70;
                $matchedBy = 'identifier_contains';
            } else {
                continue;
            }

            if ($score > $bestMatch['score']) {
                $bestMatch = [
                    'score' => $score,
                    'matched_by' => $matchedBy,
                    'matched_value' => $identifier->value,
                ];
            }
        }

        return $bestMatch;
    }

    private function resolvedRelations(): array
    {
        return [
            'entityType',
            'identifiers' => fn ($identifierQuery) =>
                $identifierQuery->where('active', true),
            'identifiers.identifierType',
            'assertions' => fn ($assertionQuery) =>
                $assertionQuery->where('active', true),
            'outgoingCompatibilities' => function ($compatibilityQuery) {
                $compatibilityQuery
                    ->where('active', true)
                    ->whereHas(
                        'rightEntity',
                        fn (Builder $entityQuery) =>
                            $entityQuery->where('active', true)
                    )
                    ->with('rightEntity');
            },
            'incomingCompatibilities' => function ($compatibilityQuery) {
                $compatibilityQuery
                    ->where('active', true)
                    ->whereHas(
                        'leftEntity',
                        fn (Builder $entityQuery) =>
                            $entityQuery->where('active', true)
                    )
                    ->with('leftEntity');
            },
        ];
    }

    private function buildResolvedResponse(
        Entity $entity,
        string $query
    ): array {
        return [
            'status' => 'resolved',
            'resolved' => true,
            'query' => $query,
            'match_type' => 'exact',
            'entity' => $entity,
            'compatibilities' => [
                'outgoing' => $entity->outgoingCompatibilities,
                'incoming' => $entity->incomingCompatibilities,
            ],
        ];
    }
}
