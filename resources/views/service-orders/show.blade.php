<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Expediente</p>
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $order->status->label() }}</span>
                </div>
                <h1 class="mt-2 text-2xl font-bold text-white">OT #{{ $order->order_number }} · {{ $order->asset->brand_name }} {{ $order->asset->model_name }}</h1>
                <p class="mt-2 text-sm text-slate-400">Recibida el {{ $order->received_at->timezone(config('app.timezone'))->format('d/m/Y \a \l\a\s H:i') }} por {{ $order->createdBy->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('service-orders.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver a órdenes</a>
                @can('create-service-orders')
                    <a href="{{ route('service-orders.create') }}" class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Recibir otro equipo</a>
                @endcan
            </div>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif

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

                @if($order->diagnostics->isNotEmpty() || $order->quotes->isNotEmpty() || $order->workItems->isNotEmpty())
                    <section class="sulu-card p-6">
                        <h2 class="text-lg font-bold text-white">Últimos registros del expediente</h2>
                        <div class="mt-5 space-y-4">
                            @if($diagnostic = $order->diagnostics->last())
                                <article class="rounded-xl border border-violet-500/20 bg-violet-500/5 p-4"><p class="text-xs font-semibold uppercase tracking-wider text-violet-300">Diagnóstico · Revisión {{ $diagnostic->revision }}</p><p class="mt-2 text-sm font-semibold text-white">{{ $diagnostic->summary }}</p><p class="mt-1 text-sm text-slate-400">{{ $diagnostic->recommendation }}</p></article>
                            @endif
                            @if($quote = $order->quotes->last())
                                <article class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4"><div class="flex flex-wrap justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wider text-amber-300">Presupuesto · Revisión {{ $quote->revision }}</p><p class="mt-2 text-sm text-slate-300">{{ $quote->options->count() }} {{ $quote->options->count() === 1 ? 'alternativa' : 'alternativas' }} · {{ $quote->currency_code }}</p></div><span class="text-xs font-semibold {{ $quote->decision ? 'text-emerald-300' : 'text-slate-500' }}">{{ $quote->decision?->decision->label() ?? 'Sin decisión' }}</span></div></article>
                            @endif
                            @foreach($order->workItems->take(5) as $work)
                                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm font-semibold text-white">{{ $work->sequence }}. {{ $work->title }}</p><p class="mt-1 text-xs text-slate-500">{{ $work->execution_mode->label() }}{{ $work->provider ? ' · '.$work->provider->name : '' }}</p></div><span class="rounded-full bg-slate-800 px-2.5 py-1 text-xs font-semibold text-slate-300">{{ $work->status->label() }}</span></div></article>
                            @endforeach
                        </div>
                    </section>
                @endif
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
