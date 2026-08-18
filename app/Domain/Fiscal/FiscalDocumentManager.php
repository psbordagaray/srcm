<?php

namespace App\Domain\Fiscal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSaleStatus;
use App\Enums\FiscalDocumentType;
use App\Models\CommerceSale;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalPointOfSale;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentManager
{
    public function __construct(private readonly CurrentOrganization $currentOrganization, private readonly AuditRecorder $audit) {}

    public function record(FiscalDocumentData $data, User $actor): FiscalDocument
    {
        $organizationId = $this->organizationId($actor);
        if ($data->documentType !== FiscalDocumentType::Invoice) {
            throw new DomainException('P10.2 sólo documenta facturas originadas en una venta confirmada.');
        }
        if (blank($data->idempotencyKey)) { throw new DomainException('La clave de idempotencia fiscal es obligatoria.'); }
        return DB::transaction(function () use ($data, $actor, $organizationId): FiscalDocument {
            $sale = CommerceSale::query()->forOrganization($organizationId)->with('lines')->lockForUpdate()->find($data->commerceSaleId);
            if (! $sale || $sale->status !== CommerceSaleStatus::Confirmed) { throw new DomainException('El documento fiscal requiere una venta confirmada de la organización activa.'); }
            $point = FiscalPointOfSale::query()->forOrganization($organizationId)->with('profile')->lockForUpdate()->find($data->fiscalPointOfSaleId);
            if (! $point || ! $point->active || ! $point->profile) { throw new DomainException('El punto de venta fiscal activo y su perfil son obligatorios.'); }
            $payload = $this->payload($sale, $point, $data);
            $existing = FiscalDocument::query()->forOrganization($organizationId)->where('idempotency_key', $data->idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->fingerprint !== $payload['fingerprint']) { throw new DomainException('La clave de idempotencia fiscal ya fue usada con otro documento.'); }
                return $existing->load('lines');
            }
            if (FiscalDocument::query()->forOrganization($organizationId)->where('commerce_sale_id', $sale->id)->where('document_type', $data->documentType->value)->exists()) { throw new DomainException('La venta ya posee su documento fiscal de este tipo.'); }
            $document = FiscalDocument::query()->create($payload + ['organization_id' => $organizationId, 'fiscal_organization_profile_id' => $point->profile->id, 'fiscal_point_of_sale_id' => $point->id, 'commerce_sale_id' => $sale->id, 'document_type' => $data->documentType, 'created_by_user_id' => $actor->id, 'documented_at' => CarbonImmutable::now(), 'idempotency_key' => $data->idempotencyKey]);
            foreach ($sale->lines as $line) {
                FiscalDocumentLine::query()->create(['organization_id' => $organizationId, 'fiscal_document_id' => $document->id, 'commerce_sale_line_id' => $line->id, 'position' => $line->position, 'line_type' => $line->line_type, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_price_minor' => $line->unit_price_minor, 'line_total_minor' => $line->line_total_minor, 'created_at' => CarbonImmutable::now()]);
            }
            $this->audit->record($document, 'fiscal_document_recorded', null, ['commerce_sale_id' => $sale->id, 'document_type' => $data->documentType->value, 'total_minor' => $sale->total_minor]);
            return $document->refresh()->load('lines', 'profile', 'pointOfSale', 'sale');
        }, 3);
    }
    private function payload(CommerceSale $sale, FiscalPointOfSale $point, FiscalDocumentData $data): array
    {
        $issuer = ['legal_name' => $point->profile->legal_name, 'tax_id' => $point->profile->tax_id, 'vat_condition_code' => $point->profile->vat_condition_code, 'address_line' => $point->profile->address_line, 'city' => $point->profile->city, 'province_code' => $point->profile->province_code, 'postal_code' => $point->profile->postal_code, 'country_code' => $point->profile->country_code, 'point_public_id' => $point->public_id, 'point_number' => $point->point_number, 'environment' => $point->environment->value, 'integration_mode' => $point->integration_mode->value];
        $recipient = ['name' => $sale->customer_name_snapshot, 'document' => $sale->customer_document_snapshot];
        return ['issuer_snapshot' => $issuer, 'recipient_snapshot' => $recipient, 'currency_code' => $sale->currency_code, 'service_subtotal_minor' => $sale->service_subtotal_minor, 'product_subtotal_minor' => $sale->product_subtotal_minor, 'total_minor' => $sale->total_minor, 'fingerprint' => hash('sha256', json_encode(['sale' => $sale->public_id, 'point' => $point->public_id, 'type' => $data->documentType->value, 'issuer' => $issuer, 'recipient' => $recipient, 'currency' => $sale->currency_code, 'service' => $sale->service_subtotal_minor, 'product' => $sale->product_subtotal_minor, 'total' => $sale->total_minor], JSON_THROW_ON_ERROR))];
    }
    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        if (! ($this->currentOrganization->roleFor($actor)?->canManageOrganization() ?? false)) { throw new DomainException('Sólo un administrador puede registrar documentos fiscales.'); }
        return $organizationId;
    }
}
