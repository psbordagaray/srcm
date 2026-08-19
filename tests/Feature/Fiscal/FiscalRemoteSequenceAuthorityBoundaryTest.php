<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\ArcaFiscalAuthorizationAdapter;
use App\Domain\Fiscal\FiscalAuthorizationCredentialStore;
use App\Domain\Fiscal\FiscalAuthorizationTransport;
use App\Domain\Fiscal\FiscalAuthorizationTransportRequest;
use App\Domain\Fiscal\FiscalAuthorizationTransportResult;
use App\Domain\Fiscal\FiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\FiscalRemoteSequenceQuery;
use App\Domain\Fiscal\FiscalRemoteSequenceState;
use App\Enums\FiscalAuthorizationOutcome;
use App\Enums\FiscalEnvironment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentClassification;
use App\Models\FiscalDocumentNumber;
use App\Models\FiscalPointOfSale;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class FiscalRemoteSequenceAuthorityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_sequence_is_an_explicit_read_only_contract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(
                FiscalRemoteSequenceAuthority::class
            ))->isInterface()
        );

        $method = new \ReflectionMethod(
            FiscalRemoteSequenceAuthority::class,
            'lastAuthorized'
        );

        $this->assertSame(
            FiscalRemoteSequenceState::class,
            (string) $method->getReturnType()
        );
    }

    public function test_adapter_uses_external_point_voucher_type_and_remote_last_authorized_only(): void
    {
        $document = $this->document(
            pointNumber: 12,
            voucherCode: '6',
            localNumber: 98765432
        );

        $authority = new RecordingSequenceAuthority(
            new FiscalRemoteSequenceState(
                FiscalEnvironment::Homologation,
                12,
                6,
                41
            )
        );

        $transport = new RecordingAuthorizationTransport;

        $result = $this->adapter(
            $transport,
            $authority
        )->request($document);

        $this->assertSame(
            FiscalAuthorizationOutcome::Unknown,
            $result->outcome
        );

        $this->assertNotNull(
            $authority->query
        );

        $this->assertSame(
            91,
            $authority->query->organizationId
        );

        $this->assertSame(
            FiscalEnvironment::Homologation,
            $authority->query->environment
        );

        $this->assertSame(
            12,
            $authority->query->pointOfSaleNumber
        );

        $this->assertSame(
            6,
            $authority->query->voucherTypeCode
        );

        $this->assertNotNull(
            $transport->request
        );

        $this->assertSame(
            321,
            $transport->request->fiscalDocumentId
        );

        $this->assertSame(
            FiscalEnvironment::Homologation,
            $transport->request->environment
        );

        $this->assertSame(
            12,
            $transport->request->pointOfSaleNumber
        );

        $this->assertSame(
            6,
            $transport->request->voucherTypeCode
        );

        $this->assertSame(
            42,
            $transport->request->voucherNumber
        );

        $this->assertNotSame(
            98765432,
            $transport->request->voucherNumber
        );
    }

    public function test_transport_request_carries_no_internal_point_id_or_local_assigned_number(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string =>
                $property->getName(),
            (new ReflectionClass(
                FiscalAuthorizationTransportRequest::class
            ))->getProperties()
        );

        $this->assertContains(
            'pointOfSaleNumber',
            $properties
        );

        $this->assertContains(
            'voucherTypeCode',
            $properties
        );

        $this->assertContains(
            'voucherNumber',
            $properties
        );

        $this->assertNotContains(
            'fiscalPointOfSaleId',
            $properties
        );

        $this->assertNotContains(
            'assignedNumber',
            $properties
        );
    }

    public function test_first_remote_voucher_may_derive_number_one_from_remote_zero(): void
    {
        $document = $this->document(
            pointNumber: 3,
            voucherCode: '1',
            localNumber: 900
        );

        $authority = new RecordingSequenceAuthority(
            new FiscalRemoteSequenceState(
                FiscalEnvironment::Homologation,
                3,
                1,
                0
            )
        );

        $transport = new RecordingAuthorizationTransport;

        $this->adapter(
            $transport,
            $authority
        )->request($document);

        $this->assertSame(
            1,
            $transport->request?->voucherNumber
        );
    }

    public function test_mismatched_remote_sequence_identity_fails_closed(): void
    {
        $document = $this->document(
            pointNumber: 12,
            voucherCode: '6'
        );

        $authority = new RecordingSequenceAuthority(
            new FiscalRemoteSequenceState(
                FiscalEnvironment::Homologation,
                13,
                6,
                41
            )
        );

        $transport = new RecordingAuthorizationTransport;

        try {
            $this->adapter(
                $transport,
                $authority
            )->request($document);

            $this->fail(
                'Una identidad remota distinta debió fallar cerrado.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $transport->request
        );
    }

    public function test_remote_sequence_overflow_fails_before_authorization_transport(): void
    {
        $document = $this->document(
            pointNumber: 12,
            voucherCode: '6'
        );

        $authority = new RecordingSequenceAuthority(
            new FiscalRemoteSequenceState(
                FiscalEnvironment::Homologation,
                12,
                6,
                99999999
            )
        );

        $transport = new RecordingAuthorizationTransport;

        try {
            $this->adapter(
                $transport,
                $authority
            )->request($document);

            $this->fail(
                'No debe derivarse un CbteNro fuera del máximo WSFE.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $transport->request
        );
    }

    public function test_missing_or_invalid_wsfe_identity_fails_before_remote_sequence_query(): void
    {
        $document = $this->document(
            pointNumber: 12,
            voucherCode: 'ABC'
        );

        $authority = new RecordingSequenceAuthority(
            new FiscalRemoteSequenceState(
                FiscalEnvironment::Homologation,
                12,
                1,
                0
            )
        );

        $transport = new RecordingAuthorizationTransport;

        try {
            $this->adapter(
                $transport,
                $authority
            )->request($document);

            $this->fail(
                'CbteTipo no numérico debió fallar cerrado.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $authority->query
        );

        $this->assertNull(
            $transport->request
        );
    }

    public function test_credentials_are_required_before_remote_sequence_query(): void
    {
        $document = $this->document(
            pointNumber: 12,
            voucherCode: '6'
        );

        $authority = new RecordingSequenceAuthority(
            new FiscalRemoteSequenceState(
                FiscalEnvironment::Homologation,
                12,
                6,
                41
            )
        );

        $transport = new RecordingAuthorizationTransport;

        $adapter = new ArcaFiscalAuthorizationAdapter(
            $transport,
            new FixedCredentialStore(false),
            $authority
        );

        try {
            $adapter->request($document);

            $this->fail(
                'Sin credenciales configuradas no debe consultarse la secuencia remota.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $authority->query
        );

        $this->assertNull(
            $transport->request
        );
    }

    private function adapter(
        RecordingAuthorizationTransport $transport,
        RecordingSequenceAuthority $authority
    ): ArcaFiscalAuthorizationAdapter {
        return new ArcaFiscalAuthorizationAdapter(
            $transport,
            new FixedCredentialStore(true),
            $authority
        );
    }

    private function document(
        int $pointNumber,
        ?string $voucherCode,
        int $localNumber = 700
    ): FiscalDocument {
        $document = new FiscalDocument;
        $document->id = 321;
        $document->organization_id = 91;

        $point = new FiscalPointOfSale;
        $point->id = 44;
        $point->organization_id = 91;
        $point->environment =
            FiscalEnvironment::Homologation;
        $point->point_number = $pointNumber;

        $classification =
            new FiscalDocumentClassification;
        $classification->voucher_code =
            $voucherCode;

        $number = new FiscalDocumentNumber;
        $number->number = $localNumber;
        $number->fiscal_point_of_sale_id = 44;
        $number->environment =
            FiscalEnvironment::Homologation->value;

        $document->setRelation(
            'pointOfSale',
            $point
        );

        $document->setRelation(
            'classification',
            $classification
        );

        $document->setRelation(
            'numberAssignment',
            $number
        );

        return $document;
    }
}

final class RecordingSequenceAuthority implements FiscalRemoteSequenceAuthority
{
    public ?FiscalRemoteSequenceQuery $query = null;

    public function __construct(
        private readonly FiscalRemoteSequenceState $state
    ) {
    }

    public function lastAuthorized(
        FiscalRemoteSequenceQuery $query
    ): FiscalRemoteSequenceState {
        $this->query = $query;

        return $this->state;
    }
}

final class RecordingAuthorizationTransport implements FiscalAuthorizationTransport
{
    public ?FiscalAuthorizationTransportRequest $request = null;

    public function authorize(
        FiscalAuthorizationTransportRequest $request
    ): FiscalAuthorizationTransportResult {
        $this->request = $request;

        return new FiscalAuthorizationTransportResult(
            FiscalAuthorizationOutcome::Unknown,
            'BOUNDARY_TEST'
        );
    }
}

final class FixedCredentialStore implements FiscalAuthorizationCredentialStore
{
    public function __construct(
        private readonly bool $configured
    ) {
    }

    public function configuredFor(
        int $organizationId
    ): bool {
        return $this->configured
            && $organizationId > 0;
    }
}
