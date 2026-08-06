@php
    $latestInspection = $order->qualityInspections
        ->sortByDesc('revision')
        ->first();
@endphp

<section class="sulu-card p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-emerald-300">Cierre técnico</p>
            <h2 class="mt-1 text-lg font-bold text-white">Control de calidad y entrega</h2>
            <p class="mt-1 text-sm text-slate-500">Pruebas finales, custodia, conformidad y garantías atribuibles.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('inspect-service-quality')
                @if($order->status === \App\Enums\ServiceOrderStatus::QualityControl)
                    <a href="{{ route('service-orders.quality-inspections.create', $order) }}" class="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-emerald-300">Controlar calidad</a>
                @endif
            @endcan

            @can('deliver-service-orders')
                @if(
                    $order->status === \App\Enums\ServiceOrderStatus::ReadyForDelivery
                    && $latestInspection?->outcome === \App\Enums\ServiceQualityOutcome::Approved
                    && ! $order->delivery
                )
                    <a href="{{ route('service-orders.delivery.create', $order) }}" class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Registrar entrega</a>
                @endif
            @endcan
        </div>
    </div>

    @if($errors->has('completion'))
        <div role="alert" class="mt-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
            {{ $errors->first('completion') }}
        </div>
    @endif

    <div class="mt-5 space-y-4">
        @forelse($order->qualityInspections->sortByDesc('revision') as $inspection)
            @php
                $approved = $inspection->outcome
                    === \App\Enums\ServiceQualityOutcome::Approved;
            @endphp

            <article class="rounded-2xl border {{ $approved ? 'border-emerald-500/20 bg-emerald-500/5' : 'border-amber-500/20 bg-amber-500/5' }} p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-bold text-white">Control R{{ $inspection->revision }}</p>
                            <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase {{ $approved ? 'bg-emerald-400/10 text-emerald-200' : 'bg-amber-400/10 text-amber-200' }}">
                                {{ $inspection->outcome->label() }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $inspection->inspected_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                            · {{ $inspection->inspectedBy->name }}
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="font-mono text-sm font-bold text-white">
                            {{ $inspection->check_count - $inspection->failed_check_count }}/{{ $inspection->check_count }}
                        </p>
                        <p class="mt-1 text-[10px] uppercase tracking-wider text-slate-500">Pruebas aprobadas</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach($inspection->checks as $check)
                        <div class="rounded-xl border {{ $check['passed'] ? 'border-emerald-500/20 bg-slate-950/60' : 'border-red-500/20 bg-red-500/5' }} p-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-xs font-semibold text-slate-200">{{ $check['label'] }}</p>
                                <span class="text-[10px] font-bold uppercase {{ $check['passed'] ? 'text-emerald-300' : 'text-red-300' }}">
                                    {{ $check['passed'] ? 'Aprobada' : 'Fallida' }}
                                </span>
                            </div>
                            @if($check['notes'])
                                <p class="mt-2 text-[11px] leading-5 text-slate-500">{{ $check['notes'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Condición final</p>
                        <p class="mt-2 whitespace-pre-line text-xs text-slate-300">{{ $inspection->condition_notes }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Accesorios verificados</p>
                        <p class="mt-2 whitespace-pre-line text-xs text-slate-300">{{ $inspection->accessories_snapshot }}</p>
                    </div>
                </div>

                @if($inspection->rework_reason)
                    <p class="mt-4 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs text-amber-100">
                        <strong>Retrabajo requerido:</strong> {{ $inspection->rework_reason }}
                    </p>
                @endif

                @if($inspection->notes)
                    <p class="mt-3 text-xs text-slate-500">{{ $inspection->notes }}</p>
                @endif
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-700 bg-slate-950/30 p-6 text-center">
                <p class="text-sm font-semibold text-slate-300">Todavía no existe un control de calidad.</p>
                <p class="mt-1 text-xs text-slate-500">El control se habilita cuando todos los trabajos concluyen y el equipo vuelve a custodia de la organización.</p>
            </div>
        @endforelse
    </div>

    @if($order->delivery)
        <article class="mt-6 rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">Entrega confirmada</p>
                    <h3 class="mt-2 text-lg font-bold text-white">{{ $order->delivery->recipient_name }}</h3>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $order->delivery->delivered_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                        · entregó {{ $order->delivery->deliveredBy->name }}
                    </p>
                </div>

                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $order->delivery->customer_conformity ? 'bg-emerald-400/10 text-emerald-200' : 'bg-amber-400/10 text-amber-200' }}">
                    {{ $order->delivery->customer_conformity ? 'Con conformidad' : 'Sin conformidad' }}
                </span>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Condición entregada</p>
                    <p class="mt-2 whitespace-pre-line text-xs text-slate-300">{{ $order->delivery->condition_notes }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Accesorios entregados</p>
                    <p class="mt-2 whitespace-pre-line text-xs text-slate-300">{{ $order->delivery->accessories_snapshot }}</p>
                </div>
            </div>

            @if($order->delivery->recipient_document || $order->delivery->notes)
                <div class="mt-4 rounded-xl border border-slate-800 bg-slate-950/60 p-3 text-xs text-slate-400">
                    @if($order->delivery->recipient_document)
                        <p><strong>Documento:</strong> {{ $order->delivery->recipient_document }}</p>
                    @endif
                    @if($order->delivery->notes)
                        <p class="{{ $order->delivery->recipient_document ? 'mt-2' : '' }}">{{ $order->delivery->notes }}</p>
                    @endif
                </div>
            @endif

            <div class="mt-5 border-t border-cyan-500/10 pt-4">
                <h4 class="text-sm font-bold text-white">Garantías generadas</h4>
                <div class="mt-3 space-y-2">
                    @forelse($order->delivery->warranties as $warranty)
                        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold text-emerald-100">{{ $warranty->workReport->workItem->title }}</p>
                                    <p class="mt-1 text-[11px] text-slate-500">{{ $warranty->coverage_terms }}</p>
                                </div>
                                <p class="font-mono text-xs text-emerald-200">{{ $warranty->warranty_days }} días</p>
                            </div>
                            <p class="mt-2 text-[10px] uppercase tracking-wider text-slate-600">
                                Vigente hasta {{ $warranty->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                            </p>
                        </div>
                    @empty
                        <p class="text-xs text-slate-500">Los trabajos completados no declararon garantía.</p>
                    @endforelse
                </div>
            </div>
        </article>
    @endif
</section>
