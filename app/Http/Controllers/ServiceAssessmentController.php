<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceAssessmentManager;
use App\Domain\Service\ServiceDiagnosticData;
use App\Domain\Service\ServiceDiagnosticFindingData;
use App\Domain\Service\ServiceQuoteData;
use App\Domain\Service\ServiceQuoteDecisionData;
use App\Domain\Service\ServiceQuoteLineData;
use App\Domain\Service\ServiceQuoteOptionData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceFindingSeverity;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceQuoteLineType;
use App\Http\Requests\StoreServiceDiagnosticRequest;
use App\Http\Requests\StoreServiceQuoteDecisionRequest;
use App\Http\Requests\StoreServiceQuoteRequest;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ServiceAssessmentController extends Controller
{
    public function createDiagnostic(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);

        abort_unless(in_array($serviceOrder->status, [
            ServiceOrderStatus::Received,
            ServiceOrderStatus::Diagnosing,
        ], true), 409);

        $serviceOrder->load(['asset.identifiers', 'intake', 'diagnostics']);

        return view('service-orders.diagnostics.create', [
            'order' => $serviceOrder,
            'severities' => ServiceFindingSeverity::cases(),
            'idempotencyKey' => 'service-ui:diagnostic:'.Str::uuid(),
        ]);
    }

    public function storeDiagnostic(
        StoreServiceDiagnosticRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServiceAssessmentManager $manager
    ): RedirectResponse {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);
        $validated = $request->validated();

        try {
            $diagnostic = $manager->recordDiagnostic(
                new ServiceDiagnosticData(
                    serviceOrderId: $serviceOrder->id,
                    summary: $validated['summary'],
                    recommendation: $validated['recommendation'],
                    findings: collect($validated['findings'])
                        ->map(fn (array $finding):
                            ServiceDiagnosticFindingData =>
                                new ServiceDiagnosticFindingData(
                                    severity: ServiceFindingSeverity::from(
                                        $finding['severity']
                                    ),
                                    category: $finding['category'],
                                    description: $finding['description'],
                                    evidenceNotes:
                                        $finding['evidence_notes'] ?? null
                                )
                        )->values()->all(),
                    idempotencyKey: $validated['idempotency_key'],
                    dataRiskNotes: $validated['data_risk_notes'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'assessment' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                "Diagnóstico revisión {$diagnostic->revision} registrado."
            );
    }

    public function createQuote(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);
        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::Diagnosing,
            409
        );

        $serviceOrder->load([
            'asset',
            'intake',
            'diagnostics.findings',
            'quotes',
        ]);
        abort_if($serviceOrder->diagnostics->isEmpty(), 409);

        return view('service-orders.quotes.create', [
            'order' => $serviceOrder,
            'diagnostic' => $serviceOrder->diagnostics->last(),
            'lineTypes' => ServiceQuoteLineType::cases(),
            'idempotencyKey' => 'service-ui:quote:'.Str::uuid(),
        ]);
    }

    public function storeQuote(
        StoreServiceQuoteRequest $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization,
        ServiceAssessmentManager $manager
    ): RedirectResponse {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);
        $validated = $request->validated();

        try {
            $quote = $manager->issueQuote(
                new ServiceQuoteData(
                    serviceOrderId: $serviceOrder->id,
                    options: collect($validated['options'])
                        ->map(fn (array $option): ServiceQuoteOptionData =>
                            new ServiceQuoteOptionData(
                                label: $option['label'],
                                lines: collect($option['lines'])
                                    ->map(fn (array $line):
                                        ServiceQuoteLineData =>
                                            new ServiceQuoteLineData(
                                                type:
                                                    ServiceQuoteLineType::from(
                                                        $line['type']
                                                    ),
                                                description:
                                                    $line['description'],
                                                quantity: $line['quantity'],
                                                unitPriceMinor:
                                                    $this->minorAmount(
                                                        $line['unit_price']
                                                    )
                                            )
                                    )->values()->all(),
                                description: $option['description'] ?? null,
                                recommended: (bool) $option['recommended']
                            )
                        )->values()->all(),
                    idempotencyKey: $validated['idempotency_key'],
                    currencyCode: $validated['currency_code'],
                    validUntil: filled($validated['valid_until'] ?? null)
                        ? CarbonImmutable::parse(
                            $validated['valid_until'],
                            config('app.timezone')
                        )->endOfDay()
                        : null,
                    terms: $validated['terms'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'assessment' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                "Presupuesto revisión {$quote->revision} emitido."
            );
    }

    public function createDecision(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceQuote $serviceQuote,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardQuote(
            $request,
            $serviceOrder,
            $serviceQuote,
            $currentOrganization
        );
        abort_unless(
            $serviceOrder->status === ServiceOrderStatus::AwaitingApproval,
            409
        );

        $serviceQuote->load(['options.lines', 'decision']);
        abort_if($serviceQuote->decision !== null, 409);

        return view('service-orders.quotes.decision', [
            'order' => $serviceOrder->load(['asset', 'intake']),
            'quote' => $serviceQuote,
            'decisionTypes' => ServiceQuoteDecisionType::cases(),
            'idempotencyKey' => 'service-ui:decision:'.Str::uuid(),
        ]);
    }

    public function storeDecision(
        StoreServiceQuoteDecisionRequest $request,
        ServiceOrder $serviceOrder,
        ServiceQuote $serviceQuote,
        CurrentOrganization $currentOrganization,
        ServiceAssessmentManager $manager
    ): RedirectResponse {
        $this->guardQuote(
            $request,
            $serviceOrder,
            $serviceQuote,
            $currentOrganization
        );
        $validated = $request->validated();

        try {
            $decision = $manager->recordDecision(
                new ServiceQuoteDecisionData(
                    serviceQuoteId: $serviceQuote->id,
                    decision: ServiceQuoteDecisionType::from(
                        $validated['decision']
                    ),
                    customerName: $validated['customer_name'],
                    channel: $validated['channel'],
                    idempotencyKey: $validated['idempotency_key'],
                    serviceQuoteOptionId:
                        $validated['service_quote_option_id'] ?? null,
                    customerReference:
                        $validated['customer_reference'] ?? null,
                    reason: $validated['reason'] ?? null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors([
                'assessment' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('service-orders.show', $serviceOrder)
            ->with(
                'success',
                'Decisión del cliente registrada: '
                    .$decision->decision->label().'.'
            );
    }

    private function guardOrder(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): void {
        abort_unless(
            (int) $serviceOrder->organization_id
                === $currentOrganization->id($request->user()),
            404
        );
    }

    private function guardQuote(
        Request $request,
        ServiceOrder $serviceOrder,
        ServiceQuote $serviceQuote,
        CurrentOrganization $currentOrganization
    ): void {
        $this->guardOrder($request, $serviceOrder, $currentOrganization);

        abort_unless(
            (int) $serviceQuote->organization_id
                === (int) $serviceOrder->organization_id
                && (int) $serviceQuote->service_order_id
                    === (int) $serviceOrder->id,
            404
        );

        $latestQuoteId = $serviceOrder->quotes()
            ->latest('revision')
            ->value('id');
        abort_unless((int) $latestQuoteId === (int) $serviceQuote->id, 409);
    }

    private function minorAmount(string $value): int
    {
        try {
            return (int) (string) BigDecimal::of($value)
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::Unnecessary)
                ->toBigInteger();
        } catch (Throwable $exception) {
            throw new DomainException(
                'El precio contiene una fracción menor a un centavo.',
                previous: $exception
            );
        }
    }
}
