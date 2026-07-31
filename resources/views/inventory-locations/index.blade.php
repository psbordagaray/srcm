<x-app-layout>
    <div class="space-y-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Inventario
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Ubicaciones
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Encontrá cada artículo mediante una jerarquía privada de la organización activa.
                </p>
            </div>

            @can('manage-inventory-locations')
                <a
                    href="{{ route('inventory-locations.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nueva ubicación
                </a>
            @endcan

        </div>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">

            <div class="border-b border-slate-800 p-4">

                <form
                    method="GET"
                    action="{{ route('inventory-locations.index') }}"
                    class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_13rem_11rem_auto]"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Buscar nombre o camino completo..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <select
                        name="type"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos los tipos</option>

                        @foreach($types as $locationType)
                            <option
                                value="{{ $locationType->value }}"
                                @selected($type === $locationType->value)
                            >
                                {{ $locationType->label() }}
                            </option>
                        @endforeach
                    </select>

                    <select
                        name="status"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos los estados</option>
                        <option value="active" @selected($status === 'active')>
                            Activas
                        </option>
                        <option value="inactive" @selected($status === 'inactive')>
                            Inactivas
                        </option>
                    </select>

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Filtrar
                        </button>

                        @if($search !== '' || $type !== '' || $status !== '')
                            <a
                                href="{{ route('inventory-locations.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>

            </div>

            @if($locationRows->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron ubicaciones
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        @if($search !== '' || $type !== '' || $status !== '')
                            Probá con otros filtros.
                        @else
                            Creá la primera ubicación para representar el espacio físico real.
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Ubicación
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Tipo
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Estado
                                </th>
                                @can('manage-inventory-locations')
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">
                                        Acciones
                                    </th>
                                @endcan
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            @foreach($locationRows as $row)
                                @php($location = $row['location'])

                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-6 py-4">
                                        <div
                                            class="flex items-center gap-2"
                                            style="padding-left: {{ min($row['depth'], 8) * 1.25 }}rem"
                                        >
                                            @if($row['depth'] > 0)
                                                <span class="text-slate-600">↳</span>
                                            @endif

                                            <span class="font-semibold text-white">
                                                {{ $location->name }}
                                            </span>
                                        </div>

                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $row['path'] }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        {{ $location->type->label() }}
                                    </td>

                                    <td class="px-6 py-4">
                                        @if($location->active)
                                            <span class="inline-flex rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                Activa
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-semibold text-red-300">
                                                Inactiva
                                            </span>
                                        @endif
                                    </td>

                                    @can('manage-inventory-locations')
                                        <td class="px-6 py-4">
                                            <div class="flex justify-end gap-2">
                                                <a
                                                    href="{{ route('inventory-locations.edit', $location) }}"
                                                    class="rounded-lg border border-cyan-500 px-3 py-1 text-xs font-semibold text-cyan-300 hover:bg-cyan-500/10"
                                                >
                                                    Editar
                                                </a>

                                                <form
                                                    action="{{ route('inventory-locations.toggle-active', $location) }}"
                                                    method="POST"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        onclick="return confirm('{{ $location->active ? 'Solo puede inactivarse si no contiene ubicaciones activas. ¿Continuar?' : '¿Desea activar esta ubicación?' }}')"
                                                        class="rounded-lg border border-amber-500 px-3 py-1 text-xs font-semibold text-amber-300 hover:bg-amber-500/10"
                                                    >
                                                        {{ $location->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-800 px-6 py-4 text-xs text-slate-500">
                    {{ $locationRows->count() }} ubicaciones visibles con los filtros actuales.
                </div>
            @endif

        </section>

        <div class="rounded-2xl border border-cyan-400/10 bg-cyan-400/[0.03] px-5 py-4 text-sm text-slate-400">
            Las ubicaciones describen dónde puede encontrarse mercadería. En este bloque todavía no contienen cantidades, costos ni disponibilidad.
        </div>

    </div>
</x-app-layout>
