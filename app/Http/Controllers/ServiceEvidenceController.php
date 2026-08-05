<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceEvidenceData;
use App\Domain\Service\ServiceEvidenceManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ServiceEvidenceContext;
use App\Http\Requests\StoreServiceEvidenceRequest;
use App\Models\ServiceEvidence;
use App\Models\ServiceOrder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceEvidenceController extends Controller
{
    public function create(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): View {
        $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );

        $serviceOrder->load([
            'asset',
            'intake',
            'diagnostics',
            'workItems',
            'partRequirements.product',
            'partRequirements.workItem',
            'custodyEvents',
            'qualityInspections',
            'delivery',
            'cancellationRequest.resolution.returnRecord',
            'warrantyClaimAsCorrective.resolution',
            'warrantyClaimAsCorrective.returnRecord',
        ]);

        $extensions = array_values(
            (array) config('service_evidence.allowed_mime_types', [])
        );

        return view('service-orders.evidence.create', [
            'order' => $serviceOrder,
            'targets' => $this->targets($serviceOrder),
            'idempotencyKey' => 'service-ui:evidence:'.Str::uuid(),
            'accept' => collect($extensions)
                ->map(fn (string $extension): string => '.'.$extension)
                ->implode(','),
            'maximumMegabytes' => max(
                1,
                (int) ceil(
                    ((int) config('service_evidence.max_bytes')) / 1048576
                )
            ),
        ]);
    }

    public function store(
        StoreServiceEvidenceRequest $request,
        ServiceOrder $serviceOrder,
        ServiceEvidenceManager $manager
    ): RedirectResponse {
        $validated = $request->validated();
        $uploadedFile = $request->file('evidence_file');
        $sourcePath = $uploadedFile?->getRealPath();

        if (! is_string($sourcePath) || $sourcePath === '') {
            return back()
                ->withInput()
                ->withErrors([
                    'service_evidence' => 'El archivo temporal no está disponible.',
                ]);
        }

        try {
            $evidence = $manager->upload(
                new ServiceEvidenceData(
                    serviceOrderId: $serviceOrder->id,
                    context: ServiceEvidenceContext::from(
                        $validated['context']
                    ),
                    sourcePath: $sourcePath,
                    originalFilename: $uploadedFile->getClientOriginalName(),
                    idempotencyKey: $validated['idempotency_key'],
                    referenceId: $validated['reference_id'] ?? null,
                    description: $validated['description'] ?? null,
                    capturedAt: filled($validated['captured_at'] ?? null)
                        ? CarbonImmutable::parse(
                            $validated['captured_at'],
                            config('app.timezone')
                        )
                        : null
                ),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'service_evidence' => $exception->getMessage(),
                ]);
        }

        return redirect(
            route('service-orders.show', $serviceOrder)
                .'#service-evidence'
        )->with(
            'success',
            "Evidencia privada «{$evidence->original_filename}» registrada."
        );
    }

    public function verify(
        Request $request,
        ServiceOrder $serviceOrder,
        string $evidencePublicId,
        CurrentOrganization $currentOrganization,
        ServiceEvidenceManager $manager
    ): RedirectResponse {
        $evidence = $this->resolveEvidence(
            $request,
            $serviceOrder,
            $evidencePublicId,
            $currentOrganization
        );

        try {
            $integrity = $manager->verify(
                $evidence,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'service_evidence' => $exception->getMessage(),
            ]);
        }

        if (! $integrity->valid()) {
            return back()->withErrors([
                'service_evidence' => 'La evidencia privada falta o no coincide con su huella registrada.',
            ]);
        }

        return back()->with(
            'success',
            "Integridad confirmada para «{$evidence->original_filename}»."
        );
    }

    public function download(
        Request $request,
        ServiceOrder $serviceOrder,
        string $evidencePublicId,
        CurrentOrganization $currentOrganization,
        ServiceEvidenceManager $manager
    ): StreamedResponse {
        $evidence = $this->resolveEvidence(
            $request,
            $serviceOrder,
            $evidencePublicId,
            $currentOrganization
        );

        try {
            $integrity = $manager->verify(
                $evidence,
                $request->user()
            );
        } catch (DomainException) {
            abort(404);
        }

        abort_unless(
            $integrity->valid(),
            409,
            'La evidencia privada no superó la verificación de integridad.'
        );

        return Storage::disk($evidence->disk)->download(
            $evidence->path,
            $evidence->original_filename,
            [
                'Content-Type' => $evidence->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]
        );
    }

    private function guardOrder(
        Request $request,
        ServiceOrder $serviceOrder,
        CurrentOrganization $currentOrganization
    ): int {
        $organizationId = $currentOrganization->id($request->user());

        abort_unless(
            (int) $serviceOrder->organization_id === $organizationId,
            404
        );

        return $organizationId;
    }

    private function resolveEvidence(
        Request $request,
        ServiceOrder $serviceOrder,
        string $evidencePublicId,
        CurrentOrganization $currentOrganization
    ): ServiceEvidence {
        $organizationId = $this->guardOrder(
            $request,
            $serviceOrder,
            $currentOrganization
        );

        return ServiceEvidence::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $serviceOrder->id)
            ->where('public_id', $evidencePublicId)
            ->firstOrFail();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function targets(ServiceOrder $order): array
    {
        $targets = [[
            'value' => ServiceEvidenceContext::Order->value,
            'label' => ServiceEvidenceContext::Order->label(),
        ]];

        if ($order->intake) {
            $targets[] = [
                'value' => ServiceEvidenceContext::Intake->value
                    .':'.$order->intake->id,
                'label' => ServiceEvidenceContext::Intake->label(),
            ];
        }

        foreach ($order->diagnostics as $diagnostic) {
            $targets[] = [
                'value' => ServiceEvidenceContext::Diagnostic->value
                    .':'.$diagnostic->id,
                'label' => 'Diagnóstico R'.$diagnostic->revision
                    .' · '.Str::limit($diagnostic->summary, 80),
            ];
        }

        foreach ($order->workItems as $workItem) {
            $targets[] = [
                'value' => ServiceEvidenceContext::WorkItem->value
                    .':'.$workItem->id,
                'label' => 'Trabajo '.$workItem->sequence
                    .' · '.Str::limit($workItem->title, 80),
            ];
        }

        foreach ($order->partRequirements as $requirement) {
            $product = $requirement->product?->name
                ?? 'Repuesto #'.$requirement->id;
            $targets[] = [
                'value' => ServiceEvidenceContext::PartRequirement->value
                    .':'.$requirement->id,
                'label' => 'Repuesto · '.Str::limit($product, 80),
            ];
        }

        foreach ($order->custodyEvents as $event) {
            $targets[] = [
                'value' => ServiceEvidenceContext::CustodyEvent->value
                    .':'.$event->id,
                'label' => 'Custodia #'.$event->id
                    .' · '.$event->event_type->label(),
            ];
        }

        foreach ($order->qualityInspections as $inspection) {
            $targets[] = [
                'value' => ServiceEvidenceContext::QualityInspection->value
                    .':'.$inspection->id,
                'label' => 'Control de calidad R'.$inspection->revision,
            ];
        }

        if ($order->delivery) {
            $targets[] = [
                'value' => ServiceEvidenceContext::Delivery->value
                    .':'.$order->delivery->id,
                'label' => ServiceEvidenceContext::Delivery->label(),
            ];
        }

        $cancellation = $order->cancellationRequest;

        if ($cancellation) {
            $targets[] = [
                'value' => ServiceEvidenceContext::CancellationRequest->value
                    .':'.$cancellation->id,
                'label' => ServiceEvidenceContext::CancellationRequest->label(),
            ];

            if ($cancellation->resolution) {
                $targets[] = [
                    'value' => ServiceEvidenceContext::CancellationResolution->value
                        .':'.$cancellation->resolution->id,
                    'label' => ServiceEvidenceContext::CancellationResolution->label(),
                ];

                if ($cancellation->resolution->returnRecord) {
                    $targets[] = [
                        'value' => ServiceEvidenceContext::CancellationReturn->value
                            .':'.$cancellation->resolution->returnRecord->id,
                        'label' => ServiceEvidenceContext::CancellationReturn->label(),
                    ];
                }
            }
        }

        $claim = $order->warrantyClaimAsCorrective;

        if ($claim) {
            $targets[] = [
                'value' => ServiceEvidenceContext::WarrantyClaim->value
                    .':'.$claim->id,
                'label' => ServiceEvidenceContext::WarrantyClaim->label(),
            ];

            if ($claim->resolution) {
                $targets[] = [
                    'value' => ServiceEvidenceContext::WarrantyResolution->value
                        .':'.$claim->resolution->id,
                    'label' => ServiceEvidenceContext::WarrantyResolution->label(),
                ];
            }

            if ($claim->returnRecord) {
                $targets[] = [
                    'value' => ServiceEvidenceContext::WarrantyReturn->value
                        .':'.$claim->returnRecord->id,
                    'label' => ServiceEvidenceContext::WarrantyReturn->label(),
                ];
            }
        }

        return $targets;
    }
}
