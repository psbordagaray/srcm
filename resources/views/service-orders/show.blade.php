<x-app-layout>
    @php($latestQuote = $order->quotes->last())
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Expediente</p>
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $order->status->label() }}</span>
                </div>
                <h1 class="mt-2 text-2xl font-bold text-white">Orden #{{ $order->order_number }} · {{ $order->asset->brand_name }} {{ $order->asset->model_name }}</h1>
                <p class="mt-2 text-sm text-slate-400">Recibida el {{ $order->received_at->timezone(config('app.timezone'))->format('d/m/Y \a \l\a\s H:i') }} por {{ $order->createdBy->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                @can('record-service-diagnostics')
                    @if(in_array($order->status, [\App\Enums\ServiceOrderStatus::Received, \App\Enums\ServiceOrderStatus::Diagnosing], true))
                        <a href="{{ route('service-orders.diagnostics.create', $order) }}" class="rounded-xl bg-violet-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-violet-300">{{ $order->diagnostics->isEmpty() ? 'Registrar diagnóstico' : 'Nueva revisión diagnóstica' }}</a>
                    @endif
                @endcan
                @can('issue-service-quotes')
                    @if($order->status === \App\Enums\ServiceOrderStatus::Diagnosing && $order->diagnostics->isNotEmpty())
                        <a href="{{ route('service-orders.quotes.create', $order) }}" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Emitir presupuesto</a>
                    @endif
                @endcan
                @can('record-service-quote-decisions')
                    @if($order->status === \App\Enums\ServiceOrderStatus::AwaitingApproval && $latestQuote && ! $latestQuote->decision)
                        <a href="{{ route('service-orders.quotes.decisions.create', [$order, $latestQuote]) }}" class="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-emerald-300">Registrar decisión</a>
                    @endif
                @endcan
                <a href="{{ route('service-orders.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver a órdenes</a>
                @can('create-service-orders')
                    <a href="{{ route('service-orders.create') }}" class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Recibir otro equipo</a>
                @endcan
            </div>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif

        @include('service-orders._cancellation', ['order' => $order])
        @include('service-orders._warranty', ['order' => $order])
        @include('service-orders._evidence', ['order' => $order])

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(20rem,0.8fr)]">
            <div class="space-y-6">
                <section class="sulu-card p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fotografía de ingreso</p><h2 class="mt-1 text-lg font-bold text-white">Condición declarada y observada</h2></div>
                        <span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-slate-400">Registro inmutable</span>
                    </div>
                    <div class="mt-6 grid gap-5 md:grid-cols-2">
                        <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Lo que declara el cliente</h3>
                            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-200">{{ $order->intake->customer_reported_issue }}</p>
                        </div>
                        <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-amber-300">Observación propia al recibir</h3>
                            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-200">{{ $order->intake->intake_observations ?: 'Sin observaciones adicionales.' }}</p>
                        </div>
                    </div>
                    <div class="mt-5 rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Accesorios en custodia</h3>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-300">{{ $order->intake->received_accessories ?: 'No se registraron accesorios.' }}</p>
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Actividad técnica y comercial</h2>
                    <p class="mt-1 text-sm text-slate-500">Resumen acumulado de los seis núcleos de Reparaciones.</p>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @php($activity = [
                            ['Diagnósticos', $order->diagnostics->count(), 'Revisiones técnicas'],
                            ['Presupuestos', $order->quotes->count(), 'Opciones versionadas'],
                            ['Trabajos', $order->workItems->count(), 'Propios y tercerizados'],
                            ['Repuestos', $order->partRequirements->count(), 'Necesidades afectadas'],
                            ['Controles', $order->qualityInspections->count(), 'Calidad antes de entregar'],
                            ['Venta', $order->commerceSale ? 1 : 0, $order->commerceSale ? 'Operación vinculada' : 'Aún no registrada'],
                        ])
                        @foreach($activity as [$label, $count, $description])
                            <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                                <div class="flex items-start justify-between gap-3"><p class="text-sm font-semibold text-slate-200">{{ $label }}</p><span class="text-xl font-bold {{ $count ? 'text-cyan-300' : 'text-slate-600' }}">{{ $count }}</span></div>
                                <p class="mt-2 text-xs text-slate-500">{{ $description }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>

                @if($order->diagnostics->isNotEmpty() || $order->quotes->isNotEmpty())
                    <section class="sulu-card p-6">
                        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-lg font-bold text-white">Diagnósticos y presupuestos</h2><p class="mt-1 text-sm text-slate-500">Historial completo de revisiones inmutables y decisiones.</p></div><span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-400">{{ $order->diagnostics->count() }} diagnósticos · {{ $order->quotes->count() }} presupuestos</span></div>
                        <div class="mt-5 space-y-5">
                            @foreach($order->diagnostics->sortByDesc('revision') as $diagnostic)
                                <article class="rounded-2xl border border-violet-500/20 bg-violet-500/5 p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wider text-violet-300">Diagnóstico · Revisión {{ $diagnostic->revision }}</p><p class="mt-2 text-sm font-bold text-white">{{ $diagnostic->summary }}</p></div><span class="text-xs text-slate-500">{{ $diagnostic->diagnosed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $diagnostic->diagnosedBy->name }}</span></div>
                                    <p class="mt-3 text-sm text-slate-300"><span class="font-semibold text-violet-200">Recomendación:</span> {{ $diagnostic->recommendation }}</p>
                                    @if($diagnostic->data_risk_notes)
                                        @php($noRisk = in_array(\Illuminate\Support\Str::of($diagnostic->data_risk_notes)->lower()->squish()->toString(), ['no hay riesgo', 'sin riesgo', 'ningún riesgo', 'ningun riesgo'], true))
                                        <p class="mt-3 rounded-xl border px-3 py-2 text-xs {{ $noRisk ? 'border-emerald-500/20 bg-emerald-500/5 text-emerald-200' : 'border-red-500/20 bg-red-500/5 text-red-200' }}"><strong>{{ $noRisk ? 'Sin riesgo identificado.' : 'Riesgo sobre datos:' }}</strong>{{ $noRisk ? '' : ' '.$diagnostic->data_risk_notes }}</p>
                                    @endif
                                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                                        @foreach($diagnostic->findings as $finding)
                                            @php($findingClass = match($finding->severity) { \App\Enums\ServiceFindingSeverity::Critical => 'border-red-500/20 bg-red-500/5 text-red-300', \App\Enums\ServiceFindingSeverity::Attention => 'border-amber-500/20 bg-amber-500/5 text-amber-300', default => 'border-sky-500/20 bg-sky-500/5 text-sky-300' })
                                            <div class="rounded-xl border p-3 {{ $findingClass }}"><div class="flex justify-between gap-3"><span class="text-xs font-bold uppercase tracking-wider">{{ $finding->category }}</span><span class="text-[10px] font-semibold uppercase">{{ $finding->severity->label() }}</span></div><p class="mt-2 text-xs leading-5 text-slate-300">{{ $finding->description }}</p>@if($finding->evidence_notes)<p class="mt-2 text-[11px] leading-5 text-slate-500">Evidencia: {{ $finding->evidence_notes }}</p>@endif</div>
                                        @endforeach
                                    </div>
                                </article>
                            @endforeach

                            @foreach($order->quotes->sortByDesc('revision') as $quote)
                                <article class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wider text-amber-300">Presupuesto · Revisión {{ $quote->revision }}</p><p class="mt-2 text-xs text-slate-500">Emitido {{ $quote->issued_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} por {{ $quote->issuedBy->name }} · Diagnóstico R{{ $quote->diagnostic?->revision }}</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $quote->decision ? ($quote->decision->decision === \App\Enums\ServiceQuoteDecisionType::Approved ? 'bg-emerald-500/10 text-emerald-300' : 'bg-red-500/10 text-red-300') : 'bg-slate-800 text-slate-400' }}">{{ $quote->decision?->decision->label() ?? 'Esperando decisión' }}</span></div>
                                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                        @foreach($quote->options as $option)
                                            <div class="rounded-xl border {{ $quote->decision?->service_quote_option_id === $option->id ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-slate-800 bg-slate-950/60' }} p-4"><div class="flex flex-wrap items-center justify-between gap-3"><div class="flex items-center gap-2"><h3 class="text-sm font-bold text-white">{{ $option->label }}</h3>@if($option->recommended)<span class="rounded-full bg-amber-400/10 px-2 py-1 text-[9px] font-bold uppercase text-amber-300">Recomendada</span>@endif</div><strong class="font-mono text-sm text-amber-200">$ {{ number_format($option->total_minor / 100, 2, ',', '.') }}</strong></div><p class="mt-2 text-xs text-slate-500">{{ $option->description }}</p><div class="mt-3 space-y-1 border-t border-slate-800 pt-3">@foreach($option->lines as $line)<div class="flex justify-between gap-3 text-[11px]"><span class="text-slate-400">{{ $line->line_type->label() }} · {{ $line->description }}</span><span class="whitespace-nowrap text-slate-300">$ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}</span></div>@endforeach</div></div>
                                        @endforeach
                                    </div>
                                    @if($quote->decision)<div class="mt-4 rounded-xl border border-slate-800 bg-slate-950/60 p-4"><p class="text-xs text-slate-300"><strong>{{ $quote->decision->customer_name }}</strong> · {{ $quote->decision->channel }} · {{ $quote->decision->decided_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>@if($quote->decision->reason)<p class="mt-2 text-xs text-slate-500">{{ $quote->decision->reason }}</p>@endif<p class="mt-2 text-[10px] uppercase tracking-wider text-slate-600">Registró {{ $quote->decision->recordedBy->name }}</p></div>@endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif

                @include('service-orders._work', ['order' => $order])
                @include('service-orders._parts', ['order' => $order])
                @include('service-orders._completion', ['order' => $order])
            </div>

            <div class="space-y-6">
                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Identidad del equipo</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tipo</dt><dd class="mt-1 text-slate-200">{{ $order->asset->asset_type->label() }}</dd></div>
                        <div class="grid grid-cols-2 gap-4"><div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Marca</dt><dd class="mt-1 text-slate-200">{{ $order->asset->brand_name }}</dd></div><div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Modelo</dt><dd class="mt-1 text-slate-200">{{ $order->asset->model_name }}</dd></div></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Color</dt><dd class="mt-1 text-slate-200">{{ $order->intake->color_snapshot ?: 'No informado' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Identificadores</dt><dd class="mt-2 space-y-2">@forelse($order->asset->identifiers as $identifier)<div class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"><span class="text-xs text-slate-500">{{ $identifier->identifier_type->label() }}</span><p class="mt-1 break-all font-mono text-xs text-cyan-300">{{ $identifier->value }}</p></div>@empty<span class="text-slate-500">Sin identificadores técnicos.</span>@endforelse</dd></div>
                    </dl>
                </section>

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Personas y contacto</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Quien entrega</dt><dd class="mt-1 text-slate-200">{{ $order->intake->customer_name_snapshot }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Propietario</dt><dd class="mt-1 text-slate-200">{{ $order->intake->owner_name_snapshot }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Contacto</dt><dd class="mt-1 {{ $order->intake->contact_available ? 'text-emerald-300' : 'text-amber-300' }}">{{ $order->intake->contact_available ? $order->intake->contact_reference : 'No disponible al ingresar' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Recepción</dt><dd class="mt-1 text-slate-200">{{ $order->intakeLocation->name }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fecha prometida</dt><dd class="mt-1 text-slate-200">{{ $order->promised_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'Sin compromiso informado' }}</dd></div>
                    </dl>
                </section>

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Historia de estado</h2>
                    <div class="mt-5 space-y-5 border-l border-slate-700 pl-5">
                        @foreach($order->statusHistory as $event)
                            <article class="relative"><span class="absolute -left-[1.55rem] top-1 h-2.5 w-2.5 rounded-full bg-cyan-300 ring-4 ring-slate-900"></span><p class="text-sm font-semibold text-white">{{ $event->to_status->label() }}</p><p class="mt-1 text-xs text-slate-500">{{ $event->changed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $event->changedBy->name }}</p><p class="mt-2 text-xs leading-5 text-slate-400">{{ $event->reason }}</p></article>
                        @endforeach
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Cadena de custodia</h2>
                    <div class="mt-5 space-y-3">
                        @foreach($order->custodyEvents as $event)
                            <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4"><div class="flex items-center justify-between gap-3"><span class="text-xs font-bold uppercase tracking-wider text-cyan-300">{{ $event->event_type->label() }}</span><span class="text-xs text-slate-600">{{ $event->occurred_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span></div><p class="mt-3 text-sm text-slate-300">{{ $event->from_holder_name }} <span class="text-slate-600">→</span> {{ $event->to_holder_name }}</p>@if($event->location)<p class="mt-1 text-xs text-slate-500">{{ $event->location->name }}</p>@endif</article>
                        @endforeach
                    </div>
                </section>

                @if($order->delivery)
                    <section class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-6"><h2 class="font-bold text-emerald-200">Entrega registrada</h2><p class="mt-2 text-sm text-slate-300">{{ $order->delivery->recipient_name }} · {{ $order->delivery->delivered_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p><p class="mt-3 text-xs text-slate-500">{{ $order->delivery->warranties->count() }} {{ $order->delivery->warranties->count() === 1 ? 'garantía otorgada' : 'garantías otorgadas' }}</p></section>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
