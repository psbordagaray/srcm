<?php

namespace App\Models;

use App\Enums\CommerceSaleStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerReceivableInstallment extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'customer_receivable_id',
        'sequence',
        'due_on',
        'amount_minor',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerReceivableInstallment $installment
        ): void {
            if (blank($installment->public_id)) {
                $installment->public_id =
                    (string) Str::uuid();
            }

            $installment->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una cuota propia programada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una cuota propia programada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(
            CustomerReceivable::class,
            'customer_receivable_id'
        );
    }

    private function guardCreation(): void
    {
        if (
            (int) $this->sequence < 1
            || (int) $this->sequence > 120
            || (int) $this->amount_minor < 1
            || $this->due_on === null
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La cuota propia no conserva secuencia, vencimiento, importe y huella válidos.'
            );
        }

        $receivable = CustomerReceivable::query()
            ->whereKey($this->customer_receivable_id)
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->with('sale')
            ->first();

        if (
            ! $receivable
            || ! $receivable->sale
            || $receivable->sale->status
                !== CommerceSaleStatus::Building
            || CarbonImmutable::parse($this->due_on)
                ->startOfDay()
                ->lt(
                    $receivable->sale->sold_at
                        ->startOfDay()
                )
        ) {
            throw new DomainException(
                'La cuota propia debe pertenecer a una cuenta por cobrar de una venta en preparación.'
            );
        }

        if (CustomerReceivableInstallmentPlan::query()
            ->forOrganization((int) $this->organization_id)
            ->where(
                'customer_receivable_id',
                $receivable->id
            )
            ->exists()
        ) {
            throw new DomainException(
                'No pueden agregarse cuotas después de reconocer el cronograma.'
            );
        }
    }
}
