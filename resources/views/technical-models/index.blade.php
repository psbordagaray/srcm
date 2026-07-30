<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo conectado
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Modelos técnicos
                </h1>

                <p class="mt-2 max-w-3xl text-sm text-slate-400">
                    Cada modelo está vinculado con una ficha de conocimiento.
                    Desde esa ficha se administran códigos y compatibilidades.
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

        @if (session('error'))
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif

        @error('technical_model_action')
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $message }}
            </div>
        @enderror

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
                        {{ $search !== ''
                            ? 'Probá con otro criterio de búsqueda.'
                            : 'Creá el primer modelo técnico conectado.' }}
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
                                    Conocimiento
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
                            @foreach ($technicalModels as $technicalModel)
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-6 py-4">
                                        @if ($technicalModel->knowledgeEntity)
                                            <a
                                                href="{{ route('technical-models.show', $technicalModel) }}"
                                                class="font-mono font-bold text-cyan-300 transition hover:text-cyan-200"
                                            >
                                                {{ $technicalModel->code }}
                                            </a>
                                        @else
                                            <div class="font-mono font-bold text-white">
                                                {{ $technicalModel->code }}
                                            </div>
                                        @endif

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
                                        @if ($technicalModel->knowledgeEntity)
                                            <span class="inline-flex rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">
                                                Ficha vinculada
                                            </span>

                                            <div class="mt-2 max-w-xs text-xs text-slate-500">
                                                {{ $technicalModel->knowledgeEntity->name }}
                                            </div>
                                        @else
                                            <span class="inline-flex rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-300">
                                                Sin vincular
                                            </span>
                                        @endif
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

                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if ($technicalModel->knowledgeEntity)
                                                <a
                                                    href="{{ route('technical-models.show', $technicalModel) }}"
                                                    class="rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-1.5 text-xs font-semibold text-cyan-300 transition hover:bg-cyan-500/20"
                                                >
                                                    Abrir ficha
                                                </a>
                                            @endif

                                            @can('manage-catalog')
                                                <a
                                                    href="{{ route('technical-models.edit', $technicalModel) }}"
                                                    class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
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
                                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ $technicalModel->active
                                                            ? 'border-amber-500/30 bg-amber-500/10 text-amber-300 hover:bg-amber-500/20'
                                                            : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20' }}"
                                                    >
                                                        {{ $technicalModel->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
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
