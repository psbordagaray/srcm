<x-app-layout>

    <div class="space-y-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Marcas
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Administrá las marcas oficiales utilizadas por el catálogo maestro de SRCM.
                </p>

            </div>

            <a
                href="{{ route('brands.create') }}"
                class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
            >
                Nueva marca
            </a>

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
                    action="{{ route('brands.index') }}"
                    class="flex flex-col gap-3 sm:flex-row"
                >

                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Buscar marca..."
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
                                href="{{ route('brands.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>

                        @endif

                    </div>

                </form>

            </div>

            @if ($brands->isEmpty())

                <div class="px-6 py-16 text-center">

                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron marcas.
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">

                        @if ($search !== '')

                            Probá con otro criterio de búsqueda.

                        @else

                            Creá la primera marca para comenzar.

                        @endif

                    </p>

                </div>

            @else

                <div class="overflow-x-auto">

                    <table class="min-w-full divide-y divide-slate-800">

                        <thead class="bg-slate-950/60">

                            <tr>

                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Marca
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

                            @foreach ($brands as $brand)

                                <tr class="transition hover:bg-slate-800/40">

                                    <td class="px-6 py-4">

                                        <div class="font-semibold text-white">
                                            {{ $brand->name }}
                                        </div>

                                        @if ($brand->description)

                                            <div class="mt-1 max-w-xl text-sm text-slate-400">
                                                {{ \Illuminate\Support\Str::limit($brand->description, 90) }}
                                            </div>

                                        @endif

                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-400">
                                        {{ $brand->website ?: '—' }}
                                    </td>

                                    <td class="px-6 py-4">

                                        @if ($brand->active)

                                            <span class="inline-flex rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                Activa
                                            </span>

                                        @else

                                            <span class="inline-flex rounded-full bg-slate-700 px-2.5 py-1 text-xs font-semibold text-slate-300">
                                                Inactiva
                                            </span>

                                        @endif

                                    </td>

                                    <td class="px-6 py-4 text-right space-x-3">

                                        <a
                                            href="{{ route('brands.edit', $brand) }}"
                                            class="text-sm font-semibold text-cyan-400 hover:text-cyan-300"
                                        >
                                            Editar
                                        </a>

                                        <form
                                            action="{{ route('brands.toggle-active', $brand) }}"
                                            method="POST"
                                            class="inline"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <button
                                                type="submit"
                                                class="text-sm font-semibold {{ $brand->active ? 'text-amber-400 hover:text-amber-300' : 'text-emerald-400 hover:text-emerald-300' }}"
                                            >
                                                {{ $brand->active ? 'Inactivar' : 'Activar' }}
                                            </button>

                                        </form>

                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

                @if ($brands->hasPages())

                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $brands->links() }}
                    </div>

                @endif

            @endif

        </section>

    </div>

</x-app-layout>