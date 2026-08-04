@php
    $cancellation = $order->cancellationRequest;
    $resolution = $cancellation?->resolution;
    $returnRecord = $resolution?->returnRecord;
    $externalPending = $order->workItems->filter(
        fn ($work) => $work->execution_mode === \App\Enums\ServiceWorkExecutionMode::External
            && $work->status === \App\Enums\ServiceWorkStatus::WithProvider
    );
@endphp

@if($cancellation || $order->canRequestCancellation())
    <section id="cancellation" class="rounded-2xl border border-rose-500/20 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-rose-300">Cancelación posterior a aprobación</p>
                <h2 class="mt-1 text-lg font-bold text-white">Centro de cancelación y devolución</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">Solicitud, exposición, retorno de terceros, resolución administrativa y devolución física en una sola secuencia trazable.</p>
            </div>

            @if(! $cancellation)
                @can('request-service-cancellation')
                    @if($order->canRequestCancellation())
                        <a href="{{ route('service-orders.cancellation.request.create', $order) }}" class="inline-flex shrink-0 items-center justify-center rounded-xl bg-rose-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-rose-300">Solicitar cancelación</a>
                    @endif
                @endcan
            @elseif($order->status === \App\Enums\ServiceOrderStatus::CancellationPending && ! $resolution)
                @can('resolve-service-cancellation')
                    <a href="{{ route('service-orders.cancellation.resolution.create', [$order, $cancellation]) }}" class="inline-flex shrink-0 items-center justify-center rounded-xl bg-orange-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-orange-300">Resolver cancelación</a>
                @endcan
            @elseif($order->status === \App\Enums\ServiceOrderStatus::ReadyForReturn && $resolution && ! $returnRecord)
                @can('return-cancelled-service-order')
                    <a href="{{ route('service-orders.cancellation.return.create', [$order, $resolution]) }}" class="inline-flex shrink-0 items-center justify-center rounded-xl bg-red-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-red-300">Registrar devolución</a>
                @endcan
            @endif
        </div>

        @if(! $cancellation)
            <div class="mt-5 rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-4 text-sm text-slate-400">
                No existe una solicitud. El expediente conserva su flujo normal hasta que una persona autorizada registre la revocación.
            </div>
        @else
            <div class="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.8fr)]">
                <article class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-rose-300">Solicitud</p>
                            <h3 class="mt-2 font-bold text-white">{{ $cancellation->reason->label() }}</h3>
                        </div>
                        <span class="rounded-full bg-slate-950 px-2.5 py-1 text-xs text-slate-400">{{ $cancellation->channel }}</span>
                    </div>
                    <p class="mt-3 text-sm text-slate-300">{{ $cancellation->requester_name }} · {{ $cancellation->requested_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                    @if($cancellation->details)<p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-400">{{ $cancellation->details }}</p>@endif
                    <p class="mt-3 text-[10px] uppercase tracking-wider text-slate-600">Registró {{ $cancellation->requestedBy->name }}</p>
                </article>

                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Exposición al solicitar</p>
                    <dl class="mt-3 space-y-2 text-xs">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Trabajo iniciado</dt><dd class="font-semibold {{ $cancellation->has_started_work ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_started_work ? 'Sí' : 'No' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Compras</dt><dd class="font-semibold {{ $cancellation->has_part_purchases ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_part_purchases ? 'Sí' : 'No' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Consumos</dt><dd class="font-semibold {{ $cancellation->has_part_consumptions ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_part_consumptions ? 'Sí' : 'No' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Custodia externa</dt><dd class="font-semibold {{ $cancellation->has_external_custody ? 'text-red-300' : 'text-emerald-300' }}">{{ $cancellation->has_external_custody ? 'Sí' : 'No' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Pagos</dt><dd class="font-semibold {{ $cancellation->has_registered_payments ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_registered_payments ? 'Sí' : 'No' }}</dd></div>
                    </dl>
                </article>
            </div>

            @if($externalPending->isNotEmpty())
                <div class="mt-5 rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h3 class="font-bold text-fuchsia-200">Custodia externa pendiente</h3><p class="mt-1 text-xs text-slate-400">El equipo debe volver al comercio antes de la resolución.</p></div>
                        <span class="rounded-full bg-fuchsia-500/10 px-2.5 py-1 text-xs font-bold text-fuchsia-300">{{ $externalPending->count() }}</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach($externalPending as $work)
                            <div class="flex flex-col gap-3 rounded-xl border border-slate-800 bg-slate-950/60 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div><p class="text-sm font-semibold text-white">{{ $work->sequence }}. {{ $work->title }}</p><p class="mt-1 text-xs text-slate-500">{{ $work->provider?->name ?? 'Especialista externo' }}</p></div>
                                @can('transfer-service-custody')
                                    <a href="{{ route('service-orders.cancellation.recall.create', [$order, $work]) }}" class="inline-flex items-center justify-center rounded-xl border border-fuchsia-500/40 px-4 py-2 text-xs font-bold text-fuchsia-300 transition hover:bg-fuchsia-500/10">Registrar retorno</a>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($resolution)
                <article class="mt-5 rounded-xl border border-orange-500/20 bg-orange-500/5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><p class="text-xs font-semibold uppercase tracking-wider text-orange-300">Resolución administrativa</p><h3 class="mt-2 font-bold text-white">{{ $resolution->financial_outcome->label() }}</h3></div>
                        <span class="text-xs text-slate-500">{{ $resolution->resolved_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $resolution->resolvedBy->name }}</span>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-3">
                        <div class="rounded-lg bg-slate-950/60 p-3"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Trabajo</p><p class="mt-2 text-xs leading-5 text-slate-300">{{ $resolution->work_disposition }}</p></div>
                        <div class="rounded-lg bg-slate-950/60 p-3"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Repuestos</p><p class="mt-2 text-xs leading-5 text-slate-300">{{ $resolution->parts_disposition }}</p></div>
                        <div class="rounded-lg bg-slate-950/60 p-3"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Finanzas</p><p class="mt-2 text-xs leading-5 text-slate-300">{{ $resolution->financial_disposition }}</p>@if($resolution->customer_charge_minor > 0)<p class="mt-2 font-mono text-xs text-orange-200">{{ $resolution->currency_code }} {{ number_format($resolution->customer_charge_minor / 100, 2, ',', '.') }}</p>@endif</div>
                    </div>
                </article>
            @endif

            @if($returnRecord)
                <article class="mt-5 rounded-xl border border-red-500/20 bg-red-500/5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wider text-red-300">Devolución final</p><h3 class="mt-2 font-bold text-white">Entregado a {{ $returnRecord->recipient_name }}</h3></div><span class="text-xs text-slate-500">{{ $returnRecord->returned_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span></div>
                    <p class="mt-3 text-sm text-slate-300">{{ $returnRecord->condition_notes }}</p>
                    <p class="mt-2 text-xs text-slate-500">Accesorios: {{ $returnRecord->accessories_snapshot }}</p>
                    <p class="mt-3 text-[10px] uppercase tracking-wider text-slate-600">Registró {{ $returnRecord->returnedBy->name }}</p>
                </article>
            @endif
        @endif
    </section>
@endif
