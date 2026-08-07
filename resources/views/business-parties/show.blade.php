<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Expediente de identidad
                </p>
                <h2 class="mt-1 text-2xl font-bold text-white">
                    {{ $party->name }}
                </h2>
            </div>

            <div class="flex gap-3">
                @can('manage-business-parties')
                    <a
                        href="{{ route('business-parties.edit', $party) }}"
                        class="rounded-xl bg-cyan-400 px-4 py-2 font-semibold text-slate-950"
                    >
                        Editar identidad
                    </a>
                @endcan
                <a
                    href="{{ route('business-parties.index') }}"
                    class="rounded-xl border border-slate-700 px-4 py-2 font-semibold text-slate-300"
                >
                    Volver
                </a>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5 lg:col-span-2">
                <h3 class="font-semibold text-white">Identidad y contacto</h3>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-500">Tipo</dt>
                        <dd class="mt-1 text-slate-200">
                            {{ $party->party_type === 'person' ? 'Persona' : 'Organización / empresa' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-500">Documento</dt>
                        <dd class="mt-1 text-slate-200">{{ $party->tax_id ?: 'Sin registrar' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-500">Correo</dt>
                        <dd class="mt-1 text-slate-200">{{ $party->email ?: 'Sin registrar' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-500">Teléfono</dt>
                        <dd class="mt-1 text-slate-200">{{ $party->phone ?: 'Sin registrar' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wider text-slate-500">Sitio web</dt>
                        <dd class="mt-1 text-slate-200">
                            @if ($party->website)
                                <a href="{{ $party->website }}" class="text-cyan-300 hover:text-cyan-200">
                                    {{ $party->website }}
                                </a>
                            @else
                                Sin registrar
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <h3 class="font-semibold text-white">Roles comerciales</h3>
                <div class="mt-4 space-y-3 text-sm">
                    @if ($party->customer)
                        <a
                            href="{{ route('customers.show', $party->customer) }}"
                            class="block rounded-xl border border-cyan-500/30 bg-cyan-500/5 p-3 text-cyan-200"
                        >
                            Cliente · {{ $party->customer->active ? 'activo' : 'inactivo' }}
                        </a>
                    @else
                        <div class="rounded-xl border border-slate-800 p-3 text-slate-500">
                            Sin rol Cliente
                        </div>
                    @endif

                    @if ($party->supplier)
                        <a
                            href="{{ route('suppliers.show', $party->supplier) }}"
                            class="block rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-amber-200"
                        >
                            Proveedor · {{ $party->supplier->active ? 'activo' : 'inactivo' }}
                        </a>
                    @else
                        <div class="rounded-xl border border-slate-800 p-3 text-slate-500">
                            Sin rol Proveedor
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <div class="text-xs uppercase tracking-wider text-slate-500">Ventas</div>
                <div class="mt-2 text-3xl font-bold text-white">{{ $salesCount }}</div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <div class="text-xs uppercase tracking-wider text-slate-500">Reparaciones como cliente</div>
                <div class="mt-2 text-3xl font-bold text-white">{{ $customerOrderCount }}</div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <div class="text-xs uppercase tracking-wider text-slate-500">Órdenes como propietario</div>
                <div class="mt-2 text-3xl font-bold text-white">{{ $ownerOrderCount }}</div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <div class="text-xs uppercase tracking-wider text-slate-500">Trabajo tercerizado como proveedor</div>
                <div class="mt-2 text-3xl font-bold text-white">{{ $providerWorkCount }}</div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <h3 class="font-semibold text-white">Reparaciones vinculadas</h3>
                <div class="mt-4 space-y-3">
                    @forelse ($customerOrders as $order)
                        <a
                            href="{{ route('service-orders.show', $order) }}"
                            class="block rounded-xl border border-slate-800 p-3 text-sm text-slate-300 hover:border-cyan-500/40"
                        >
                            Orden #{{ $order->order_number }}
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">Sin reparaciones como cliente.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                <h3 class="font-semibold text-white">Equipos / órdenes como propietario</h3>
                <div class="mt-4 space-y-3">
                    @forelse ($ownerOrders as $order)
                        <a
                            href="{{ route('service-orders.show', $order) }}"
                            class="block rounded-xl border border-slate-800 p-3 text-sm text-slate-300 hover:border-cyan-500/40"
                        >
                            Orden #{{ $order->order_number }}
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">Sin órdenes como propietario.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
            <h3 class="font-semibold text-white">Ventas vinculadas</h3>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @forelse ($sales as $sale)
                    <a
                        href="{{ route('commerce-sales.show', $sale) }}"
                        class="rounded-xl border border-slate-800 p-3 text-sm text-slate-300 hover:border-cyan-500/40"
                    >
                        Venta #{{ $sale->sale_number }}
                        · {{ $sale->currency_code }}
                        {{ number_format($sale->total_minor / 100, 2, ',', '.') }}
                    </a>
                @empty
                    <p class="text-sm text-slate-500">Sin ventas vinculadas.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
            <h3 class="font-semibold text-white">Otros contextos trazables</h3>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wider text-slate-500">Entregas recibidas</dt>
                    <dd class="mt-1 text-xl font-semibold text-white">{{ $deliveryRecipientCount }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-slate-500">Cancelaciones solicitadas</dt>
                    <dd class="mt-1 text-xl font-semibold text-white">{{ $cancellationRequesterCount }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-slate-500">Devoluciones por cancelación recibidas</dt>
                    <dd class="mt-1 text-xl font-semibold text-white">{{ $cancellationRecipientCount }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wider text-slate-500">ID interno</dt>
                    <dd class="mt-1 text-xl font-semibold text-white">#{{ $party->id }}</dd>
                </div>
            </dl>
        </section>
    </div>
</x-app-layout>
