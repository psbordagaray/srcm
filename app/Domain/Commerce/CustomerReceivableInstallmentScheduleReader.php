<?php

namespace App\Domain\Commerce;

use App\Models\CustomerReceivable;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CustomerReceivableInstallmentScheduleReader
{
    public const VIEW =
        'customer_receivable_installment_balances';

    /**
     * @param Collection<int, CustomerReceivable> $receivables
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    public function rowsForReceivables(
        Collection $receivables
    ): Collection {
        if ($receivables->isEmpty()) {
            return collect();
        }

        $organizationIds = $receivables
            ->pluck('organization_id')
            ->unique()
            ->values();

        if ($organizationIds->count() !== 1) {
            throw new DomainException(
                'El cronograma derivado no puede mezclar organizaciones.'
            );
        }

        $receivableIds = $receivables
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $rows = DB::table(self::VIEW)
            ->where(
                'organization_id',
                (int) $organizationIds->first()
            )
            ->whereIn(
                'customer_receivable_id',
                $receivableIds
            )
            ->orderBy('customer_receivable_id')
            ->orderBy('sequence')
            ->get()
            ->map(
                fn (object $row): array =>
                    $this->normalizeRow($row)
            );

        $grouped = $rows->groupBy(
            'customer_receivable_id'
        );

        foreach ($receivableIds as $receivableId) {
            if (! $grouped->has($receivableId)) {
                throw new DomainException(
                    'Una cuenta por cobrar quedó fuera de su cronograma derivado.'
                );
            }
        }

        return $grouped;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsForReceivable(
        CustomerReceivable $receivable
    ): Collection {
        return $this->rowsForReceivables(
            collect([$receivable])
        )->get(
            (int) $receivable->id,
            collect()
        );
    }

    public function outstandingMinor(
        CustomerReceivable $receivable
    ): int {
        return (int) $this
            ->rowsForReceivable($receivable)
            ->sum('outstanding_minor');
    }

    /** @return array<string, mixed> */
    private function normalizeRow(
        object $row
    ): array {
        return [
            'organization_id' =>
                (int) $row->organization_id,
            'customer_receivable_id' =>
                (int) $row->customer_receivable_id,
            'installment_id' =>
                $row->installment_id === null
                    ? null
                    : (int) $row->installment_id,
            'installment_public_id' =>
                $row->installment_public_id,
            'sequence' => (int) $row->sequence,
            'installment_count' =>
                (int) $row->installment_count,
            'due_on' => $row->due_on === null
                ? null
                : CarbonImmutable::parse(
                    (string) $row->due_on
                )->startOfDay(),
            'original_minor' =>
                (int) $row->amount_minor,
            'collected_minor' =>
                (int) $row->collected_minor,
            'outstanding_minor' =>
                (int) $row->outstanding_minor,
            'planned' => (bool) $row->planned,
        ];
    }
}
