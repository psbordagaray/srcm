<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryNegativeOverrideIssuer;
use App\Domain\Inventory\InventoryNegativeRequestManager;
use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryBaseUnit;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Enums\UserRole;
use App\Http\Requests\ConfirmInventoryMovementWithOverrideRequest;
use App\Http\Requests\ExplainInventoryNegativeAuthorizationRequest;
use App\Http\Requests\StoreInventoryNegativeRequest;
use App\Models\CatalogProduct;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use App\Models\InventoryNegativeRequestLine;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InventoryNegativeAuthorizationController extends Controller
{
    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $user = $request->user();
        $organizationId = $currentOrganization->id($user);
        $role = $currentOrganization->roleFor($user);
        $canOverride = $role?->canOverrideInventoryNegative() ?? false;
        $search = Str::of((string) $request->query('search'))
            ->squish()->limit(100, '')->toString();
        $status = InventoryNegativeRequestStatus::tryFrom(
            (string) $request->query('status')
        )?->value ?? '';

        $query = $this->visibleQuery(
            $organizationId,
            (int) $user->id,
            $canOverride
        )->with([
            'movement:id,public_id,type,status,created_by_user_id,effective_at,reason',
            'requestedBy:id,name',
            'approvedBy:id,name',
            'rejectedBy:id,name',
            'lines.product:id,sku,name,base_unit_code,quantity_scale',
            'lines.location:id,name',
            'override',
        ]);

        if ($status !== '') {
            $query->where('status', $status);
        }

        $this->applySearch($query, $search);

        $authorizations = $query
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $authorizations->setCollection(
            $authorizations->getCollection()->map(
                fn (InventoryNegativeRequest $authorization): array =>
                    $this->authorizationForDisplay(
                        $authorization,
                        (int) $user->id,
                        $role,
                        $canOverride
                    )
            )
        );

        $summary = $this->summary(
            $organizationId,
            (int) $user->id,
            $canOverride
        );
        $statuses = InventoryNegativeRequestStatus::cases();

        return view('inventory-negative-authorizations.index', compact(
            'authorizations',
            'summary',
            'statuses',
            'search',
            'status',
            'canOverride'
        ));
    }

    public function store(
        StoreInventoryNegativeRequest $request,
        InventoryMovement $inventoryMovement,
        InventoryNegativeRequestManager $manager
    ): RedirectResponse {
        try {
            $authorization = $manager->request(
                $inventoryMovement,
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('inventory-negative-authorizations.index')
            ->with(
                'success',
                'Solicitud #'.$this->shortId($authorization->public_id)
                    .' registrada para revisión administrativa.'
            );
    }

    public function approve(
        Request $request,
        InventoryNegativeRequest $inventoryNegativeRequest,
        InventoryNegativeOverrideIssuer $issuer
    ): RedirectResponse {
        try {
            $issuance = $issuer->issue(
                $inventoryNegativeRequest,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($issuance->invalidated) {
            return back()->with(
                'error',
                $issuance->request->invalidation_reason
                    ?? 'La solicitud perdió vigencia y fue invalidada.'
            );
        }

        return back()->with(
            'success',
            'Override emitido. Solo el solicitante autorizado puede consumirlo.'
        );
    }

    public function reject(
        ExplainInventoryNegativeAuthorizationRequest $request,
        InventoryNegativeRequest $inventoryNegativeRequest,
        InventoryNegativeOverrideIssuer $issuer
    ): RedirectResponse {
        try {
            $issuer->reject(
                $inventoryNegativeRequest,
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Solicitud rechazada con atribución.');
    }

    public function revoke(
        ExplainInventoryNegativeAuthorizationRequest $request,
        InventoryNegativeOverride $inventoryNegativeOverride,
        InventoryNegativeOverrideIssuer $issuer
    ): RedirectResponse {
        try {
            $issuer->revoke(
                $inventoryNegativeOverride,
                (string) $request->validated('reason'),
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Override revocado con atribución.');
    }

    public function confirm(
        ConfirmInventoryMovementWithOverrideRequest $request,
        InventoryMovement $inventoryMovement,
        InventoryNegativeOverride $inventoryNegativeOverride,
        InventoryMovementConfirmer $confirmer
    ): RedirectResponse {
        try {
            $result = $confirmer->confirmWithNegativeOverride(
                $inventoryMovement,
                $inventoryNegativeOverride,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result->invalidated) {
            return back()->with(
                'error',
                $result->override->invalidation_reason
                    ?? $result->request->invalidation_reason
                    ?? 'La autorización perdió vigencia.'
            );
        }

        return back()->with(
            'success',
            'Override consumido: movimiento confirmado e incidencia registrada.'
        );
    }

    private function visibleQuery(
        int $organizationId,
        int $userId,
        bool $canOverride
    ): Builder {
        return InventoryNegativeRequest::query()
            ->forOrganization($organizationId)
            ->when(
                ! $canOverride,
                fn (Builder $query): Builder => $query->where(
                    'requested_by_user_id',
                    $userId
                )
            );
    }

    /**
     * @return array{pending: int, approved: int, fulfilled: int, closed: int}
     */
    private function summary(
        int $organizationId,
        int $userId,
        bool $canOverride
    ): array {
        $query = $this->visibleQuery(
            $organizationId,
            $userId,
            $canOverride
        );

        return [
            'pending' => (clone $query)->where(
                'status',
                InventoryNegativeRequestStatus::Pending->value
            )->count(),
            'approved' => (clone $query)->where(
                'status',
                InventoryNegativeRequestStatus::Approved->value
            )->count(),
            'fulfilled' => (clone $query)->where(
                'status',
                InventoryNegativeRequestStatus::Fulfilled->value
            )->count(),
            'closed' => (clone $query)->whereIn('status', [
                InventoryNegativeRequestStatus::Rejected->value,
                InventoryNegativeRequestStatus::Invalidated->value,
            ])->count(),
        ];
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $identity = CatalogProduct::normalizeIdentity($search);
        $publicId = preg_replace('/[^a-zA-Z0-9-]/', '', $search) ?? '';

        $query->where(function (Builder $searchQuery) use (
            $search,
            $identity,
            $publicId
        ): void {
            $searchQuery->where('reason', 'like', '%'.$search.'%');

            if ($publicId !== '') {
                $searchQuery
                    ->orWhere('public_id', 'like', '%'.$publicId.'%')
                    ->orWhereHas(
                        'movement',
                        fn (Builder $movementQuery): Builder =>
                            $movementQuery->where(
                                'public_id',
                                'like',
                                '%'.$publicId.'%'
                            )
                    );
            }

            if ($identity !== '') {
                $searchQuery->orWhereHas(
                    'lines.product',
                    fn (Builder $productQuery): Builder => $productQuery
                        ->where(
                            'normalized_sku',
                            'like',
                            '%'.$identity.'%'
                        )->orWhere(
                            'normalized_name',
                            'like',
                            '%'.$identity.'%'
                        )
                );
            }
        });
    }

    /** @return array<string, mixed> */
    private function authorizationForDisplay(
        InventoryNegativeRequest $authorization,
        int $userId,
        ?UserRole $role,
        bool $canOverride
    ): array {
        $override = $authorization->override;
        $movement = $authorization->movement;
        $isPending = $authorization->status
            === InventoryNegativeRequestStatus::Pending;
        $isActive = $override?->status
            === InventoryNegativeOverrideStatus::Active;

        return [
            'authorization' => $authorization,
            'shortId' => $this->shortId($authorization->public_id),
            'movementShortId' => $this->shortId(
                $movement?->public_id ?? ''
            ),
            'statusClass' => $this->statusClass($authorization->status),
            'canApprove' => $canOverride && $isPending,
            'canReject' => $canOverride && $isPending,
            'canRevoke' => $canOverride && $isActive,
            'canConsume' => $isActive
                && $movement?->status === InventoryMovementStatus::Draft
                && (int) $override?->authorized_user_id === $userId
                && ($role?->canConfirmInventoryMovement(
                    $movement->type
                ) ?? false),
            'lines' => $authorization->lines->map(
                fn (InventoryNegativeRequestLine $line): array => [
                    'product' => $line->product?->name
                        ?? 'Producto ausente',
                    'sku' => $line->product?->sku ?? 'Sin SKU',
                    'location' => $line->location?->name
                        ?? 'Ubicación ausente',
                    'condition' => $line->condition->label(),
                    'current' => $this->quantity($line->current_quantity),
                    'requested' => $this->quantity(
                        $line->requested_quantity
                    ),
                    'projected' => $this->quantity(
                        $line->projected_quantity
                    ),
                    'deficit' => $this->quantity(
                        $line->incremental_deficit
                    ),
                    'unit' => InventoryBaseUnit::tryFrom(
                        (string) $line->base_unit_code
                    )?->label() ?? Str::upper(
                        (string) $line->base_unit_code
                    ),
                    'createsNegative' => $line->creates_negative,
                ]
            ),
        ];
    }

    private function statusClass(
        InventoryNegativeRequestStatus $status
    ): string {
        return match ($status) {
            InventoryNegativeRequestStatus::Pending =>
                'border-amber-500/30 bg-amber-500/10 text-amber-200',
            InventoryNegativeRequestStatus::Approved =>
                'border-cyan-500/30 bg-cyan-500/10 text-cyan-200',
            InventoryNegativeRequestStatus::Fulfilled =>
                'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
            InventoryNegativeRequestStatus::Rejected,
            InventoryNegativeRequestStatus::Invalidated =>
                'border-slate-500/30 bg-slate-500/10 text-slate-300',
        };
    }

    private function shortId(string $publicId): string
    {
        return Str::upper(Str::substr($publicId, 0, 8));
    }

    private function quantity(string $quantity): string
    {
        $quantity = InventoryQuantity::signed($quantity);
        $display = rtrim(rtrim($quantity, '0'), '.');

        return $display === '' || $display === '-0' ? '0' : $display;
    }
}
