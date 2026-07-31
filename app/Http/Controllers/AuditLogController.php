<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\Identifier;
use App\Models\Manufacturer;
use App\Models\Organization;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\TechnicalModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const EVENT_LABELS = [
        'created' => 'Creación',
        'updated' => 'Edición',
        'activated' => 'Activación',
        'deactivated' => 'Inactivación',
        'organization_switched' => 'Cambio de organización',
    ];

    /**
     * @var array<class-string, string>
     */
    private const ENTITY_LABELS = [
        Brand::class => 'Marca',
        BusinessParty::class => 'Identidad comercial',
        CatalogProduct::class => 'Producto',
        Compatibility::class => 'Compatibilidad',
        Entity::class => 'Entidad de conocimiento',
        Identifier::class => 'Identificador',
        Manufacturer::class => 'Fabricante',
        Organization::class => 'Organización',
        ProductCategory::class => 'Categoría',
        Supplier::class => 'Proveedor',
        SupplierOffer::class => 'Oferta de proveedor',
        TechnicalModel::class => 'Modelo técnico',
    ];

    public function index(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id();
        $filters = $request->validate([
            'request_id' => ['nullable', 'string', 'max:36'],
            'event' => [
                'nullable',
                'string',
                'in:created,updated,activated,deactivated,organization_switched',
            ],
            'entity' => [
                'nullable',
                'string',
                'in:brand,business_party,catalog_product,compatibility,entity,identifier,manufacturer,organization,product_category,supplier,supplier_offer,technical_model',
            ],
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
        ]);

        $entityTypes = [
            'brand' => Brand::class,
            'business_party' => BusinessParty::class,
            'catalog_product' => CatalogProduct::class,
            'compatibility' => Compatibility::class,
            'entity' => Entity::class,
            'identifier' => Identifier::class,
            'manufacturer' => Manufacturer::class,
            'organization' => Organization::class,
            'product_category' => ProductCategory::class,
            'supplier' => Supplier::class,
            'supplier_offer' => SupplierOffer::class,
            'technical_model' => TechnicalModel::class,
        ];

        $displayTimezone = (string) config(
            'app.display_timezone',
            'America/Argentina/Buenos_Aires'
        );

        $dateFromUtc = filled(
            $filters['date_from'] ?? null
        )
            ? CarbonImmutable::parse(
                $filters['date_from'].' 00:00:00',
                $displayTimezone
            )->utc()
            : null;

        $dateToUtc = filled(
            $filters['date_to'] ?? null
        )
            ? CarbonImmutable::parse(
                $filters['date_to'].' 23:59:59.999999',
                $displayTimezone
            )->utc()
            : null;

        $auditLogs = AuditLog::query()
            ->where(function (Builder $query) use (
                $organizationId
            ): void {
                $query
                    ->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })
            ->when(
                filled($filters['request_id'] ?? null),
                fn (Builder $query) => $query->where(
                    'request_id',
                    'like',
                    '%'.$filters['request_id'].'%'
                )
            )
            ->when(
                filled($filters['event'] ?? null),
                fn (Builder $query) => $query->where(
                    'event',
                    $filters['event']
                )
            )
            ->when(
                filled($filters['entity'] ?? null),
                fn (Builder $query) => $query->where(
                    'auditable_type',
                    $entityTypes[$filters['entity']]
                )
            )
            ->when(
                filled($filters['user_id'] ?? null),
                fn (Builder $query) => $query->where(
                    'user_id',
                    $filters['user_id']
                )
            )
            ->when(
                $dateFromUtc !== null,
                fn (Builder $query) => $query->where(
                    'created_at',
                    '>=',
                    $dateFromUtc
                )
            )
            ->when(
                $dateToUtc !== null,
                fn (Builder $query) => $query->where(
                    'created_at',
                    '<=',
                    $dateToUtc
                )
            )
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $users = User::query()
            ->whereIn(
                'id',
                AuditLog::query()
                    ->where(function (Builder $query) use (
                        $organizationId
                    ): void {
                        $query
                            ->where(
                                'organization_id',
                                $organizationId
                            )
                            ->orWhereNull('organization_id');
                    })
                    ->whereNotNull('user_id')
                    ->select('user_id')
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        $auditedCompatibilityCount = AuditLog::query()
            ->where(function (Builder $query) use (
                $organizationId
            ): void {
                $query
                    ->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })
            ->where(
                'auditable_type',
                Compatibility::class
            )
            ->distinct()
            ->count('auditable_id');

        $legacyCompatibilityCount = max(
            0,
            Compatibility::query()->count()
                - $auditedCompatibilityCount
        );

        return view('audit-logs.index', [
            'auditLogs' => $auditLogs,
            'users' => $users,
            'filters' => $filters,
            'eventLabels' => self::EVENT_LABELS,
            'entityLabels' => self::ENTITY_LABELS,
            'roleLabels' => $this->roleLabels(),
            'displayTimezone' => $displayTimezone,
            'legacyCompatibilityCount' =>
                $legacyCompatibilityCount,
        ]);
    }

    public function show(
        AuditLog $auditLog,
        CurrentOrganization $currentOrganization
    ): View {
        abort_unless(
            $auditLog->organization_id === null
                || (int) $auditLog->organization_id
                    === $currentOrganization->id(),
            404
        );
        return view('audit-logs.show', [
            'auditLog' => $auditLog,
            'eventLabels' => self::EVENT_LABELS,
            'entityLabels' => self::ENTITY_LABELS,
            'roleLabels' => $this->roleLabels(),
            'displayTimezone' => (string) config(
                'app.display_timezone',
                'America/Argentina/Buenos_Aires'
            ),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function roleLabels(): array
    {
        $labels = [];

        foreach (UserRole::cases() as $role) {
            $labels[$role->value] = $role->label();
        }

        return $labels;
    }
}
