<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Oferta de proveedor
                </p>
                <h1 class="mt-1 text-2xl font-bold text-white">
                    {{ $offer->product->name }}
                </h1>
                <p class="mt-2 text-sm text-slate-400">
                    {{ $offer->supplier->party->name }} · {{ $offer->supplier_code ?: 'Sin código propio' }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a
                    href="{{ route('supplier-offers.index') }}"
                    class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300"
                >
                    Volver
                </a>

                @can('manage-commerce')
                    <a
                        href="{{ route('supplier-offers.edit', $offer) }}"
                        class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950"
                    >
                        Editar oferta
                    </a>
                @endcan
            </div>
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

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 lg:col-span-2">
                <h2 class="text-lg font-bold text-white">Información publicada</h2>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Proveedor</dt>
                        <dd class="mt-1 text-sm">
                            <a href="{{ route('suppliers.show', $offer->supplier) }}" class="font-semibold text-cyan-300">
                                {{ $offer->supplier->party->name }}
                            </a>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Producto maestro</dt>
                        <dd class="mt-1 text-sm">
                            <a href="{{ route('products.show', $offer->product) }}" class="font-semibold text-cyan-300">
                                {{ $offer->product->sku }} — {{ $offer->product->name }}
                            </a>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Costo informado</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-200">
                            @if ($offer->cost_amount !== null)
                                {{ $offer->currency }} {{ number_format((float) $offer->cost_amount, 2, ',', '.') }}
                            @else
                                No informado
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Disponibilidad</dt>
                        <dd class="mt-1 text-sm text-slate-200">
                            {{ $offer->availabilityLabel() }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fecha de verificación</dt>
                        <dd class="mt-1 text-sm text-slate-200">
                            {{ $offer->checked_at->format('d/m/Y') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Estado</dt>
                        <dd class="mt-1 text-sm {{ $offer->active ? 'text-emerald-300' : 'text-slate-400' }}">
                            {{ $offer->active ? 'Oferta activa' : 'Oferta inactiva' }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-6 border-t border-slate-800 pt-6">
                    <h3 class="text-sm font-semibold text-slate-300">Descripción del proveedor</h3>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-400">
                        {{ $offer->published_description ?: 'No informada.' }}
                    </p>
                </div>

                <div class="mt-6 border-t border-slate-800 pt-6">
                    <h3 class="text-sm font-semibold text-slate-300">Condiciones comerciales</h3>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-400">
                        {{ $offer->commercial_terms ?: 'Sin condiciones registradas.' }}
                    </p>
                </div>
            </section>

            <div class="space-y-6">
                <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Fuente</h2>

                    @if ($offer->source_url)
                        <a
                            href="{{ $offer->source_url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-4 block break-all text-sm font-semibold text-cyan-300"
                        >
                            {{ $offer->source_url }}
                        </a>
                    @else
                        <p class="mt-4 text-sm text-slate-400">Sin URL registrada.</p>
                    @endif
                </section>

                <section class="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-6">
                    <h2 class="font-bold text-amber-200">No es inventario</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        La disponibilidad pertenece al proveedor. El stock propio se generará mediante compras y movimientos de inventario.
                    </p>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
