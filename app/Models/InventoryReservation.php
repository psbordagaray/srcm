<?php
namespace App\Models;
use App\Enums\InventoryCondition;
use App\Enums\InventoryReservationStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
class InventoryReservation extends Model
{
    protected $fillable = [
        'organization_id','public_id','catalog_product_id','inventory_location_id',
        'condition','quantity','base_unit_code','status','expires_at','released_at',
        'release_reason','created_by_user_id','released_by_user_id','idempotency_key','fingerprint',
    ];
    protected function casts(): array
    {
        return [
            'condition' => InventoryCondition::class,
            'quantity' => 'decimal:6',
            'status' => InventoryReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
    protected static function booted(): void
    {
        static::updating(function (self $reservation): void {
            foreach ([
                'organization_id','public_id','catalog_product_id','inventory_location_id',
                'condition','quantity','base_unit_code','created_by_user_id','idempotency_key',
                'fingerprint','expires_at',
            ] as $attribute) {
                if ($reservation->isDirty($attribute)) {
                    throw new DomainException('La dimension de una reserva confirmada es inmutable.');
                }
            }
        });
        static::deleting(function (): void {
            throw new DomainException('Las reservas no se eliminan fisicamente.');
        });
    }
    public function isEffective(): bool
    {
        return $this->status === InventoryReservationStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}