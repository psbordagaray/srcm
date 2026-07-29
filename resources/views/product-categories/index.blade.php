<x-app-layout>
    <div class="space-y-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Categorías de productos
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Organizá los productos de controles remotos, computación, celulares y accesorios.
                </p>
            </div>

            @can('manage-catalog')
            <a
                href="{{ route('product-categories.create') }}"
                class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
            >
                Nueva categoría
            </a>
            @endcan

        </div>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">

            <div class="border-b border-slate-800 p-4">

                <form
                    method="GET"
                    action="{{ route('product-categories.index') }}"
                    class="flex flex-col gap-3 sm:flex-row"
                >

                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Buscar por nombre, slug o descripción..."
                        class="min-w-0 flex-1 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <div class="flex gap-2">

                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Buscar
                        </button>

                        @if($search !== '')
                            <a
                                href="{{ route('product-categories.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif

                    </div>

                </form>

            </div>

            @if($categories->isEmpty())

                <div class="px-6 py-16 text-center">

                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron categorías
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        @if($search !== '')
                            Probá con otro término de búsqueda.
                        @else
                            Creá la primera categoría para comenzar a organizar el catálogo.
                        @endif
                    </p>

                </div>

            @else

                <div class="overflow-x-auto">

                    <table class="min-w-full divide-y divide-slate-800">

                        <thead class="bg-slate-950/60">

                            <tr>

                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Categoría
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Slug
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

                            @foreach($categories as $category)

                                <tr class="transition hover:bg-slate-800/40">

                                    <td class="px-6 py-4">

                                        <div class="font-semibold text-white">
                                            {{ $category->name }}
                                        </div>

                                        @if($category->description)
                                            <div class="mt-1 max-w-xl text-sm text-slate-400">
                                                {{ \Illuminate\Support\Str::limit($category->description,90) }}
                                            </div>
                                        @endif

                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-400">
                                        {{ $category->slug }}
                                    </td>

                                    <td class="px-6 py-4">

                                        @if($category->active)

                                            <span class="inline-flex rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                Activa
                                            </span>

                                        @else

                                            <span class="inline-flex rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-semibold text-red-300">
                                                Inactiva
                                            </span>

                                        @endif

                                    </td>

                                    @can('manage-catalog')
                                    <td class="px-6 py-4">

                                        <div class="flex justify-end gap-2">

                                            <a
                                                href="{{ route('product-categories.edit',$category) }}"
                                                class="rounded-lg border border-cyan-500 px-3 py-1 text-xs font-semibold text-cyan-300 hover:bg-cyan-500/10"
                                            >
                                                Editar
                                            </a>

                                            <form
                                                action="{{ route('product-categories.toggle-active',$category) }}"
                                                method="POST"
                                            >

                                                @csrf
                                                @method('PATCH')

                                                <button
                                                    type="submit"
                                                    onclick="return confirm('¿Desea cambiar el estado de esta categoría?')"
                                                    class="rounded-lg border border-amber-500 px-3 py-1 text-xs font-semibold text-amber-300 hover:bg-amber-500/10"
                                                >

                                                    @if($category->active)
                                                        Inactivar
                                                    @else
                                                        Activar
                                                    @endif

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

                @if($categories->hasPages())

                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $categories->links() }}
                    </div>

                @endif

            @endif

        </section>

    </div>
</x-app-layout>
