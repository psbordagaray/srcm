<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Compras · Órdenes y recepciones</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Compras generales</h1>
                <p class="mt-2 text-sm text-slate-400">Compromiso comercial, entregas parciales, costos documentados y vínculo atómico con Inventario.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('supplier-payables.aging') }}" class="rounded-xl border border-amber-400/30 px-5 py-2.5 text-sm font-semibold text-amber-200">CxP / aging</a>
                <a href="{{ route('purchase-payment-operations.index') }}" class="rounded-xl border border-cyan-400/30 px-5 py-2.5 text-sm font-semibold text-cyan-200">Pagos a proveedores</a>
                @can('draft-purchase-orders')
                    <a href="{{ route('purchase-orders.create') }}" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Nueva orden</a>
                @endcan
            </div>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Borradores</p><p class="mt-3 text-3xl font-bold text-white">{{ $summary['draft'] }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Abiertas</p><p class="mt-3 text-3xl font-bold text-amber-300">{{ $summary['open'] }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Recibidas</p><p class="mt-3 text-3xl font-bold text-emerald-300">{{ $summary['received'] }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Canceladas</p><p class="mt-3 text-3xl font-bold text-slate-400">{{ $summary['cancelled'] }}</p></article>
        </div>

        <section class="sulu-card p-5">
            <form method="GET" action="{{ route('purchase-orders.index') }}" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_14rem_18rem_auto]">
                <input name="search" type="search" value="{{ $search }}" placeholder="UUID, proveedor, CUIT o documento" class="w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                <select name="status" class="rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    <option value="">Todos los estados</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option->value }}" @selected($status === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
                <select name="supplier" class="rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    <option value="">Todos los proveedores</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) $supplierId === (string) $supplier->id)>{{ $supplier->party->name }}</option>
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-xl border border-amber-400/30 px-4 py-2.5 text-sm font-semibold text-amber-200">Filtrar</button>
                    @if($search !== '' || $status !== '' || $supplierId !== null)
                        <a href="{{ route('purchase-orders.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-400">Limpiar</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/70">
                        <tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="px-5 py-4">Orden</th>
                            <th class="px-5 py-4">Proveedor</th>
                            <th class="px-5 py-4">Estado</th>
                            <th class="px-5 py-4">Recepciones</th>
                            <th class="px-5 py-4 text-right">Total esperado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @forelse($orders as $order)
                            <tr class="transition hover:bg-slate-800/30">
                                <td class="px-5 py-4">
                                    <a href="{{ route('purchase-orders.show', $order) }}" class="font-mono text-sm font-bold text-amber-200 hover:text-amber-100">{{ strtoupper(substr($order->public_id, 0, 8)) }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $order->created_at->format('d/m/Y H:i') }}</p>
                                </td>
                                <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-200">{{ $order->supplier->party->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->supplier->party->tax_id ?: 'Sin identificación fiscal' }}</p></td>
                                <td class="px-5 py-4"><span class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-xs font-semibold text-slate-200">{{ $order->status->label() }}</span></td>
                                <td class="px-5 py-4"><p class="text-sm text-slate-300">{{ $order->receipts->count() }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->receipts->last()?->document_reference ?: 'Sin documento' }}</p></td>
                                <td class="px-5 py-4 text-right"><p class="font-mono text-sm font-bold text-white">{{ $order->currency_code }} {{ number_format($order->expected_total_minor / 100, 2, ',', '.') }}</p></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center"><p class="text-sm font-semibold text-slate-300">No hay órdenes de compra.</p><p class="mt-1 text-xs text-slate-500">Los borradores y órdenes emitidas aparecerán aquí.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($orders->hasPages())<div class="border-t border-slate-800 px-5 py-4">{{ $orders->links() }}</div>@endif
        </section>
    </div>
</x-app-layout>
