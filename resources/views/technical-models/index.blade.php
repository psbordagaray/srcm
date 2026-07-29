<x-app-layout>

    <div class="space-y-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Modelos técnicos
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Identidades técnicas asociadas a marcas y categorías del catálogo maestro.
                </p>
            </div>

            @can('manage-catalog')
            <a
                href="{{ route('technical-models.create') }}"
                class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
            >
                Nuevo modelo
            </a>
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
                    action="{{ route('technical-models.index') }}"
                    class="flex flex-col gap-3 sm:flex-row"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Buscar por código, nombre, marca o categoría..."
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
                                href="{{ route('technical-models.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>

            </div>

            @if ($technicalModels->isEmpty())

                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron modelos técnicos.
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        {{ $search !== '' ? 'Probá con otro criterio de búsqueda.' : 'Creá el primer modelo técnico del catálogo.' }}
                    </p>
                </div>

            @else

                <div class="overflow-x-auto">

                    <table class="min-w-full divide-y divide-slate-800">

                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Modelo
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Marca
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Categoría
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Estado
                                </th>

                                @can('manage-catalog')
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Acciones
                                </th>
                                @endcan
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">

                            @foreach ($technicalModels as $technicalModel)
                                <tr class="transition hover:bg-slate-800/40">

                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-white">
                                            {{ $technicalModel->code }}
                                        </div>

                                        @if ($technicalModel->name)
                                            <div class="mt-1 text-sm text-slate-400">
                                                {{ $technicalModel->name }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        {{ $technicalModel->brand->name }}
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        {{ $technicalModel->productCategory->name }}
                                    </td>

                                    <td class="px-6 py-4">
                                        @if ($technicalModel->active)
                                            <span class="inline-flex rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                Activo
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-semibold text-red-300">
                                                Inactivo
                                            </span>
                                        @endif
                                    </td>

                                    @can('manage-catalog')
                                    <td class="px-6 py-4">
                                        <div class="flex justify-end gap-2">

                                            <a
                                                href="{{ route('technical-models.edit', $technicalModel) }}"
                                                class="rounded-lg border border-cyan-500 px-3 py-1 text-xs font-semibold text-cyan-300 transition hover:bg-cyan-500/10"
                                            >
                                                Editar
                                            </a>

                                            <form
                                                action="{{ route('technical-models.toggle-active', $technicalModel) }}"
                                                method="POST"
                                            >
                                                @csrf
                                                @method('PATCH')

                                                <button
                                                    type="submit"
                                                    onclick="return confirm('¿Desea cambiar el estado de este modelo técnico?')"
                                                    class="rounded-lg border px-3 py-1 text-xs font-semibold transition {{ $technicalModel->active ? 'border-amber-500 text-amber-300 hover:bg-amber-500/10' : 'border-emerald-500 text-emerald-300 hover:bg-emerald-500/10' }}"
                                                >
                                                    {{ $technicalModel->active ? 'Inactivar' : 'Activar' }}
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

                @if ($technicalModels->hasPages())
                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $technicalModels->links() }}
                    </div>
                @endif

            @endif

        </section>

    </div>

</x-app-layout>
