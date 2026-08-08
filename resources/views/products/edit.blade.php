<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Catálogo maestro
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Editar producto
            </h1>

            <p class="mt-2 text-sm text-slate-400">
                {{ $product->name }} · {{ $product->sku }}
            </p>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        @error('price')
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                {{ $message }}
            </div>
        @enderror

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
            <form method="POST" action="{{ route('products.update', $product) }}">
                @csrf
                @method('PUT')

                @include('products._form')
            </form>
        </section>

        <section class="rounded-2xl border border-amber-400/20 bg-amber-400/[0.04] p-6 shadow-xl shadow-black/10">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-300">Política comercial privada</p>
                    <h2 class="mt-2 text-lg font-bold text-white">Precio de venta de {{ $currentOrganization->name }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
                        El producto pertenece al catálogo maestro compartido; el precio pertenece exclusivamente a esta organización. Cada cambio abre una nueva revisión histórica y las ventas toman el precio desde el servidor.
                    </p>
                </div>
                <span class="rounded-lg border border-amber-400/20 bg-amber-400/10 px-3 py-2 text-xs font-semibold text-amber-100">Operadores: solo lectura</span>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                @foreach(['ARS', 'USD'] as $currency)
                    @php($price = $currentPrices->firstWhere('currency_code', $currency))
                    <article class="rounded-xl border border-slate-800 bg-slate-950/60 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ $currency }}</p>
                                <p class="mt-2 text-2xl font-bold text-white">
                                    @if($price)
                                        {{ $currency }} {{ number_format($price->amount_minor / 100, 2, ',', '.') }}
                                    @else
                                        <span class="text-amber-300">Sin precio vigente</span>
                                    @endif
                                </p>
                                @if($price)
                                    <p class="mt-2 text-xs text-slate-500">Vigente desde {{ $price->valid_from?->format('d/m/Y H:i') }}</p>
                                @endif
                            </div>
                        </div>

                        @can('manage-commerce-prices')
                            <form method="POST" action="{{ route('organization-product-prices.update', $product) }}" class="mt-5 space-y-3">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="currency_code" value="{{ $currency }}">
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Nuevo precio</label>
                                    <input name="amount" type="text" inputmode="decimal" required placeholder="0,00" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white focus:border-amber-400 focus:ring-amber-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Motivo / referencia</label>
                                    <input name="reason" type="text" maxlength="500" placeholder="Lista vigente, ajuste comercial, etc." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-amber-400 focus:ring-amber-400">
                                </div>
                                <button type="submit" class="rounded-xl bg-amber-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-amber-200">Actualizar precio {{ $currency }}</button>
                            </form>
                        @else
                            <p class="mt-5 rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3 text-xs leading-5 text-slate-400">
                                Tu rol puede consultar el precio vigente, pero no modificar la política comercial.
                            </p>
                        @endcan
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
