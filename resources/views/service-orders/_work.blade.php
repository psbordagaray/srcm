<section class="sulu-card p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Ejecución técnica</p>
            <h2 class="mt-1 text-lg font-bold text-white">Trabajos y custodia</h2>
            <p class="mt-1 text-sm text-slate-500">Alcance aprobado, responsables, especialistas y resultados inmutables.</p>
        </div>
        @can('plan-service-work')
            @if($order->status === \App\Enums\ServiceOrderStatus::InProgress)
                <a href="{{ route('service-orders.work-items.create', $order) }}" class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Planificar trabajo</a>
            @endif
        @endcan
    </div>

    @if($errors->has('work'))
        <div role="alert" class="mt-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
            {{ $errors->first('work') }}
        </div>
    @endif

    <div class="mt-5 space-y-4">
        @forelse($order->workItems as $work)
            <article class="rounded-2xl border border-slate-800 bg-slate-950/50 p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-bold text-white">{{ $work->sequence }}. {{ $work->title }}</p>
                            @if($work->service_warranty_claim_resolution_id)
                                <span class="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-[10px] font-bold uppercase text-emerald-300">Garantía</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $work->execution_mode->label() }}
                            @if($work->assignedUser)
                                · Responsable: {{ $work->assignedUser->name }}
                            @elseif($work->provider)
                                · Especialista: {{ $work->provider->name }}
                            @endif
                        </p>
                    </div>
                    <span class="rounded-full border border-slate-700 bg-slate-900 px-2.5 py-1 text-xs font-semibold text-slate-300">{{ $work->status->label() }}</span>
                </div>

                <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $work->description }}</p>

                @if($work->custodyLinks->isNotEmpty())
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach($work->custodyLinks as $link)
                            <div class="rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/5 p-3 text-xs text-slate-300">
                                <p class="font-semibold text-fuchsia-200">{{ $link->direction->label() }}</p>
                                <p class="mt-1">{{ $link->custodyEvent->from_holder_name }} → {{ $link->custodyEvent->to_holder_name }}</p>
                                <p class="mt-2 text-slate-500">{{ $link->custodyEvent->occurred_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($work->report)
                    <div class="mt-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">{{ $work->report->outcome->label() }}</p>
                            <p class="text-[11px] text-slate-500">{{ $work->report->recorded_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $work->report->recordedBy->name }}</p>
                        </div>
                        <p class="mt-2 text-sm font-semibold text-white">{{ $work->report->result_summary }}</p>
                        <p class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-300">{{ $work->report->work_performed }}</p>
                        @if($work->report->unresolved_reason)
                            <p class="mt-3 rounded-lg border border-red-500/20 bg-red-500/5 px-3 py-2 text-xs text-red-200"><strong>Motivo sin solución:</strong> {{ $work->report->unresolved_reason }}</p>
                        @endif
                        @if($work->report->warranty_days !== null)
                            <p class="mt-3 text-xs text-emerald-200">Garantía informada: {{ $work->report->warranty_days }} días{{ $work->report->warranty_terms ? ' · '.$work->report->warranty_terms : '' }}</p>
                        @endif
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @can('execute-service-work')
                        @if(
                            $work->execution_mode === \App\Enums\ServiceWorkExecutionMode::Internal
                            && $work->status === \App\Enums\ServiceWorkStatus::Planned
                            && $order->status === \App\Enums\ServiceOrderStatus::InProgress
                        )
                            <form method="POST" action="{{ route('service-orders.work-items.start', [$order, $work]) }}">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ 'service-ui:work-start:'.\Illuminate\Support\Str::uuid() }}">
                                <button type="submit" class="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-cyan-300">Iniciar ejecución</button>
                            </form>
                        @endif

                        @if(
                            $work->status === \App\Enums\ServiceWorkStatus::InProgress
                            && $order->status === \App\Enums\ServiceOrderStatus::InProgress
                            && ! $work->report
                        )
                            <a href="{{ route('service-orders.work-items.report.create', [$order, $work]) }}" class="rounded-lg bg-emerald-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-emerald-300">Registrar resultado</a>
                        @endif
                    @endcan

                    @can('transfer-service-custody')
                        @if(
                            $work->execution_mode === \App\Enums\ServiceWorkExecutionMode::External
                            && $work->status === \App\Enums\ServiceWorkStatus::Planned
                            && $order->status === \App\Enums\ServiceOrderStatus::InProgress
                        )
                            <a href="{{ route('service-orders.work-items.dispatch.create', [$order, $work]) }}" class="rounded-lg bg-fuchsia-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-fuchsia-300">Entregar a especialista</a>
                        @endif

                        @if(
                            $work->execution_mode === \App\Enums\ServiceWorkExecutionMode::External
                            && $work->status === \App\Enums\ServiceWorkStatus::WithProvider
                            && $order->status === \App\Enums\ServiceOrderStatus::WithExternalProvider
                        )
                            <a href="{{ route('service-orders.work-items.return.create', [$order, $work]) }}" class="rounded-lg bg-violet-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-violet-300">Registrar retorno</a>
                        @endif
                    @endcan
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-700 bg-slate-950/30 p-6 text-center">
                <p class="text-sm font-semibold text-slate-300">Todavía no hay trabajos planificados.</p>
                <p class="mt-1 text-xs text-slate-500">El trabajo debe surgir del presupuesto aprobado o de una resolución de garantía.</p>
            </div>
        @endforelse
    </div>
</section>
