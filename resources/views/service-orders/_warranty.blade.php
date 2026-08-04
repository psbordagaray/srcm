@php
    $warranties = $order->delivery?->warranties ?? collect();
    $originalClaims = $order->warrantyClaimsAsOriginal ?? collect();
    $correctiveClaim = $order->warrantyClaimAsCorrective;
    $hasWarrantyContext = $warranties->isNotEmpty()
        || $originalClaims->isNotEmpty()
        || $correctiveClaim !== null;
@endphp

@if($hasWarrantyContext)
    <section class="sulu-card border-cyan-500/20 p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Garantías y reingresos</p>
                <h2 class="mt-1 text-lg font-bold text-white">Centro de reclamos de garantía</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">La orden original, el reclamo y la orden correctiva permanecen vinculados sin reescribir la entrega ni la garantía otorgada.</p>
            </div>
            @if($correctiveClaim)
                <span class="inline-flex rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-1.5 text-xs font-semibold text-cyan-200">{{ $correctiveClaim->status->label() }}</span>
            @endif
        </div>

        @if($warranties->isNotEmpty())
            <div class="mt-6 space-y-4">
                @foreach($warranties as $warranty)
                    @php
                        $openClaim = $warranty->claims->first(
                            fn ($claim) => $claim->status->isOpen()
                        );
                        $active = now()->lessThanOrEqualTo($warranty->expires_at);
                    @endphp
                    <article class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-bold text-white">Garantía de {{ $warranty->warranty_days }} días</p>
                                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase {{ $active ? 'bg-emerald-500/10 text-emerald-300' : 'bg-amber-500/10 text-amber-300' }}">{{ $active ? 'Vigente' : 'Vencida' }}</span>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">Desde {{ $warranty->starts_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} hasta {{ $warranty->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.</p>
                                <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $warranty->coverage_terms }}</p>
                            </div>
                            @can('register-service-warranty-claims')
                                @if(! $openClaim && $order->status === \App\Enums\ServiceOrderStatus::Delivered)
                                    <a href="{{ route('service-orders.warranty-claims.create', [$order, $warranty]) }}" class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Registrar reclamo</a>
                                @endif
                            @endcan
                        </div>

                        @if($warranty->claims->isNotEmpty())
                            <div class="mt-5 space-y-3 border-t border-cyan-500/10 pt-4">
                                @foreach($warranty->claims as $claim)
                                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                            <div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="text-sm font-semibold text-white">Reclamo {{ $claim->public_id }}</p>
                                                    <span class="rounded-full bg-slate-800 px-2.5 py-1 text-[10px] font-semibold text-slate-300">{{ $claim->status->label() }}</span>
                                                </div>
                                                <p class="mt-2 text-xs text-slate-500">Registrado {{ $claim->received_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} por {{ $claim->receivedBy?->name }} · canal {{ $claim->channel }}.</p>
                                                <p class="mt-3 whitespace-pre-line text-sm text-slate-300">{{ $claim->reported_issue }}</p>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <a href="{{ route('service-orders.show', $claim->correctiveOrder) }}" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Ver orden correctiva #{{ $claim->correctiveOrder->order_number }}</a>
                                                @can('resolve-service-warranty-claims')
                                                    @if($claim->status === \App\Enums\ServiceWarrantyClaimStatus::PendingReview)
                                                        <a href="{{ route('service-orders.warranty-claims.resolution.create', [$order, $claim]) }}" class="rounded-lg bg-amber-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-amber-300">Resolver reclamo</a>
                                                    @endif
                                                @endcan
                                            </div>
                                        </div>
                                        @if($claim->resolution)
                                            <div class="mt-4 grid gap-3 border-t border-slate-800 pt-4 md:grid-cols-2">
                                                <div><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Resultado</p><p class="mt-1 text-sm font-semibold text-white">{{ $claim->resolution->outcome->label() }}</p></div>
                                                <div><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Resolvió</p><p class="mt-1 text-sm text-slate-300">{{ $claim->resolution->resolvedBy?->name }} · {{ $claim->resolution->resolved_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p></div>
                                                @if($claim->resolution->covered_scope)<div><p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-400">Cubierto</p><p class="mt-1 whitespace-pre-line text-sm text-slate-300">{{ $claim->resolution->covered_scope }}</p></div>@endif
                                                @if($claim->resolution->excluded_scope)<div><p class="text-[10px] font-semibold uppercase tracking-wider text-rose-400">Excluido</p><p class="mt-1 whitespace-pre-line text-sm text-slate-300">{{ $claim->resolution->excluded_scope }}</p></div>@endif
                                            </div>
                                        @endif
                                        <div class="mt-4 border-t border-slate-800 pt-4">
                                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Historia del reclamo</p>
                                            <div class="mt-3 space-y-2">
                                                @foreach($claim->statusHistory as $history)
                                                    <div class="flex flex-col gap-1 rounded-lg border border-slate-800 bg-slate-950/50 px-3 py-2 text-xs sm:flex-row sm:items-center sm:justify-between">
                                                        <span class="text-slate-300">{{ $history->from_status?->label() ?? 'Inicio' }} → {{ $history->to_status->label() }} · {{ $history->reason }}</span>
                                                        <span class="whitespace-nowrap text-slate-600">{{ $history->changed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $history->changedBy?->name }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        @if($claim->returnRecord)
                                            <div class="mt-4 rounded-lg border border-rose-500/20 bg-rose-500/5 px-3 py-3 text-xs text-rose-100">Devuelto a <strong>{{ $claim->returnRecord->recipient_name }}</strong> el {{ $claim->returnRecord->returned_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} por {{ $claim->returnRecord->returnedBy?->name }}.</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        @if($correctiveClaim)
            <article class="mt-6 rounded-2xl border border-violet-500/20 bg-violet-500/5 p-5">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-violet-300">Esta es una orden correctiva de garantía</p>
                        <p class="mt-2 text-sm text-slate-300">Reclamo de <strong>{{ $correctiveClaim->claimant_name }}</strong>: {{ $correctiveClaim->reported_issue }}</p>
                        <p class="mt-2 text-xs text-slate-500">Condición de reingreso: {{ $correctiveClaim->reentry_condition_notes }}</p>
                        <a href="{{ route('service-orders.show', $correctiveClaim->originalOrder) }}" class="mt-3 inline-flex text-sm font-semibold text-cyan-300 transition hover:text-cyan-200">Ver orden original #{{ $correctiveClaim->originalOrder->order_number }}</a>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @can('resolve-service-warranty-claims')
                            @if($correctiveClaim->status === \App\Enums\ServiceWarrantyClaimStatus::PendingReview)
                                <a href="{{ route('service-orders.warranty-claims.resolution.create', [$order, $correctiveClaim]) }}" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Resolver reclamo</a>
                            @endif
                        @endcan
                        @can('return-service-warranty-claims')
                            @if($correctiveClaim->status === \App\Enums\ServiceWarrantyClaimStatus::ReadyForReturn && ! $correctiveClaim->returnRecord)
                                <a href="{{ route('service-orders.warranty-claims.return.create', [$order, $correctiveClaim]) }}" class="rounded-xl bg-rose-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-rose-300">Registrar devolución</a>
                            @endif
                        @endcan
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Estado del reclamo</p><p class="mt-2 text-sm font-semibold text-white">{{ $correctiveClaim->status->label() }}</p></div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Garantía al reclamar</p><p class="mt-2 text-sm font-semibold text-white">{{ $correctiveClaim->warranty_status_at_claim->label() }}</p></div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Ubicación de reingreso</p><p class="mt-2 text-sm text-slate-300">{{ $correctiveClaim->intakeLocation?->name }}</p></div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Accesorios</p><p class="mt-2 text-sm text-slate-300">{{ $correctiveClaim->accessories_snapshot }}</p></div>
                </div>

                @if($correctiveClaim->resolution)
                    <div class="mt-5 rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-sm font-bold text-white">Resolución: {{ $correctiveClaim->resolution->outcome->label() }}</p><span class="text-xs text-slate-500">{{ $correctiveClaim->resolution->resolvedBy?->name }} · {{ $correctiveClaim->resolution->resolved_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span></div>
                        <p class="mt-3 whitespace-pre-line text-sm text-slate-300">{{ $correctiveClaim->resolution->technical_basis }}</p>
                        @if($correctiveClaim->resolution->administrative_exception)<p class="mt-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-xs text-amber-200"><strong>Excepción administrativa:</strong> {{ $correctiveClaim->resolution->exception_reason }}</p>@endif
                    </div>
                @endif

                <div class="mt-5 rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Historia del reclamo</p>
                    <div class="mt-3 space-y-2">
                        @foreach($correctiveClaim->statusHistory as $history)
                            <div class="flex flex-col gap-1 rounded-lg border border-slate-800 px-3 py-2 text-xs sm:flex-row sm:items-center sm:justify-between">
                                <span class="text-slate-300">{{ $history->from_status?->label() ?? 'Inicio' }} → {{ $history->to_status->label() }} · {{ $history->reason }}</span>
                                <span class="whitespace-nowrap text-slate-600">{{ $history->changed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $history->changedBy?->name }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if($correctiveClaim->returnRecord)
                    <div class="mt-5 rounded-xl border border-rose-500/20 bg-rose-500/5 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-rose-300">Devolución final</p>
                        <p class="mt-2 text-sm text-slate-200">Entregado a <strong>{{ $correctiveClaim->returnRecord->recipient_name }}</strong> el {{ $correctiveClaim->returnRecord->returned_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} por {{ $correctiveClaim->returnRecord->returnedBy?->name }}.</p>
                        <p class="mt-2 text-xs text-slate-400">{{ $correctiveClaim->returnRecord->condition_notes }}</p>
                    </div>
                @endif
            </article>
        @endif
    </section>
@endif
