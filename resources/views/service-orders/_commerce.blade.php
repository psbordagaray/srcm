@if(
    $order->status === \App\Enums\ServiceOrderStatus::Delivered
    || $order->commerceSale
)
    <section class="sulu-card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-amber-300">Cierre comercial</p>
                <h2 class="mt-1 text-lg font-bold text-white">Venta y cobro</h2>
                <p class="mt-1 text-sm text-slate-500">Liquidación de la reparación entregada y artículos agregados.</p>
            </div>

            @if($order->commerceSale)
                @can('view-commerce-sales')
                    <a href="{{ route('commerce-sales.show', $order->commerceSale) }}" class="rounded-xl border border-amber-400/30 px-4 py-2.5 text-sm font-semibold text-amber-200 transition hover:border-amber-300 hover:text-amber-100">Ver venta #{{ $order->commerceSale->sale_number }}</a>
                @endcan
            @else
                @can('record-commerce-sales')
                    <a href="{{ route('commerce-sales.create', ['service_order' => $order->public_id]) }}" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Liquidar reparación</a>
                @endcan
            @endif
        </div>

        @if($order->commerceSale)
            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Operación</p>
                    <p class="mt-2 font-mono text-sm font-bold text-white">Venta #{{ $order->commerceSale->sale_number }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Total cobrado</p>
                    <p class="mt-2 font-mono text-sm font-bold text-amber-200">$ {{ number_format($order->commerceSale->total_minor / 100, 2, ',', '.') }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Confirmada</p>
                    <p class="mt-2 text-sm font-semibold text-slate-200">{{ $order->commerceSale->confirmed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                </div>
            </div>
        @else
            <div class="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-4">
                <p class="text-sm font-semibold text-amber-100">Reparación entregada pendiente de liquidación.</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">El cierre comercial debe incorporar el presupuesto aprobado completo y registrar pagos exactos.</p>
            </div>
        @endif
    </section>
@endif
