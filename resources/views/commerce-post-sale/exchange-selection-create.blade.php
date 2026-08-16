<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-violet-300">Comercio · Posventa · Cambio</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Seleccionar mercadería de reemplazo</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Venta #{{ $resolution->request->sale->sale_number }}
                    · reconocido $ {{ number_format($resolution->recognizedAmountMinor() / 100, 2, ',', '.') }}
                    · {{ $resolution->currency_code }}
                </p>
            </div>

            <a
                href="{{ route('commerce-post-sale.show', $resolution->request) }}"
                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
            >
                Volver al expediente
            </a>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">No se pudo fijar el reemplazo.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($resolution->exchangeSelection)
            <section class="sulu-card p-8">
                <p class="text-lg font-bold text-emerald-200">Selección ya fijada</p>
                <p class="mt-2 text-sm text-slate-500">
                    La selección de reemplazo es inmutable. La salida de stock y la diferencia económica se ejecutan en un paso posterior.
                </p>

                <div class="mt-5 space-y-3">
                    @foreach($resolution->exchangeSelection->lines as $line)
                        <div class="rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-bold text-white">{{ $line->product->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $line->product->sku }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-sm font-bold text-violet-100">
                                        {{ rtrim(rtrim($line->quantity, '0'), '.') }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        $ {{ number_format($line->unit_price_minor / 100, 2, ',', '.') }} c/u
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @elseif($prices->isEmpty())
            <section class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6">
                <p class="font-bold text-amber-100">No hay productos con precio vigente en {{ $resolution->currency_code }}.</p>
                <p class="mt-2 text-sm text-slate-400">
                    El reemplazo no puede seleccionarse hasta que exista al menos un precio privado vigente para la organización.
                </p>
            </section>
        @else
            <section class="rounded-2xl border border-violet-500/20 bg-violet-500/5 p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-violet-300">Sólo selección económica</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    El precio se toma del valor privado vigente del servidor. Este paso no reserva stock, no entrega mercadería y no cobra ni devuelve diferencias.
                </p>
            </section>

            <form
                method="POST"
                action="{{ route('commerce-post-sale.exchange-selections.store', $resolution) }}"
                class="space-y-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Productos disponibles para seleccionar</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        La existencia física se revalidará recién durante la ejecución del cambio.
                    </p>

                    <div class="mt-5 space-y-3">
                        @foreach($prices as $price)
                            @php
                                $index = $loop->index;
                                $selected = old("lines.$index.selected") === '1';
                            @endphp

                            <article class="rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                                <div class="grid gap-4 lg:grid-cols-[auto_minmax(0,1fr)_10rem_10rem] lg:items-center">
                                    <label class="flex items-center gap-3">
                                        <input
                                            type="checkbox"
                                            name="lines[{{ $index }}][selected]"
                                            value="1"
                                            @checked($selected)
                                            class="h-4 w-4 rounded border-slate-600 bg-slate-950 text-violet-400"
                                        >
                                        <span class="text-xs font-bold uppercase tracking-wider text-violet-200">Incluir</span>
                                    </label>

                                    <div>
                                        <input
                                            type="hidden"
                                            name="lines[{{ $index }}][catalog_product_id]"
                                            value="{{ $price->catalog_product_id }}"
                                        >
                                        <p class="text-sm font-bold text-white">{{ $price->product->name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $price->product->sku }}</p>
                                    </div>

                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Precio vigente</p>
                                        <p class="mt-1 font-mono text-sm font-bold text-white">
                                            $ {{ number_format($price->amount_minor / 100, 2, ',', '.') }}
                                        </p>
                                    </div>

                                    <label class="block">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cantidad</span>
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            name="lines[{{ $index }}][quantity]"
                                            value="{{ old("lines.$index.quantity", '1') }}"
                                            class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                        >
                                    </label>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Notas de selección</span>
                        <textarea
                            name="notes"
                            rows="3"
                            maxlength="2000"
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                            placeholder="Opcional."
                        >{{ old('notes') }}</textarea>
                    </label>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <a
                        href="{{ route('commerce-post-sale.show', $resolution->request) }}"
                        class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300"
                    >
                        Cancelar
                    </a>
                    <button
                        type="submit"
                        class="rounded-xl bg-violet-300 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-violet-200"
                    >
                        Fijar reemplazo
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
