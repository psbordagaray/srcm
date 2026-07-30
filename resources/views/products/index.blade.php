<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo maestro
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Productos
                </h1>

                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    Cada registro representa un artículo maestro único. Su precio,
                    stock, proveedor, condición y ubicación pertenecerán a futuras
                    ofertas privadas, no a esta identidad global.
                </p>
            </div>

            @can('manage-catalog')
                <a
                    href="{{ route('products.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nuevo producto
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

        @if (session('error'))
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form
                    method="GET"
                    action="{{ route('products.index') }}"
                    class="flex flex-col gap-3 sm:flex-row"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Buscar por SKU, nombre, marca, fabricante o categoría..."
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
                                href="{{ route('products.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            @if ($products->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron productos.
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        {{ $search !== ''
                            ? 'Probá con otro criterio de búsqueda.'
                            : 'Todavía no se registraron artículos maestros.' }}
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Producto
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Clasificación
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
                            @foreach ($products as $product)
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-white">
                                            {{ $product->name }}
                                        </div>

                                        <div class="mt-1 font-mono text-xs text-cyan-300">
                                            {{ $product->sku }}
                                        </div>

                                        @if ($product->description)
                                            <div class="mt-2 max-w-xl text-sm text-slate-400">
                                                {{ $product->description }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        <div>{{ $product->productCategory->name }}</div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            Marca: {{ $product->brand?->name ?? 'Sin marca' }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            Fabricante: {{ $product->manufacturer?->name ?? 'No informado' }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-sm">
                                        @if ($product->knowledgeEntity && $product->knowledgeIdentifier)
                                            <a
                                                href="{{ route('products.show', $product) }}"
                                                class="font-semibold text-cyan-300 transition hover:text-cyan-200"
                                            >
                                                Abrir ficha
                                            </a>

                                            <div class="mt-1 text-xs text-slate-600">
                                                {{ $product->knowledgeEntity->entityType?->name }}
                                            </div>
                                        @else
                                            <span class="text-red-300">
                                                Vínculo incompleto
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4">
                                        @if ($product->active)
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
                                            <a
                                                href="{{ route('products.show', $product) }}"
                                                class="rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-1.5 text-xs font-semibold text-cyan-300 transition hover:bg-cyan-500/20"
                                            >
                                                Ficha
                                            </a>

                                            @can('manage-catalog')
                                                <a
                                                    href="{{ route('products.edit', $product) }}"
                                                    class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                >
                                                    Editar
                                                </a>

                                                <form
                                                    method="POST"
                                                    action="{{ route('products.toggle-active', $product) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ $product->active
                                                            ? 'border-amber-500/30 bg-amber-500/10 text-amber-300 hover:bg-amber-500/20'
                                                            : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20' }}"
                                                    >
                                                        {{ $product->active ? 'Inactivar' : 'Activar' }}
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

                @if ($products->hasPages())
                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $products->links() }}
                    </div>
                @endif
            @endif
        </section>

        <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
            <strong>Regla APB:</strong>
            antes de crear un artículo, SRCM compara SKU y nombre normalizados.
            Los códigos alternativos y las compatibilidades se agregan desde la ficha.
        </div>
    </div>
</x-app-layout>
