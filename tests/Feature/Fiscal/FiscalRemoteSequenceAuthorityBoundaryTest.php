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
use App\Domain\Fiscal\WsfeFecaeDetailData;
use App\Domain\Fiscal\WsfeFecaeHeaderData;
use App\Domain\Fiscal\WsfeFecaeRequestComposerContract;
use App\Domain\Fiscal\WsfeFecaeRequestData;
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

    public function test_remote_sequence_and_fecae_composer_are_explicit_contracts(): void
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

        $this->assertTrue(
            (new ReflectionClass(
                WsfeFecaeRequestComposerContract::class
            ))->isInterface()
        );
    }

    public function test_adapter_uses_remote_sequence_then_composes_and_transports_same_fecae_request(): void
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
        $composer = new RecordingFecaeComposer;

        $result = $this->adapter(
            $transport,
            $authority,
            $composer
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

        $this->assertSame(
            321,
            $composer->fiscalDocumentId
        );

        $this->assertSame(
            42,
            $composer->voucherNumber
        );

        $this->assertNotNull(
            $transport->request
        );

        $this->assertSame(
            91,
            $transport->request->organizationId
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

        $this->assertSame(
            $composer->request,
            $transport->request->fecaeRequest
        );

        $this->assertNotSame(
            98765432,
            $transport->request->voucherNumber
        );
    }

    public function test_transport_request_carries_composed_payload_but_no_local_or_secret_fields(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string =>
                $property->getName(),
            (new ReflectionClass(
                FiscalAuthorizationTransportRequest::class
            ))->getProperties()
        );

        foreach ([
            'organizationId',
            'fiscalDocumentId',
            'environment',
            'pointOfSaleNumber',
            'voucherTypeCode',
            'voucherNumber',
            'fecaeRequest',
        ] as $expected) {
            $this->assertContains(
                $expected,
                $properties
            );
        }

        foreach ([
            'fiscalPointOfSaleId',
            'assignedNumber',
            'token',
            'sign',
            'certificate',
            'privateKey',
            'endpoint',
        ] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $properties
            );
        }
    }

    public function test_first_remote_voucher_may_derive_number_one_and_compose_same_number(): void
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
        $composer = new RecordingFecaeComposer;

        $this->adapter(
            $transport,
            $authority,
            $composer
        )->request($document);

        $this->assertSame(
            1,
            $composer->voucherNumber
        );

        $this->assertSame(
            1,
            $transport->request?->voucherNumber
        );

        $this->assertSame(
            1,
            $transport->request?->fecaeRequest
                ->detail->voucherFrom
        );

        $this->assertSame(
            1,
            $transport->request?->fecaeRequest
                ->detail->voucherTo
        );
    }

    public function test_mismatched_remote_sequence_identity_fails_before_composition_and_transport(): void
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
        $composer = new RecordingFecaeComposer;

        try {
            $this->adapter(
                $transport,
                $authority,
                $composer
            )->request($document);

            $this->fail(
                'Una identidad remota distinta debió fallar cerrado.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $composer->request
        );

        $this->assertNull(
            $transport->request
        );
    }

    public function test_remote_sequence_overflow_fails_before_composition_and_transport(): void
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
        $composer = new RecordingFecaeComposer;

        try {
            $this->adapter(
                $transport,
                $authority,
                $composer
            )->request($document);

            $this->fail(
                'No debe derivarse un CbteNro fuera del máximo WSFE.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $composer->request
        );

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
        $composer = new RecordingFecaeComposer;

        try {
            $this->adapter(
                $transport,
                $authority,
                $composer
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
            $composer->request
        );

        $this->assertNull(
            $transport->request
        );
    }

    public function test_credentials_are_required_before_remote_sequence_query_or_composition(): void
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
        $composer = new RecordingFecaeComposer;

        $adapter = new ArcaFiscalAuthorizationAdapter(
            $transport,
            new FixedCredentialStore(false),
            $authority,
            $composer
        );

        try {
            $adapter->request($document);

            $this->fail(
                'Sin credenciales configuradas no debe consultarse la secuencia remota ni componerse FECAE.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(
            $authority->query
        );

        $this->assertNull(
            $composer->request
        );

        $this->assertNull(
            $transport->request
        );
    }

    public function test_composer_identity_mismatch_fails_before_authorization_transport(): void
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
        $composer = new RecordingFecaeComposer(
            pointNumberOverride: 13
        );

        try {
            $this->adapter(
                $transport,
                $authority,
                $composer
            )->request($document);

            $this->fail(
                'Un FeCAEReq para otra identidad debió fallar antes del transport.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull(
            $composer->request
        );

        $this->assertNull(
            $transport->request
        );
    }

    private function adapter(
        RecordingAuthorizationTransport $transport,
        RecordingSequenceAuthority $authority,
        ?RecordingFecaeComposer $composer = null
    ): ArcaFiscalAuthorizationAdapter {
        return new ArcaFiscalAuthorizationAdapter(
            $transport,
            new FixedCredentialStore(true),
            $authority,
            $composer ?? new RecordingFecaeComposer
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

final class RecordingFecaeComposer implements WsfeFecaeRequestComposerContract
{
    public ?int $fiscalDocumentId = null;
    public ?int $voucherNumber = null;
    public ?WsfeFecaeRequestData $request = null;

    public function __construct(
        private readonly ?int $pointNumberOverride = null,
        private readonly ?int $voucherTypeOverride = null,
        private readonly ?int $voucherNumberOverride = null,
    ) {
    }

    public function compose(
        FiscalDocument $document,
        int $voucherNumber
    ): WsfeFecaeRequestData {
        $this->fiscalDocumentId =
            (int) $document->id;

        $this->voucherNumber =
            $voucherNumber;

        $pointNumber =
            $this->pointNumberOverride
            ?? (int) $document->pointOfSale->point_number;

        $voucherType =
            $this->voucherTypeOverride
            ?? (int) $document->classification->voucher_code;

        $payloadVoucher =
            $this->voucherNumberOverride
            ?? $voucherNumber;

        $this->request = new WsfeFecaeRequestData(
            new WsfeFecaeHeaderData(
                1,
                $pointNumber,
                $voucherType
            ),
            new WsfeFecaeDetailData(
                conceptCode: 1,
                documentTypeCode: 80,
                documentNumber: '20123456786',
                voucherFrom: $payloadVoucher,
                voucherTo: $payloadVoucher,
                voucherDate: '20260818',
                totalAmount: '1.00',
                nonTaxedAmount: '0.00',
                netTaxableAmount: '1.00',
                exemptAmount: '0.00',
                tributesAmount: '0.00',
                vatAmount: '0.00',
                serviceFrom: null,
                serviceTo: null,
                paymentDueDate: null,
                currencyId: 'PES',
                currencyQuotation: '1.000000',
                sameCurrencySettlement: 'N',
                recipientVatConditionId: 1,
            )
        );

        return $this->request;
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
