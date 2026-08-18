<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FiscalAuthorizationAttempt extends Model
{
    use BelongsToOrganization;
    protected $fillable = ['organization_id','public_id','fiscal_document_id','attempt_number','requested_at','recorded_by_user_id','idempotency_key','fingerprint'];
    protected static function booted(): void {
        static::creating(function (self $attempt): void { if (blank($attempt->public_id)) { $attempt->public_id = (string) Str::uuid(); } });
        static::updating(fn () => throw new DomainException('Un intento de autorización fiscal es inmutable.'));
        static::deleting(fn () => throw new DomainException('Un intento de autorización fiscal no puede eliminarse.'));
    }
    protected function casts(): array { return ['attempt_number'=>'integer','requested_at'=>'immutable_datetime']; }
    public function document(): BelongsTo { return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
    public function response(): HasOne { return $this->hasOne(FiscalAuthorizationResponse::class); }
}
