<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Compras · Expediente</p><h1 class="mt-2 text-2xl font-bold text-white">Orden {{ strtoupper(substr($order->public_id, 0, 8)) }}</h1><p class="mt-2 break-all font-mono text-xs text-slate-500">{{ $order->public_id }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($order->status === \App\Enums\PurchaseOrderStatus::Draft)
                    @can('draft-purchase-orders')<a href="{{ route('purchase-orders.edit', $order) }}" class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200">Editar borrador</a>@endcan
                    @can('issue-purchase-orders')<form method="POST" action="{{ route('purchase-orders.issue', $order) }}">@csrf<button class="rounded-xl bg-amber-400 px-4 py-2 text-sm font-bold text-slate-950">Emitir orden</button></form>@endcan
                @endif
                @if($order->status->acceptsReceipts())
                    @can('receive-purchases')<a href="{{ route('purchase-orders.receipts.create', $order) }}" class="rounded-xl bg-emerald-400 px-4 py-2 text-sm font-bold text-slate-950">Registrar recepción</a>@endcan
                @endif
            </div>
        </div>

        @if(session('success'))<div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>@endif
        @if(session('error'))<div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>@endif
        @if($errors->any())<div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Proveedor</p><p class="mt-3 text-lg font-bold text-white">{{ $order->supplier->party->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->supplier->party->tax_id ?: 'Sin identificación fiscal' }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Estado</p><p class="mt-3 text-lg font-bold text-amber-200">{{ $order->status->label() }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?: 'Aún no emitida' }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Mercadería</p><p class="mt-3 font-mono text-xl font-bold text-white">{{ $order->currency_code }} {{ number_format($order->merchandise_subtotal_minor / 100, 2, ',', '.') }}</p><p class="mt-1 text-xs text-slate-500">Logística esperada: {{ number_format($order->expected_logistics_cost_minor / 100, 2, ',', '.') }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total esperado</p><p class="mt-3 font-mono text-xl font-bold text-amber-200">{{ $order->currency_code }} {{ number_format($order->expected_total_minor / 100, 2, ',', '.') }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->receipts->count() }} recepción{{ $order->receipts->count() === 1 ? '' : 'es' }}</p></article>
        </div>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4"><h2 class="font-bold text-white">Líneas y remanentes</h2><p class="mt-1 text-xs text-slate-500">Las cantidades recibidas provienen exclusivamente de recepciones confirmadas.</p></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-800"><thead class="bg-slate-950/70"><tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500"><th class="px-5 py-4">Producto</th><th class="px-5 py-4">Pedido</th><th class="px-5 py-4">Recibido</th><th class="px-5 py-4">Pendiente</th><th class="px-5 py-4 text-right">Costo / subtotal</th></tr></thead><tbody class="divide-y divide-slate-800/80">
                @foreach($order->lines as $line)
                    <tr><td class="px-5 py-4"><p class="text-sm font-semibold text-white">{{ $line->product->sku }} · {{ $line->description }}</p><p class="mt-1 text-xs text-slate-500">{{ $line->supplier_code ?: 'Sin código proveedor' }}{{ $line->supplierOffer ? ' · oferta #'.$line->supplierOffer->id : '' }}</p></td><td class="px-5 py-4 font-mono text-sm text-slate-200">{{ $line->ordered_quantity }} {{ $line->base_unit_code }}</td><td class="px-5 py-4 font-mono text-sm text-emerald-200">{{ $lineBalances[$line->id]['received'] }}</td><td class="px-5 py-4 font-mono text-sm text-amber-200">{{ $lineBalances[$line->id]['remaining'] }}</td><td class="px-5 py-4 text-right"><p class="font-mono text-sm text-slate-200">{{ $order->currency_code }} {{ number_format($line->unit_cost_minor / 100, 2, ',', '.') }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ number_format($line->subtotal_minor / 100, 2, ',', '.') }}</p></td></tr>
                @endforeach
            </tbody></table></div>
        </section>

        <section class="space-y-4">
            <div><h2 class="text-lg font-bold text-white">Recepciones confirmadas</h2><p class="mt-1 text-sm text-slate-500">Cada recepción conserva documento, costo real, ubicación, condición y evidencia de Inventario.</p></div>
            @forelse($order->receipts as $receipt)
                <article class="sulu-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="font-mono text-sm font-bold text-emerald-200">{{ strtoupper(substr($receipt->public_id, 0, 8)) }}</p><p class="mt-1 text-sm text-white">{{ $receipt->document_reference ?: 'Sin referencia documental' }}</p><p class="mt-1 text-xs text-slate-500">{{ $receipt->received_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $receipt->receivedBy->name }}</p></div><div class="text-right"><p class="font-mono text-sm font-bold text-white">{{ $order->currency_code }} {{ number_format($receipt->actual_total_minor / 100, 2, ',', '.') }}</p><p class="mt-1 text-xs text-slate-500">Movimiento Inventario</p><a href="{{ route('inventory-movements.index', ['search' => $receipt->inventoryMovement->public_id]) }}" class="mt-1 block break-all font-mono text-[11px] text-cyan-300 hover:text-cyan-200">{{ $receipt->inventoryMovement->public_id }}</a></div></div>
                    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-800"><table class="min-w-full divide-y divide-slate-800"><tbody class="divide-y divide-slate-800/80">@foreach($receipt->lines as $line)<tr><td class="px-4 py-3 text-sm text-slate-200">{{ $line->product->sku }} · {{ $line->product->name }}</td><td class="px-4 py-3 font-mono text-sm text-emerald-200">{{ $line->received_quantity }}</td><td class="px-4 py-3 text-sm text-slate-300">{{ $line->condition->label() }}</td><td class="px-4 py-3 text-sm text-slate-300">{{ $line->inventoryLocation->name }}</td><td class="px-4 py-3 text-right font-mono text-sm text-slate-200">{{ $order->currency_code }} {{ number_format($line->actual_unit_cost_minor / 100, 2, ',', '.') }}</td></tr>@endforeach</tbody></table></div>
                </article>
            @empty
                <div class="sulu-card px-5 py-10 text-center text-sm text-slate-500">Todavía no hay mercadería recibida para esta orden.</div>
            @endforelse
        </section>

        @if($order->status === \App\Enums\PurchaseOrderStatus::Issued)
            @can('cancel-purchase-orders')
                <section class="sulu-card border-red-500/20 p-5"><h2 class="font-bold text-red-100">Cancelar orden no recibida</h2><p class="mt-1 text-sm text-slate-500">La cancelación exige motivo y deja trazabilidad. Después de una recepción ya no está permitida.</p><form method="POST" action="{{ route('purchase-orders.cancel', $order) }}" class="mt-4 flex flex-col gap-3 md:flex-row">@csrf<input name="reason" required maxlength="1000" value="{{ old('reason') }}" placeholder="Motivo de cancelación" class="flex-1 rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-red-400 focus:ring-red-400"><button class="rounded-xl border border-red-400/30 px-4 py-2.5 text-sm font-semibold text-red-200">Cancelar orden</button></form></section>
            @endcan
        @endif

        @if($order->notes)<section class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Notas</p><p class="mt-3 whitespace-pre-line text-sm text-slate-300">{{ $order->notes }}</p></section>@endif
    </div>
</x-app-layout>
