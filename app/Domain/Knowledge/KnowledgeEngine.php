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
            return $this->notFoundResponse($query);
        }

        $exactUuidEntity = $this->findExactUuidEntity($query);

        if ($exactUuidEntity) {
            return $this->buildResolvedResponse(
                $exactUuidEntity,
                $query,
                'uuid'
            );
        }

        $exactIdentifierEntities = $this
            ->findExactIdentifierEntities($query);

        if ($exactIdentifierEntities->count() === 1) {
            return $this->buildResolvedResponse(
                $exactIdentifierEntities->first(),
                $query,
                'identifier'
            );
        }

        if ($exactIdentifierEntities->count() > 1) {
            return $this->buildAmbiguousExactResponse(
                $exactIdentifierEntities,
                $query
            );
        }

        $candidates = $this->findCandidates($query);

        if ($candidates->isEmpty()) {
            return $this->notFoundResponse($query);
        }

        return [
            'status' => 'candidates',
            'resolved' => false,
            'query' => $query,
            'reason' => 'partial_match',
            'candidates' => $candidates->values()->all(),
        ];
    }

    private function findExactUuidEntity(string $query): ?Entity
    {
        return Entity::query()
            ->where('active', true)
            ->whereRaw(
                'LOWER(uuid) = ?',
                [mb_strtolower($query)]
            )
            ->with($this->resolvedRelations())
            ->first();
    }

    private function findExactIdentifierEntities(
        string $query
    ): Collection {
        $normalizedQuery = mb_strtolower(trim($query));

        return Entity::query()
            ->where('active', true)
            ->whereHas(
                'identifiers',
                function (Builder $identifierQuery) use (
                    $normalizedQuery
                ): void {
                    $identifierQuery
                        ->where('active', true)
                        ->whereRaw(
                            'LOWER(TRIM(value)) = ?',
                            [$normalizedQuery]
                        );
                }
            )
            ->with($this->resolvedRelations())
            ->limit(25)
            ->get();
    }

    private function findCandidates(string $query): Collection
    {
        $normalizedQuery = mb_strtolower($query);
        $likeQuery = '%' . $normalizedQuery . '%';

        return Entity::query()
            ->where('active', true)
            ->where(function (Builder $builder) use ($likeQuery) {
                $builder
                    ->whereRaw(
                        'LOWER(name) LIKE ?',
                        [$likeQuery]
                    )
                    ->orWhereHas(
                        'identifiers',
                        function (
                            Builder $identifierQuery
                        ) use ($likeQuery): void {
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
            ->map(function (
                Entity $entity
            ) use ($normalizedQuery): array {
                $match = $this->calculateCandidateMatch(
                    $entity,
                    $normalizedQuery
                );

                return $this->candidatePayload(
                    $entity,
                    $match['score'],
                    $match['matched_by'],
                    $match['matched_value']
                );
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

        $normalizedName = mb_strtolower(
            (string) $entity->name
        );

        if ($normalizedName !== '') {
            if (
                str_starts_with(
                    $normalizedName,
                    $normalizedQuery
                )
            ) {
                $bestMatch = [
                    'score' => 80,
                    'matched_by' => 'name_starts_with',
                    'matched_value' => $entity->name,
                ];
            } elseif (
                str_contains(
                    $normalizedName,
                    $normalizedQuery
                )
            ) {
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

            if (
                str_starts_with(
                    $normalizedValue,
                    $normalizedQuery
                )
            ) {
                $score = 90;
                $matchedBy = 'identifier_starts_with';
            } elseif (
                str_contains(
                    $normalizedValue,
                    $normalizedQuery
                )
            ) {
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

    private function buildAmbiguousExactResponse(
        Collection $entities,
        string $query
    ): array {
        $normalizedQuery = mb_strtolower(trim($query));

        return [
            'status' => 'candidates',
            'resolved' => false,
            'query' => $query,
            'reason' => 'ambiguous_exact_identifier',
            'candidates' => $entities
                ->map(function (
                    Entity $entity
                ) use ($normalizedQuery): array {
                    $matchedIdentifier = $entity->identifiers
                        ->first(
                            fn ($identifier): bool =>
                                mb_strtolower(
                                    trim(
                                        (string) $identifier->value
                                    )
                                ) === $normalizedQuery
                        );

                    return $this->candidatePayload(
                        $entity,
                        100,
                        'identifier_exact',
                        $matchedIdentifier?->value
                    );
                })
                ->values()
                ->all(),
        ];
    }

    private function candidatePayload(
        Entity $entity,
        int $score,
        string $matchedBy,
        ?string $matchedValue
    ): array {
        return [
            'uuid' => $entity->uuid,
            'name' => $entity->name,
            'type' => $entity->entityType?->name,
            'score' => $score,
            'matched_by' => $matchedBy,
            'matched_value' => $matchedValue,
            'identifiers' => $entity->identifiers
                ->map(fn ($identifier) => [
                    'value' => $identifier->value,
                    'type' => $identifier
                        ->identifierType?->name,
                    'is_primary' => $identifier->is_primary,
                ])
                ->values()
                ->all(),
        ];
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
            'outgoingCompatibilities' => function (
                $compatibilityQuery
            ): void {
                $compatibilityQuery
                    ->where('active', true)
                    ->whereHas(
                        'rightEntity',
                        fn (Builder $entityQuery) =>
                            $entityQuery->where('active', true)
                    )
                    ->with('rightEntity');
            },
            'incomingCompatibilities' => function (
                $compatibilityQuery
            ): void {
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
        string $query,
        string $matchedBy
    ): array {
        return [
            'status' => 'resolved',
            'resolved' => true,
            'query' => $query,
            'match_type' => 'exact',
            'matched_by' => $matchedBy,
            'entity' => $entity,
            'compatibilities' => [
                'outgoing' =>
                    $entity->outgoingCompatibilities,
                'incoming' =>
                    $entity->incomingCompatibilities,
            ],
        ];
    }

    private function notFoundResponse(string $query): array
    {
        return [
            'status' => 'not_found',
            'resolved' => false,
            'query' => $query,
            'candidates' => [],
        ];
    }
}
