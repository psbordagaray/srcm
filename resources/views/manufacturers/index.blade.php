<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo maestro
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Fabricantes
                </h1>

                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    El fabricante es responsable de producir física o técnicamente
                    un artículo. Una marca no necesariamente fabrica lo que comercializa,
                    y tampoco representa al importador o al proveedor.
                </p>
            </div>

            @can('manage-catalog')
                <a
                    href="{{ route('manufacturers.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nuevo fabricante
                </a>
            @else
                <span class="inline-flex rounded-full border border-slate-700 bg-slate-800/60 px-3 py-1.5 text-xs font-semibold text-slate-300">
                    Consulta
                </span>
            @endcan
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form
                    method="GET"
                    action="{{ route('manufacturers.index') }}"
                    class="flex flex-col gap-3 sm:flex-row"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Buscar por nombre, sitio web o descripción..."
                        class="min-w-0 flex-1 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Buscar
                        </button>

                        @if ($search !== '')
                            <a
                                href="{{ route('manufacturers.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            @if ($manufacturers->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron fabricantes.
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        {{ $search !== ''
                            ? 'Probá con otro criterio de búsqueda.'
                            : 'Todavía no se registraron fabricantes.' }}
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Fabricante
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Sitio web
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Estado
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Acciones
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            @foreach ($manufacturers as $manufacturer)
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-white">
                                            {{ $manufacturer->name }}
                                        </div>

                                        @if ($manufacturer->description)
                                            <div class="mt-1 max-w-xl text-sm text-slate-400">
                                                {{ $manufacturer->description }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm">
                                        @if ($manufacturer->website)
                                            <a
                                                href="{{ $manufacturer->website }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-cyan-300 transition hover:text-cyan-200"
                                            >
                                                {{ $manufacturer->website }}
                                            </a>
                                        @else
                                            <span class="text-slate-600">
                                                Sin sitio registrado
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4">
                                        @if ($manufacturer->active)
                                            <span class="inline-flex rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                Activo
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-semibold text-red-300">
                                                Inactivo
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('manage-catalog')
                                                <a
                                                    href="{{ route('manufacturers.edit', $manufacturer) }}"
                                                    class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                >
                                                    Editar
                                                </a>

                                                <form
                                                    method="POST"
                                                    action="{{ route('manufacturers.toggle-active', $manufacturer) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ $manufacturer->active
                                                            ? 'border-amber-500/30 bg-amber-500/10 text-amber-300 hover:bg-amber-500/20'
                                                            : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20' }}"
                                                    >
                                                        {{ $manufacturer->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-xs text-slate-600">
                                                    Solo lectura
                                                </span>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($manufacturers->hasPages())
                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $manufacturers->links() }}
                    </div>
                @endif
            @endif
        </section>

        <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
            <strong>Regla SRCM:</strong>
            una marca no necesariamente fabrica el artículo y un proveedor
            solamente publica una oferta comercial sobre él.
        </div>
    </div>
</x-app-layout>
