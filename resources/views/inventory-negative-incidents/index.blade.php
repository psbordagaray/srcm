<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-red-300">
                    Inventario · Control APB
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Stock negativo
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Seguimiento administrativo de solicitudes, Overrides e incidencias dentro de la organización activa.
                </p>
            </div>

            <a
                href="{{ route('inventory-availability.index', ['status' => 'deficit']) }}"
                class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-cyan-400/50 hover:text-white"
            >
                Ver disponibilidad con déficit
            </a>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Solicitudes pendientes
                </p>
                <p
                    data-testid="pending-requests"
                    class="mt-2 text-3xl font-bold {{ $summary['pendingRequests'] > 0 ? 'text-amber-300' : 'text-slate-300' }}"
                >
                    {{ $summary['pendingRequests'] }}
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Overrides activos
                </p>
                <p
                    data-testid="active-overrides"
                    class="mt-2 text-3xl font-bold {{ $summary['activeOverrides'] > 0 ? 'text-cyan-300' : 'text-slate-300' }}"
                >
                    {{ $summary['activeOverrides'] }}
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Incidencias activas
                </p>
                <p
                    data-testid="active-incidents"
                    class="mt-2 text-3xl font-bold {{ $summary['activeIncidents'] > 0 ? 'text-red-300' : 'text-slate-300' }}"
                >
                    {{ $summary['activeIncidents'] }}
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Líneas por regularizar
                </p>
                <p
                    data-testid="pending-lines"
                    class="mt-2 text-3xl font-bold {{ $summary['pendingLines'] > 0 ? 'text-red-300' : 'text-emerald-300' }}"
                >
                    {{ $summary['pendingLines'] }}
                </p>
            </article>
        </div>

        @if($summary['pendingRequests'] > 0 || $summary['activeOverrides'] > 0)
            <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                Hay decisiones excepcionales pendientes. Un Override activo autoriza únicamente al usuario, movimiento y fotografía de saldo registrados.
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form
                    method="GET"
                    action="{{ route('inventory-negative-incidents.index') }}"
                    class="grid gap-3 2xl:grid-cols-[minmax(0,1fr)_12rem_13rem_13rem_13rem_auto]"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Incidencia, producto, SKU o ubicación..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <select
                        name="status"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos los estados</option>
                        @foreach($statusOptions as $option)
                            <option
                                value="{{ $option['value'] }}"
                                @selected($status === $option['value'])
                            >
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>

                    <select
                        name="attention"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Toda regularización</option>
                        <option value="pending" @selected($attention === 'pending')>Con déficit pendiente</option>
                        <option value="regularized" @selected($attention === 'regularized')>Sin déficit pendiente</option>
                    </select>

                    <select
                        name="location"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todas las ubicaciones</option>
                        @foreach($locations as $location)
                            <option
                                value="{{ $location['id'] }}"
                                @selected($locationId === $location['id'])
                            >
                                {{ $location['name'] }}
                            </option>
                        @endforeach
                    </select>

                    <select
                        name="condition"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todas las condiciones</option>
                        @foreach($conditions as $inventoryCondition)
                            <option
                                value="{{ $inventoryCondition->value }}"
                                @selected($condition === $inventoryCondition->value)
                            >
                                {{ $inventoryCondition->label() }}
                            </option>
                        @endforeach
                    </select>

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Filtrar
                        </button>

                        @if($search !== '' || $status !== '' || $attention !== '' || $locationId !== null || $condition !== '')
                            <a
                                href="{{ route('inventory-negative-incidents.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            @if($incidents->total() === 0 && $search === '' && $status === '' && $attention === '' && $locationId === null && $condition === '')
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        Todavía no existen incidencias de stock negativo
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        Una incidencia aparecerá cuando un movimiento consuma un Override y deje una posición física negativa.
                    </p>
                </div>
            @elseif($incidents->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        Ninguna incidencia coincide con los filtros
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        Limpiá o ajustá los criterios de búsqueda.
                    </p>
                </div>
            @else
                <div class="divide-y divide-slate-800">
                    @foreach($incidents as $row)
                        @php($incident = $row['incident'])
                        <article class="p-5 transition hover:bg-slate-800/20">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-bold text-cyan-300">
                                            #{{ $row['shortId'] }}
                                        </span>
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $row['statusClass'] }}">
                                            {{ $row['statusLabel'] }}
                                        </span>
                                        @if($row['pendingLines'] > 0)
                                            <span class="inline-flex rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-semibold text-red-300">
                                                {{ $row['pendingLines'] }} {{ $row['pendingLines'] === 1 ? 'línea pendiente' : 'líneas pendientes' }}
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                Déficit regularizado
                                            </span>
                                        @endif
                                    </div>

                                    <p class="mt-3 max-w-4xl text-sm leading-6 text-slate-300">
                                        {{ $incident->reason }}
                                    </p>
                                </div>

                                <dl class="grid shrink-0 grid-cols-2 gap-x-6 gap-y-2 text-xs xl:text-right">
                                    <div>
                                        <dt class="text-slate-500">Apertura</dt>
                                        <dd class="mt-1 font-semibold text-slate-300">{{ $row['openedAt'] }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-slate-500">Movimiento</dt>
                                        <dd class="mt-1 font-mono font-semibold text-slate-300">#{{ $incident->inventory_movement_id }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-slate-500">Solicitó</dt>
                                        <dd class="mt-1 font-semibold text-slate-300">{{ $incident->requestedBy?->name ?? 'Usuario ausente' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-slate-500">Autorizó</dt>
                                        <dd class="mt-1 font-semibold text-slate-300">{{ $incident->grantedBy?->name ?? 'Usuario ausente' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="mt-5 overflow-x-auto rounded-xl border border-slate-800">
                                <table class="min-w-full divide-y divide-slate-800">
                                    <thead class="bg-slate-950/60">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Producto</th>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Ubicación</th>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Condición</th>
                                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Déficit originado</th>
                                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pendiente</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800">
                                        @foreach($row['lines'] as $lineRow)
                                            <tr>
                                                <td class="px-4 py-3">
                                                    <div class="text-sm font-semibold text-white">{{ $lineRow['productName'] }}</div>
                                                    <div class="mt-1 font-mono text-xs text-cyan-300">{{ $lineRow['productSku'] }}</div>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-slate-300">{{ $lineRow['locationName'] }}</td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex rounded-full bg-violet-500/10 px-2.5 py-1 text-xs font-semibold text-violet-300">
                                                        {{ $lineRow['condition'] }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-right font-mono text-sm text-slate-300">
                                                    {{ $lineRow['incrementalDeficit'] }}
                                                    <span class="ml-1 text-xs text-slate-500">{{ $lineRow['unit'] }}</span>
                                                </td>
                                                <td class="px-4 py-3 text-right font-mono text-sm font-bold {{ $lineRow['pending'] ? 'text-red-300' : 'text-emerald-300' }}">
                                                    {{ $lineRow['pendingDeficit'] }}
                                                    <span class="ml-1 text-xs font-normal text-slate-500">{{ $lineRow['unit'] }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if($incidents->hasPages())
                    <div class="border-t border-slate-800 px-5 py-4">
                        {{ $incidents->links() }}
                    </div>
                @endif

                <div class="border-t border-slate-800 px-6 py-4 text-xs text-slate-500">
                    {{ $incidents->total() }} incidencias coinciden con los filtros actuales. Se muestran hasta 20 por página.
                </div>
            @endif
        </section>

        <div class="rounded-2xl border border-red-400/10 bg-red-400/[0.03] px-5 py-4 text-sm text-slate-400">
            Esta pantalla es de control y no modifica el libro. La regularización se imputa automáticamente en orden FIFO cuando ingresan movimientos confirmados compatibles; el cierre administrativo permanece separado.
        </div>
    </div>
</x-app-layout>
