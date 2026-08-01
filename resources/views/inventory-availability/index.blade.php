<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Inventario
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Disponibilidad
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Lectura derivada del libro confirmado dentro de la organización activa.
                </p>
            </div>

            <a
                href="{{ route('inventory-locations.index') }}"
                class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-cyan-400/50 hover:text-white"
            >
                Ver ubicaciones
            </a>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Posiciones físicas
                </p>
                <p class="mt-2 text-3xl font-bold text-white">
                    {{ $summary['positions'] }}
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Con disponibilidad
                </p>
                <p class="mt-2 text-3xl font-bold text-emerald-300">
                    {{ $summary['available'] }}
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Con déficit
                </p>
                <p class="mt-2 text-3xl font-bold {{ $summary['deficits'] > 0 ? 'text-red-300' : 'text-slate-300' }}">
                    {{ $summary['deficits'] }}
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Ubicaciones visibles
                </p>
                <p class="mt-2 text-3xl font-bold text-cyan-300">
                    {{ $summary['locations'] }}
                </p>
            </article>
        </div>

        @if($summary['deficits'] > 0)
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                Existen {{ $summary['deficits'] }} posiciones con déficit. La disponibilidad vendible nunca se presenta como negativa.
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form
                    method="GET"
                    action="{{ route('inventory-availability.index') }}"
                    class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_13rem_13rem_12rem_auto]"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Producto, SKU o ubicación..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

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

                    <select
                        name="status"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos los saldos</option>
                        <option value="available" @selected($status === 'available')>Disponibles</option>
                        <option value="deficit" @selected($status === 'deficit')>Con déficit</option>
                        <option value="zero" @selected($status === 'zero')>En cero</option>
                        <option value="inactive" @selected($status === 'inactive')>Con dimensión inactiva</option>
                    </select>

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Filtrar
                        </button>

                        @if($search !== '' || $locationId !== null || $condition !== '' || $status !== '')
                            <a
                                href="{{ route('inventory-availability.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            @if($positions->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        Todavía no existen posiciones físicas
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        La disponibilidad aparecerá cuando existan movimientos confirmados que proyecten saldos.
                    </p>
                </div>
            @elseif($rows->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        Ninguna posición coincide con los filtros
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        Limpiá o ajustá los criterios de búsqueda.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Producto</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Ubicación</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Condición</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">Físico</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">Disponible</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">Déficit</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            @foreach($rows as $row)
                                @php($position = $row['position'])
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-white">
                                            {{ $position->productName }}
                                        </div>
                                        <div class="mt-1 font-mono text-xs text-cyan-300">
                                            {{ $position->productSku }}
                                        </div>
                                        @unless($position->productActive)
                                            <span class="mt-2 inline-flex rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-300">
                                                Producto inactivo
                                            </span>
                                        @endunless
                                    </td>

                                    <td class="px-5 py-4 text-sm text-slate-300">
                                        {{ $position->locationName }}
                                        @unless($position->locationActive)
                                            <span class="mt-2 block text-xs font-semibold text-amber-300">
                                                Ubicación inactiva
                                            </span>
                                        @endunless
                                    </td>

                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full bg-violet-500/10 px-2.5 py-1 text-xs font-semibold text-violet-300">
                                            {{ $position->condition->label() }}
                                        </span>
                                        <div class="mt-2 text-xs text-slate-500">
                                            {{ $row['unit'] }}
                                        </div>
                                    </td>

                                    <td class="px-5 py-4 text-right font-mono text-sm {{ $position->hasDeficit() ? 'font-bold text-red-300' : 'text-slate-300' }}">
                                        {{ $row['physical'] }}
                                    </td>

                                    <td class="px-5 py-4 text-right font-mono text-sm font-bold text-emerald-300">
                                        {{ $row['available'] }}
                                    </td>

                                    <td class="px-5 py-4 text-right font-mono text-sm {{ $position->hasDeficit() ? 'font-bold text-red-300' : 'text-slate-600' }}">
                                        {{ $row['deficit'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-800 px-6 py-4 text-xs text-slate-500">
                    {{ $rows->count() }} de {{ $positions->count() }} posiciones visibles con los filtros actuales.
                </div>
            @endif
        </section>

        <div class="rounded-2xl border border-cyan-400/10 bg-cyan-400/[0.03] px-5 py-4 text-sm text-slate-400">
            Físico es la proyección exacta del libro confirmado. Disponible nunca baja de cero; cualquier diferencia negativa se muestra separadamente como déficit.
        </div>
    </div>
</x-app-layout>
