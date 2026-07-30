<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\Identifier;
use App\Models\ProductCategory;
use App\Models\TechnicalModel;
use App\Models\User;
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
    ];

    /**
     * @var array<class-string, string>
     */
    private const ENTITY_LABELS = [
        Brand::class => 'Marca',
        Compatibility::class => 'Compatibilidad',
        Entity::class => 'Entidad de conocimiento',
        Identifier::class => 'Identificador',
        ProductCategory::class => 'Categoría',
        TechnicalModel::class => 'Modelo técnico',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'request_id' => ['nullable', 'string', 'max:36'],
            'event' => [
                'nullable',
                'string',
                'in:created,updated,activated,deactivated',
            ],
            'entity' => [
                'nullable',
                'string',
                'in:brand,compatibility,entity,identifier,product_category,technical_model',
            ],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
        ]);

        $entityTypes = [
            'brand' => Brand::class,
            'compatibility' => Compatibility::class,
            'entity' => Entity::class,
            'identifier' => Identifier::class,
            'product_category' => ProductCategory::class,
            'technical_model' => TechnicalModel::class,
        ];

        $auditLogs = AuditLog::query()
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
                filled($filters['date_from'] ?? null),
                fn (Builder $query) => $query->whereDate(
                    'created_at',
                    '>=',
                    $filters['date_from']
                )
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn (Builder $query) => $query->whereDate(
                    'created_at',
                    '<=',
                    $filters['date_to']
                )
            )
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $users = User::query()
            ->whereIn(
                'id',
                AuditLog::query()
                    ->whereNotNull('user_id')
                    ->select('user_id')
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('audit-logs.index', [
            'auditLogs' => $auditLogs,
            'users' => $users,
            'filters' => $filters,
            'eventLabels' => self::EVENT_LABELS,
            'entityLabels' => self::ENTITY_LABELS,
            'roleLabels' => $this->roleLabels(),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        return view('audit-logs.show', [
            'auditLog' => $auditLog,
            'eventLabels' => self::EVENT_LABELS,
            'entityLabels' => self::ENTITY_LABELS,
            'roleLabels' => $this->roleLabels(),
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
