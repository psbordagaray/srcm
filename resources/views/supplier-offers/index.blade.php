<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Operación privada
                </p>
                <h1 class="mt-1 text-2xl font-bold text-white">
                    Ofertas de proveedores
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    El costo y la disponibilidad son datos informados por proveedores; no representan existencias propias.
                </p>
            </div>

            @can('manage-commerce')
                <a
                    href="{{ route('supplier-offers.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nueva oferta
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
                    action="{{ route('supplier-offers.index') }}"
                    class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px_160px_auto]"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Proveedor, SKU, producto, código o descripción..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <select
                        name="availability"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Toda disponibilidad</option>
                        @foreach ($availabilityOptions as $value => $label)
                            <option value="{{ $value }}" @selected($availability === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>

                    <select
                        name="status"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todo estado</option>
                        <option value="active" @selected($status === 'active')>Activas</option>
                        <option value="inactive" @selected($status === 'inactive')>Inactivas</option>
                    </select>

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Buscar
                        </button>

                        @if ($search !== '' || $availability !== '' || $status !== '')
                            <a
                                href="{{ route('supplier-offers.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            @if ($offers->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron ofertas.
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        Todavía no se registraron ofertas de proveedores.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Proveedor</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Producto</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Costo y disponibilidad</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">Verificación</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">Acciones</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            @foreach ($offers as $offer)
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-6 py-4">
                                        <a
                                            href="{{ route('suppliers.show', $offer->supplier) }}"
                                            class="font-semibold text-white hover:text-cyan-300"
                                        >
                                            {{ $offer->supplier->party->name }}
                                        </a>
                                        <p class="mt-1 font-mono text-xs text-slate-500">
                                            {{ $offer->supplier_code ?: 'Sin código propio' }}
                                        </p>
                                    </td>

                                    <td class="px-6 py-4">
                                        <a
                                            href="{{ route('products.show', $offer->product) }}"
                                            class="font-semibold text-cyan-300 hover:text-cyan-200"
                                        >
                                            {{ $offer->product->name }}
                                        </a>
                                        <p class="mt-1 font-mono text-xs text-slate-500">
                                            {{ $offer->product->sku }}
                                        </p>
                                    </td>

                                    <td class="px-6 py-4 text-sm">
                                        <p class="font-semibold text-slate-200">
                                            @if ($offer->cost_amount !== null)
                                                {{ $offer->currency }} {{ number_format((float) $offer->cost_amount, 2, ',', '.') }}
                                            @else
                                                Costo no informado
                                            @endif
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $offer->availabilityLabel() }}
                                        </p>
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        {{ $offer->checked_at->format('d/m/Y') }}
                                        <p class="mt-1 text-xs {{ $offer->active ? 'text-emerald-300' : 'text-slate-500' }}">
                                            {{ $offer->active ? 'Oferta activa' : 'Oferta inactiva' }}
                                        </p>
                                    </td>

                                    <td class="px-6 py-4">
                                        <div class="flex justify-end gap-2">
                                            <a
                                                href="{{ route('supplier-offers.show', $offer) }}"
                                                class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300"
                                            >
                                                Ver
                                            </a>

                                            @can('manage-commerce')
                                                <a
                                                    href="{{ route('supplier-offers.edit', $offer) }}"
                                                    class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300"
                                                >
                                                    Editar
                                                </a>

                                                <form method="POST" action="{{ route('supplier-offers.toggle-active', $offer) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button
                                                        type="submit"
                                                        class="rounded-lg border px-3 py-2 text-xs font-semibold {{ $offer->active ? 'border-amber-500/30 text-amber-300' : 'border-emerald-500/30 text-emerald-300' }}"
                                                    >
                                                        {{ $offer->active ? 'Inactivar' : 'Activar' }}
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

                @if ($offers->hasPages())
                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $offers->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
