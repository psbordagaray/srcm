<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-400">Panel operativo</p>
                <h1 class="mt-2 text-2xl font-bold text-white sm:text-3xl">Dashboard</h1>
                <p class="mt-2 text-sm text-slate-500">
                    Estado real de {{ app(\App\Domain\Tenancy\CurrentOrganization::class)->getOrNull()?->name }}.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('create-service-orders')
                    <a href="{{ route('service-orders.create') }}" class="sulu-button-secondary">Nueva reparación</a>
                @endcan
                @can('record-commerce-sales')
                    <a href="{{ route('commerce-sales.create') }}" class="sulu-button-secondary">Nueva venta</a>
                @endcan
                @can('draft-purchase-orders')
                    <a href="{{ route('purchase-orders.create') }}" class="sulu-button-primary">Nueva compra</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @php
        $formatMoney = static function (int $minor, string $currency): string {
            $negative = $minor < 0;
            $absolute = abs($minor);
            $whole = intdiv($absolute, 100);
            $cents = $absolute % 100;

            return ($negative ? '-' : '')
                .$currency.' '
                .number_format($whole, 0, ',', '.')
                .','
                .str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        };
    @endphp

    <div class="space-y-6">
        @can('override-inventory-negative')
            @if(($summary['pending_negative_requests'] ?? 0) > 0)
                <a href="{{ route('inventory-negative-authorizations.index', ['status' => 'pending']) }}" class="flex items-center justify-between gap-4 rounded-2xl border border-amber-400/30 bg-amber-400/10 px-5 py-4 text-amber-50 shadow-lg shadow-amber-950/10 transition hover:bg-amber-400/15">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Autorización requerida</p>
                        <p class="mt-1 font-semibold">{{ $summary['pending_negative_requests'] }} Override{{ $summary['pending_negative_requests'] === 1 ? '' : 's' }} pendiente{{ $summary['pending_negative_requests'] === 1 ? '' : 's' }} de revisión</p>
                    </div>
                    <span class="rounded-lg border border-amber-300/30 px-3 py-2 text-xs font-bold">Revisar</span>
                </a>
            @endif
        @endcan

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <a
                href="{{ route('service-orders.index') }}"
                class="sulu-card p-5 transition hover:-translate-y-0.5"
                aria-label="Reparaciones abiertas: {{ $summary['service_open'] }}"
            >
                <p class="text-sm font-medium text-slate-500">Reparaciones abiertas</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $summary['service_open'] }}</p>
                <p class="mt-4 text-xs text-slate-500">
                    {{ $summary['ready_for_delivery'] }} listas para entregar
                </p>
            </a>

            <a
                href="{{ route('purchase-orders.index') }}"
                class="sulu-card p-5 transition hover:-translate-y-0.5"
                aria-label="Compras por recibir: {{ $summary['purchase_pending'] }}"
            >
                <p class="text-sm font-medium text-slate-500">Compras por recibir</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $summary['purchase_pending'] }}</p>
                <p class="mt-4 text-xs text-slate-500">
                    {{ $summary['purchase_drafts'] }} borradores
                </p>
            </a>

            <a
                href="{{ route('commerce-sales.index') }}"
                class="sulu-card p-5 transition hover:-translate-y-0.5"
                aria-label="Ventas confirmadas hoy: {{ $summary['sales_today_count'] }}"
            >
                <p class="text-sm font-medium text-slate-500">Ventas confirmadas hoy</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $summary['sales_today_count'] }}</p>
                <div class="mt-4 space-y-1 text-xs text-slate-500">
                    @forelse ($salesTotals as $total)
                        <p>{{ $formatMoney($total['total_minor'], $total['currency_code']) }}</p>
                    @empty
                        <p>Sin ventas confirmadas hoy</p>
                    @endforelse
                </div>
            </a>

            <a
                href="{{ route('inventory-availability.index') }}"
                class="sulu-card p-5 transition hover:-translate-y-0.5"
                aria-label="Posiciones con disponibilidad: {{ $summary['available_positions'] }}"
            >
                <p class="text-sm font-medium text-slate-500">Posiciones con disponibilidad</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $summary['available_positions'] }}</p>
                <p class="mt-4 text-xs text-slate-500">
                    {{ $summary['products_with_stock'] }} productos ·
                    {{ $summary['deficit_positions'] }} déficits
                </p>
            </a>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <a href="{{ route('business-parties.index') }}" class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Identidades</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ $summary['identities'] }}</p>
            </a>
            <a href="{{ route('customers.index') }}" class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Clientes activos</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ $summary['active_customers'] }}</p>
            </a>
            <a href="{{ route('suppliers.index') }}" class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Proveedores activos</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ $summary['active_suppliers'] }}</p>
            </a>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <article class="sulu-card overflow-hidden">
                <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-white">Reparaciones recientes</h2>
                            <p class="mt-1 text-sm text-slate-500">Últimos ingresos de la organización activa.</p>
                        </div>
                        <a href="{{ route('service-orders.index') }}" class="text-xs font-semibold text-cyan-300">Ver todas</a>
                    </div>
                </div>
                <div class="divide-y divide-white/5">
                    @forelse ($recentServiceOrders as $order)
                        <a href="{{ route('service-orders.show', $order) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-white/[0.02] sm:px-6">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">
                                    Orden #{{ $order->order_number }}
                                </p>
                                <p class="mt-1 truncate text-xs text-slate-500">
                                    {{ $order->customer?->name ?? 'Sin cliente identificado' }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold text-slate-300">{{ $order->status->label() }}</p>
                                <p class="mt-1 text-[11px] text-slate-600">{{ $order->received_at?->format('d/m H:i') }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-slate-500">No hay reparaciones registradas.</p>
                    @endforelse
                </div>
            </article>

            <article class="sulu-card overflow-hidden">
                <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-white">Compras recientes</h2>
                            <p class="mt-1 text-sm text-slate-500">Órdenes, recepción y estado comercial.</p>
                        </div>
                        <a href="{{ route('purchase-orders.index') }}" class="text-xs font-semibold text-cyan-300">Ver todas</a>
                    </div>
                </div>
                <div class="divide-y divide-white/5">
                    @forelse ($recentPurchases as $order)
                        <a href="{{ route('purchase-orders.show', $order) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-white/[0.02] sm:px-6">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">
                                    {{ $order->supplier?->party?->name ?? 'Proveedor no disponible' }}
                                </p>
                                <p class="mt-1 truncate text-xs text-slate-500">
                                    {{ str($order->public_id)->limit(18) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold text-slate-300">{{ $order->status->label() }}</p>
                                <p class="mt-1 text-[11px] text-slate-600">{{ $order->created_at?->format('d/m H:i') }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-slate-500">No hay órdenes de compra registradas.</p>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
            <article class="sulu-card overflow-hidden">
                <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-white">Ventas recientes</h2>
                            <p class="mt-1 text-sm text-slate-500">Operaciones confirmadas, sin mezclar monedas.</p>
                        </div>
                        <a href="{{ route('commerce-sales.index') }}" class="text-xs font-semibold text-cyan-300">Ver todas</a>
                    </div>
                </div>
                <div class="divide-y divide-white/5">
                    @forelse ($recentSales as $sale)
                        <a href="{{ route('commerce-sales.show', $sale) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-white/[0.02] sm:px-6">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">
                                    Venta #{{ $sale->sale_number }}
                                </p>
                                <p class="mt-1 truncate text-xs text-slate-500">
                                    {{ $sale->customer?->name ?? $sale->customer_name_snapshot ?? 'Consumidor sin identificar' }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-white">
                                    {{ $formatMoney($sale->total_minor, $sale->currency_code) }}
                                </p>
                                <p class="mt-1 text-[11px] text-slate-600">{{ $sale->sold_at?->format('d/m H:i') }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-slate-500">No hay ventas confirmadas.</p>
                    @endforelse
                </div>
            </article>

            <article class="sulu-card p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-bold text-white">Distribución de inventario</h2>
                        <p class="mt-1 text-sm text-slate-500">Posiciones con disponibilidad por ubicación.</p>
                    </div>
                    <a href="{{ route('inventory-availability.index') }}" class="text-xs font-semibold text-cyan-300">Disponibilidad</a>
                </div>

                <div class="mt-6 space-y-4">
                    @forelse ($inventoryDistribution as $row)
                        <div class="rounded-xl border border-white/5 bg-white/[0.02] p-4">
                            <div class="flex items-center justify-between gap-4">
                                <p class="text-sm font-semibold text-white">{{ $row['name'] }}</p>
                                <p class="text-sm font-bold text-cyan-300">{{ $row['positions'] }}</p>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $row['products'] }} productos con disponibilidad
                            </p>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-white/10 p-6 text-center text-sm text-slate-500">
                            Todavía no hay posiciones con disponibilidad.
                        </p>
                    @endforelse
                </div>
            </article>
        </section>

        <article class="sulu-card overflow-hidden">
            <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                <h2 class="font-bold text-white">Actividad auditada reciente</h2>
                <p class="mt-1 text-sm text-slate-500">Últimos eventos registrados para esta organización.</p>
            </div>
            <div class="divide-y divide-white/5">
                @forelse ($recentAudit as $event)
                    <div class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-white">
                                {{ class_basename($event->auditable_type) }}
                            </p>
                            <p class="mt-1 truncate text-xs text-slate-500">{{ $event->event }}</p>
                        </div>
                        <p class="shrink-0 text-[11px] text-slate-600">{{ $event->created_at?->format('d/m H:i') }}</p>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No hay actividad auditada reciente.</p>
                @endforelse
            </div>
        </article>
    </div>
</x-app-layout>
