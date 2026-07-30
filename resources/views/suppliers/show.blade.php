<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Proveedor
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    {{ $supplier->party->name }}
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    {{ $supplier->party->party_type === 'organization'
                        ? 'Empresa u organización'
                        : 'Persona' }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a
                    href="{{ route('suppliers.index') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                >
                    Volver
                </a>

                @can('manage-commerce')
                    <a
                        href="{{ route('suppliers.edit', $supplier) }}"
                        class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Editar proveedor
                    </a>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 lg:col-span-2">
                <h2 class="text-lg font-bold text-white">
                    Identidad y contacto
                </h2>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Identificación fiscal
                        </dt>
                        <dd class="mt-1 font-mono text-sm text-slate-200">
                            {{ $supplier->party->tax_id ?? 'No informada' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Correo
                        </dt>
                        <dd class="mt-1 text-sm text-slate-200">
                            {{ $supplier->party->email ?? 'No informado' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Teléfono
                        </dt>
                        <dd class="mt-1 text-sm text-slate-200">
                            {{ $supplier->party->phone ?? 'No informado' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Sitio web
                        </dt>
                        <dd class="mt-1 break-all text-sm text-slate-200">
                            @if ($supplier->party->website)
                                <a
                                    href="{{ $supplier->party->website }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-cyan-300 hover:text-cyan-200"
                                >
                                    {{ $supplier->party->website }}
                                </a>
                            @else
                                No informado
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="mt-6 border-t border-slate-800 pt-6">
                    <h3 class="text-sm font-semibold text-slate-300">
                        Nota interna
                    </h3>

                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-400">
                        {{ $supplier->notes ?: 'Sin notas internas.' }}
                    </p>
                </div>
            </section>

            <div class="space-y-6">
                <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">
                        Estado
                    </h2>

                    <span class="mt-4 inline-flex rounded-full px-3 py-1.5 text-sm font-semibold {{ $supplier->active
                        ? 'bg-emerald-400/10 text-emerald-300'
                        : 'bg-slate-700/70 text-slate-300' }}">
                        {{ $supplier->active ? 'Proveedor activo' : 'Proveedor inactivo' }}
                    </span>
                </section>

                <section class="rounded-2xl border border-cyan-500/20 bg-cyan-500/10 p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="font-bold text-cyan-200">
                                Ofertas comerciales
                            </h2>

                            <p class="mt-1 text-xs text-slate-500">
                                {{ $supplier->offers_count }} registradas
                            </p>
                        </div>

                        @can('manage-commerce')
                            @if ($supplier->active)
                                <a
                                    href="{{ route('supplier-offers.create', ['supplier' => $supplier->id]) }}"
                                    class="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-bold text-slate-950"
                                >
                                    Nueva oferta
                                </a>
                            @endif
                        @endcan
                    </div>

                    @if ($supplier->offers->isEmpty())
                        <p class="mt-4 text-sm leading-6 text-slate-400">
                            Este proveedor todavía no posee ofertas vinculadas.
                        </p>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($supplier->offers as $offer)
                                <a
                                    href="{{ route('supplier-offers.show', $offer) }}"
                                    class="block rounded-xl border border-cyan-500/20 bg-slate-950/40 p-3"
                                >
                                    <p class="text-sm font-semibold text-white">
                                        {{ $offer->product->name }}
                                    </p>

                                    <p class="mt-1 font-mono text-xs text-slate-500">
                                        {{ $offer->supplier_code ?: $offer->product->sku }}
                                        ·
                                        {{ $offer->availabilityLabel() }}
                                    </p>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
