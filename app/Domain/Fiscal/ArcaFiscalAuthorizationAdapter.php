<?php
namespace App\Domain\Fiscal;
use App\Models\FiscalDocument;
use DomainException;
final class ArcaFiscalAuthorizationAdapter {
 public function __construct(private readonly FiscalAuthorizationTransport $transport,private readonly FiscalAuthorizationCredentialStore $credentials){}
 public function request(FiscalDocument $document): FiscalAuthorizationTransportResult {
  $number=$document->numberAssignment;if(!$number){throw new DomainException('El documento requiere numeración fiscal antes de solicitar autorización.');}
  if(!$this->credentials->configuredFor($document->organization_id)){throw new DomainException('La integración fiscal externa no está configurada.');}
  return $this->transport->authorize(new FiscalAuthorizationTransportRequest($document->id,$number->fiscal_point_of_sale_id,$number->number));
 }}
