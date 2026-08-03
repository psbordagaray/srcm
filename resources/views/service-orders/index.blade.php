<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Reparaciones · Operación técnica
                </p>
                <h1 class="mt-1 text-2xl font-bold text-white">Órdenes de servicio</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Recepción, identidad del equipo y expediente trazable de cada reparación.
                </p>
            </div>

            @can('create-service-orders')
                <a href="{{ route('service-orders.create') }}" class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">
                    Recibir equipo
                </a>
            @endcan
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Órdenes abiertas</p>
                <p class="mt-2 text-3xl font-bold text-cyan-300">{{ $summary['open'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Esperando aprobación</p>
                <p class="mt-2 text-3xl font-bold {{ $summary['awaiting_approval'] ? 'text-amber-300' : 'text-slate-300' }}">{{ $summary['awaiting_approval'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Con colega externo</p>
                <p class="mt-2 text-3xl font-bold {{ $summary['external'] ? 'text-fuchsia-300' : 'text-slate-300' }}">{{ $summary['external'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Listas para entregar</p>
                <p class="mt-2 text-3xl font-bold {{ $summary['ready'] ? 'text-emerald-300' : 'text-slate-300' }}">{{ $summary['ready'] }}</p>
            </article>
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form method="GET" action="{{ route('service-orders.index') }}" class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_14rem_14rem_auto]">
                    <input type="search" name="search" value="{{ $search }}" placeholder="Orden, cliente, IMEI, serie, patente o equipo..." class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400">
                    <select name="asset_type" class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Todos los equipos</option>
                        @foreach($assetTypes as $type)
                            <option value="{{ $type->value }}" @selected($assetType === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Todos los estados</option>
                        @foreach($statuses as $orderStatus)
                            <option value="{{ $orderStatus->value }}" @selected($status === $orderStatus->value)>{{ $orderStatus->label() }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Buscar</button>
                        @if($search !== '' || $assetType !== '' || $status !== '')
                            <a href="{{ route('service-orders.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Limpiar</a>
                        @endif
                    </div>
                </form>
            </div>

            @if($orders->total() === 0)
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-cyan-400/10 text-cyan-300">
                        @include('components.sidebar-icon', ['name' => 'repair', 'class' => 'h-7 w-7'])
                    </div>
                    <h2 class="mt-4 text-lg font-semibold text-white">
                        {{ $search === '' && $assetType === '' && $status === '' ? 'Todavía no existen órdenes de servicio' : 'Ninguna orden coincide con los filtros' }}
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        {{ $search === '' && $assetType === '' && $status === '' ? 'El expediente comenzará cuando se reciba el primer equipo.' : 'Probá con otro IMEI, número de orden, cliente o estado.' }}
                    </p>
                </div>
            @else
                <div class="divide-y divide-slate-800">
                    @foreach($orders as $order)
                        <a href="{{ route('service-orders.show', $order) }}" class="block p-5 transition hover:bg-slate-800/30">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-bold text-cyan-300">Orden #{{ $order->order_number }}</span>
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$order->status->value] }}">{{ $order->status->label() }}</span>
                                        <span class="inline-flex rounded-full bg-slate-800 px-2.5 py-1 text-xs font-semibold text-slate-300">{{ $order->asset->asset_type->label() }}</span>
                                    </div>
                                    <h2 class="mt-3 truncate text-base font-bold text-white">{{ $order->asset->brand_name }} {{ $order->asset->model_name }}</h2>
                                    <p class="mt-1 line-clamp-2 text-sm text-slate-400">{{ $order->intake->customer_reported_issue }}</p>
                                    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
                                        <span><strong class="font-semibold text-slate-300">Cliente:</strong> {{ $order->intake->customer_name_snapshot }}</span>
                                        <span><strong class="font-semibold text-slate-300">Ingreso:</strong> {{ $order->received_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                                        <span><strong class="font-semibold text-slate-300">Ubicación:</strong> {{ $order->intakeLocation->name }}</span>
                                    </div>
                                </div>
                                <div class="shrink-0 xl:max-w-xs xl:text-right">
                                    @if($order->asset->identifiers->isEmpty())
                                        <span class="text-xs text-slate-600">Sin identificador técnico</span>
                                    @else
                                        <div class="flex flex-wrap gap-2 xl:justify-end">
                                            @foreach($order->asset->identifiers->take(3) as $identifier)
                                                <span class="rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 font-mono text-xs text-slate-300">{{ $identifier->identifier_type->label() }}: {{ $identifier->value }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <p class="mt-3 text-xs font-semibold text-cyan-300">Abrir expediente →</p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                @if($orders->hasPages())
                    <div class="border-t border-slate-800 px-5 py-4">{{ $orders->links() }}</div>
                @endif
            @endif
        </section>

        <div class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 px-5 py-4 text-sm leading-6 text-slate-400">
            El IMEI, número de serie, VIN, patente u otro identificador permite reencontrar el historial del mismo equipo cuando vuelve a ingresar, incluso con una nueva orden.
        </div>
    </div>
</x-app-layout>
