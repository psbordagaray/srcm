@php
    $currentOrganization = app(
        \App\Domain\Tenancy\CurrentOrganization::class
    )->getOrNull();

    $navigation = [
        [
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'active' => 'dashboard',
            'icon' => 'dashboard',
        ],
        [
            'label' => 'Conocimiento técnico',
            'route' => 'knowledge.explorer',
            'active' => 'knowledge.*',
            'icon' => 'link',
        ],
        [
            'label' => 'Categorías',
            'route' => 'product-categories.index',
            'active' => 'product-categories.*',
            'icon' => 'categories',
        ],
        [
            'label' => 'Marcas',
            'route' => 'brands.index',
            'active' => 'brands.*',
            'icon' => 'tag',
        ],
        [
            'label' => 'Modelos técnicos',
            'route' => 'technical-models.index',
            'active' => 'technical-models.*',
            'icon' => 'tv',
        ],
        [
            'label' => 'Fabricantes',
            'route' => 'manufacturers.index',
            'active' => 'manufacturers.*',
            'icon' => 'factory',
        ],
        [
            'label' => 'Productos',
            'route' => 'products.index',
            'active' => 'products.*',
            'icon' => 'box',
        ],        [
            'label' => 'Importar productos',
            'route' => 'product-imports.create',
            'active' => 'product-imports.*',
            'icon' => 'box',
            'disabled' => ! request()->user()?->can('manage-catalog'),
            'status' => 'Sin permiso',
        ],
        [
            'label' => 'Compatibilidades',
            'disabled' => true,
            'status' => 'Desde fichas',
            'icon' => 'link',
        ],
    ];

    $operations = [
        [
            'label' => 'Organización',
            'route' => 'organization.show',
            'active' => 'organization.*',
            'icon' => 'users',
            'disabled' => $currentOrganization === null,
        ],        [
            'label' => 'Usuarios y permisos',
            'route' => 'organization-members.index',
            'active' => 'organization-members.*',
            'icon' => 'users',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can(
                    'view-organization-members'
                ),
        ],
        [
            'label' => 'Reparaciones',
            'route' => 'service-orders.index',
            'active' => 'service-orders.*',
            'icon' => 'repair',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-service-orders'),
        ],
        [
            'label' => 'Movimientos',
            'route' => 'inventory-movements.index',
            'active' => 'inventory-movements.*',
            'icon' => 'receipt',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-inventory'),
        ],
        [
            'label' => 'Disponibilidad',
            'route' => 'inventory-availability.index',
            'active' => 'inventory-availability.*',
            'icon' => 'inventory',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can(
                    'view-inventory-availability'
                ),
        ],
        [
            'label' => 'Overrides',
            'route' => 'inventory-negative-authorizations.index',
            'active' => 'inventory-negative-authorizations.*',
            'icon' => 'receipt',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can(
                    'view-inventory-negative-authorizations'
                ),
        ],
        [
            'label' => 'Ubicaciones',
            'route' => 'inventory-locations.index',
            'active' => 'inventory-locations.*',
            'icon' => 'inventory',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-inventory'),
        ],
        [
            'label' => 'Stock negativo',
            'route' => 'inventory-negative-incidents.index',
            'active' => 'inventory-negative-incidents.*',
            'icon' => 'receipt',
            'status' => 'Solo admin',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can(
                    'view-inventory-negative-incidents'
                ),
        ],
        [
            'label' => 'Personas',
            'route' => 'business-parties.index',
            'active' => 'business-parties.*',
            'icon' => 'users',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-business-parties'),
        ],
        [
            'label' => 'Clientes',
            'route' => 'customers.index',
            'active' => 'customers.*',
            'icon' => 'users',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-customers'),
        ],
        [
            'label' => 'Proveedores',
            'route' => 'suppliers.index',
            'active' => 'suppliers.*',
            'icon' => 'truck',
        ],
        [
            'label' => 'Ofertas',
            'route' => 'supplier-offers.index',
            'active' => 'supplier-offers.*',
            'icon' => 'truck',
        ],
        [
            'label' => 'Compras',
            'route' => 'purchase-orders.index',
            'active' => 'purchase-orders.*',
            'icon' => 'cart',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-purchases'),
        ],
        [
            'label' => 'Ventas',
            'route' => 'commerce-sales.index',
            'active' => 'commerce-sales.*',
            'icon' => 'receipt',
            'disabled' => $currentOrganization === null
                || ! request()->user()?->can('view-commerce-sales'),
        ],
    ];
@endphp

<aside
    class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-white/10 bg-sulu-900 transition-transform duration-300 lg:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
>
    <div class="flex h-20 items-center justify-between border-b border-white/10 px-6">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
            <span class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-amber-300 to-amber-500 text-sulu-950 shadow-lg shadow-amber-500/10">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path stroke-linecap="round" d="m8 2 4 3 4-3M9 16h6" />
                </svg>
            </span>
            <span>
                <span class="block text-lg font-bold tracking-[0.2em] text-white">SULU TV</span>
                <span class="block max-w-40 truncate text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-400">
                    {{ $currentOrganization?->name ?? 'Sin organización' }}
                </span>
            </span>
        </a>

        <button type="button" class="sulu-icon-button lg:hidden" @click="sidebarOpen = false" aria-label="Cerrar menú">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
            </svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-4 py-6">
        <p class="px-3 text-[10px] font-bold uppercase tracking-[0.24em] text-slate-600">Catálogo</p>

        <nav class="mt-3 space-y-1">
            @foreach ($navigation as $item)
                @if ($item['disabled'] ?? false)
                    <span
                        class="sulu-nav-item cursor-not-allowed opacity-40"
                        title="{{ $item['status'] ?? 'Módulo pendiente de desarrollo' }}"
                        aria-disabled="true"
                    >
                        @include('components.sidebar-icon', ['name' => $item['icon']])
                        <span>{{ $item['label'] }}</span>
                        <span class="ml-auto text-[9px] font-bold uppercase tracking-wider text-slate-600">
                            {{ $item['status'] ?? 'Próximamente' }}
                        </span>
                    </span>
                @else
                    @php
                        $isActive = request()->routeIs($item['active']);
                    @endphp

                    <a
                        href="{{ route($item['route']) }}"
                        class="sulu-nav-item {{ $isActive ? 'sulu-nav-item-active' : '' }}"
                    >
                        @include('components.sidebar-icon', ['name' => $item['icon']])
                        <span>{{ $item['label'] }}</span>

                        @if ($isActive)
                            <span class="ml-auto h-1.5 w-1.5 rounded-full bg-cyan-300 shadow-[0_0_8px_rgba(103,232,249,.9)]"></span>
                        @endif
                    </a>
                @endif
            @endforeach
        </nav>

        <p class="mt-8 px-3 text-[10px] font-bold uppercase tracking-[0.24em] text-slate-600">Operaciones</p>

        <nav class="mt-3 space-y-1">
            @foreach ($operations as $item)
                @if ($item['disabled'] ?? false)
                    <span
                        class="sulu-nav-item cursor-not-allowed opacity-40"
                        title="{{ $item['status'] ?? 'Módulo pendiente de desarrollo' }}"
                        aria-disabled="true"
                    >
                        @include('components.sidebar-icon', ['name' => $item['icon']])
                        <span>{{ $item['label'] }}</span>
                        <span class="ml-auto text-[9px] font-bold uppercase tracking-wider text-slate-600">
                            {{ $item['status'] ?? 'Próximamente' }}
                        </span>
                    </span>
                @else
                    @php
                        $isActive = request()->routeIs($item['active']);
                    @endphp

                    <a
                        href="{{ route($item['route']) }}"
                        class="sulu-nav-item {{ $isActive ? 'sulu-nav-item-active' : '' }}"
                    >
                        @include('components.sidebar-icon', ['name' => $item['icon']])
                        <span>{{ $item['label'] }}</span>

                        @if ($isActive)
                            <span class="ml-auto h-1.5 w-1.5 rounded-full bg-cyan-300 shadow-[0_0_8px_rgba(103,232,249,.9)]"></span>
                        @endif
                    </a>
                @endif
            @endforeach
        </nav>

        @can('view-audit')
            <p class="mt-8 px-3 text-[10px] font-bold uppercase tracking-[0.24em] text-slate-600">Sistema</p>

            <nav class="mt-3 space-y-1">
                <a
                    href="{{ route('audit-logs.index') }}"
                    class="sulu-nav-item {{ request()->routeIs('audit-logs.*') ? 'sulu-nav-item-active' : '' }}"
                >
                    @include('components.sidebar-icon', ['name' => 'receipt'])
                    <span>Auditoría</span>

                    @if (request()->routeIs('audit-logs.*'))
                        <span class="ml-auto h-1.5 w-1.5 rounded-full bg-cyan-300 shadow-[0_0_8px_rgba(103,232,249,.9)]"></span>
                    @endif
                </a>
            </nav>
        @endcan
    </div>

    <div class="border-t border-white/10 p-4">
        <div class="rounded-2xl border border-amber-400/10 bg-gradient-to-br from-amber-400/10 to-transparent p-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-amber-400/10 text-amber-300">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4 7v5c0 5 3.5 8 8 9 4.5-1 8-4 8-9V7l-8-4Z" />
                    </svg>
                </span>

                <div class="min-w-0">
                    <p class="text-sm font-semibold text-white">SRCM v0.1</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Base de gestión para repuestos y controles remotos.</p>
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <a href="{{ route('profile.edit') }}" class="sulu-nav-item flex-1">
                @include('components.sidebar-icon', ['name' => 'settings'])
                <span>Mi perfil</span>
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button type="submit" class="sulu-icon-button" aria-label="Cerrar sesión">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5M15 12H3M15 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>
