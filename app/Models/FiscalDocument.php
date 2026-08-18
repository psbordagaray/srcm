<?php

namespace App\Models;

use App\Enums\FiscalDocumentState;
use App\Enums\FiscalDocumentType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FiscalDocument extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'public_id', 'fiscal_organization_profile_id',
        'fiscal_point_of_sale_id', 'commerce_sale_id', 'document_type',
        'issuer_snapshot', 'recipient_snapshot', 'currency_code',
        'service_subtotal_minor', 'product_subtotal_minor', 'total_minor',
        'documented_at', 'created_by_user_id', 'idempotency_key', 'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (FiscalDocument $document): void {
            if (blank($document->public_id)) {
                $document->public_id = (string) Str::uuid();
            }
        });
        static::updating(fn () => throw new DomainException(
            'Un documento fiscal es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un documento fiscal no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'document_type' => FiscalDocumentType::class,
            'issuer_snapshot' => 'array', 'recipient_snapshot' => 'array',
            'service_subtotal_minor' => 'integer', 'product_subtotal_minor' => 'integer',
            'total_minor' => 'integer', 'documented_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function state(): FiscalDocumentState
    {
        $response = $this->relationLoaded('authorizationAttempts')
            ? $this->authorizationAttempts->sortByDesc('attempt_number')->first()?->response
            : FiscalAuthorizationResponse::query()->whereHas('attempt', fn ($query) => $query->where('fiscal_document_id', $this->id))->latest('id')->first();
        return match ($response?->outcome) {
            \App\Enums\FiscalAuthorizationOutcome::Authorized => FiscalDocumentState::Authorized,
            \App\Enums\FiscalAuthorizationOutcome::Rejected => FiscalDocumentState::Rejected,
            \App\Enums\FiscalAuthorizationOutcome::Unknown => FiscalDocumentState::Contingency,
            default => FiscalDocumentState::Pending,
        };
    }
    public function profile(): BelongsTo { return $this->belongsTo(FiscalOrganizationProfile::class, 'fiscal_organization_profile_id'); }
    public function pointOfSale(): BelongsTo { return $this->belongsTo(FiscalPointOfSale::class, 'fiscal_point_of_sale_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(CommerceSale::class, 'commerce_sale_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function lines(): HasMany { return $this->hasMany(FiscalDocumentLine::class)->orderBy('position'); }
    public function authorizationAttempts(): HasMany { return $this->hasMany(FiscalAuthorizationAttempt::class)->orderBy('attempt_number'); }
    public function numberAssignment(): HasOne { return $this->hasOne(FiscalDocumentNumber::class); }
    public function taxComponents(): HasMany { return $this->hasMany(FiscalDocumentTax::class)->orderBy('position'); }
    public function classification(): HasOne { return $this->hasOne(FiscalDocumentClassification::class); }
    public function conceptRecord(): HasOne { return $this->hasOne(FiscalDocumentConcept::class); }
    public function recipientEvidence(): HasOne { return $this->hasOne(FiscalDocumentRecipientEvidence::class); }
    public function issueDateRecord(): HasOne { return $this->hasOne(FiscalDocumentIssueDate::class); }
    public function monetarySummary(): HasOne { return $this->hasOne(FiscalDocumentMonetarySummary::class); }
    public function currencyEvidence(): HasOne { return $this->hasOne(FiscalDocumentCurrencyEvidence::class); }
}
