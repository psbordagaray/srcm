<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Operaciones confirmadas</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Ventas y cobros</h1>
                <p class="mt-2 text-sm text-slate-400">Servicios aprobados, productos, pagos y salidas de inventario en una única evidencia.</p>
            </div>

            @can('record-commerce-sales')
                <a href="{{ route('commerce-sales.create') }}" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Nueva venta</a>
            @endcan
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Ventas confirmadas</p>
                <p class="mt-3 text-3xl font-bold text-white">{{ $summary['confirmed'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total registrado</p>
                <p class="mt-3 font-mono text-2xl font-bold text-amber-200">$ {{ number_format($summary['total_minor'] / 100, 2, ',', '.') }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Reparaciones por liquidar</p>
                <p class="mt-3 text-3xl font-bold {{ $summary['unsettled_services'] ? 'text-amber-300' : 'text-emerald-300' }}">{{ $summary['unsettled_services'] }}</p>
            </article>
        </div>

        <section class="sulu-card p-5">
            <form method="GET" action="{{ route('commerce-sales.index') }}" class="flex flex-col gap-3 md:flex-row">
                <div class="flex-1">
                    <label for="search" class="sr-only">Buscar ventas</label>
                    <input id="search" name="search" type="search" value="{{ $search }}" placeholder="Venta, cliente, documento, reparación o equipo" class="w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                </div>
                <button type="submit" class="rounded-xl border border-amber-400/30 px-5 py-2.5 text-sm font-semibold text-amber-200 transition hover:border-amber-300">Buscar</button>
                @if($search !== '')
                    <a href="{{ route('commerce-sales.index') }}" class="rounded-xl border border-slate-700 px-5 py-2.5 text-center text-sm font-semibold text-slate-400 transition hover:border-slate-500 hover:text-white">Limpiar</a>
                @endif
            </form>
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/70">
                        <tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="px-5 py-4">Venta</th>
                            <th class="px-5 py-4">Cliente</th>
                            <th class="px-5 py-4">Origen</th>
                            <th class="px-5 py-4">Pagos</th>
                            <th class="px-5 py-4 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @forelse($sales as $sale)
                            <tr class="transition hover:bg-slate-800/30">
                                <td class="px-5 py-4">
                                    <a href="{{ route('commerce-sales.show', $sale) }}" class="font-mono text-sm font-bold text-amber-200 hover:text-amber-100">#{{ $sale->sale_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $sale->sold_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="text-sm font-semibold text-slate-200">{{ $sale->customer_name_snapshot }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $sale->customer_document_snapshot ?: 'Sin documento' }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    @if($sale->serviceOrder)
                                        <a href="{{ route('service-orders.show', $sale->serviceOrder) }}" class="text-sm font-semibold text-cyan-200 hover:text-cyan-100">Reparación #{{ $sale->serviceOrder->order_number }}</a>
                                        <p class="mt-1 text-xs text-slate-500">{{ $sale->serviceOrder->asset->brand_name }} {{ $sale->serviceOrder->asset->model_name }}</p>
                                    @else
                                        <p class="text-sm font-semibold text-slate-300">Venta minorista</p>
                                        <p class="mt-1 text-xs text-slate-500">Sin reparación vinculada</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <p class="text-sm text-slate-300">{{ $sale->payments->count() }} medio{{ $sale->payments->count() === 1 ? '' : 's' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $sale->payments->pluck('method')->map->label()->join(' · ') }}</p>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <p class="font-mono text-sm font-bold text-white">$ {{ number_format($sale->total_minor / 100, 2, ',', '.') }}</p>
                                    <p class="mt-1 text-[10px] uppercase tracking-wider text-emerald-300">{{ $sale->status->label() }}</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <p class="text-sm font-semibold text-slate-300">No hay ventas para mostrar.</p>
                                    <p class="mt-1 text-xs text-slate-500">Las operaciones confirmadas aparecerán aquí.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($sales->hasPages())
                <div class="border-t border-slate-800 px-5 py-4">{{ $sales->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
