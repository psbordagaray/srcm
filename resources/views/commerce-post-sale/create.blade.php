<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Posventa</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Nueva solicitud de posventa</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Venta #{{ $sale->sale_number }} · {{ $sale->customer_name_snapshot }} · {{ $sale->currency_code }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('commerce-sales.show', $sale) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">
                    Volver a la venta
                </a>
                <a href="{{ route('commerce-post-sale.index') }}" class="rounded-xl border border-violet-400/30 px-4 py-2.5 text-sm font-semibold text-violet-200 transition hover:border-violet-300">
                    Ver posventa
                </a>
            </div>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">No se pudo registrar la solicitud.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($sale->postSaleRequests->isNotEmpty())
            <section class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">Antecedentes</p>
                <p class="mt-2 text-sm text-slate-300">
                    Esta venta ya posee {{ $sale->postSaleRequests->count() }} solicitud{{ $sale->postSaleRequests->count() === 1 ? '' : 'es' }} de posventa. Una nueva solicitud no reescribe las anteriores.
                </p>
            </section>
        @endif

        <form method="POST" action="{{ route('commerce-post-sale.store', $sale) }}" class="space-y-6">
            @csrf

            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Intención</span>
                        <select name="intent" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white">
                            @foreach($intents as $intent)
                                <option value="{{ $intent->value }}" @selected(old('intent', \App\Enums\CommercePostSaleIntent::Return->value) === $intent->value)>
                                    {{ $intent->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Venta original</span>
                        <div class="mt-2 rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3 text-sm text-slate-300">
                            #{{ $sale->sale_number }} · $ {{ number_format($sale->total_minor / 100, 2, ',', '.') }}
                        </div>
                    </label>
                </div>

                <label class="mt-5 block">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Motivo</span>
                    <textarea
                        name="reason"
                        rows="3"
                        required
                        minlength="10"
                        maxlength="500"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                        placeholder="Describí qué solicita el cliente y por qué."
                    >{{ old('reason') }}</textarea>
                </label>

                <label class="mt-5 block">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Notas internas</span>
                    <textarea
                        name="notes"
                        rows="2"
                        maxlength="2000"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                        placeholder="Opcional."
                    >{{ old('notes') }}</textarea>
                </label>
            </section>

            <section class="sulu-card p-6">
                <div>
                    <h2 class="text-lg font-bold text-white">Productos alcanzados</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Seleccioná únicamente productos de la venta original. La recepción física se confirmará en un paso posterior.
                    </p>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse($productLines as $line)
                        @php
                            $index = $loop->index;
                            $selected = old("lines.$index.selected") === '1';
                        @endphp

                        <article class="rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                            <div class="grid gap-4 lg:grid-cols-[auto_minmax(0,1fr)_12rem] lg:items-center">
                                <label class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        name="lines[{{ $index }}][selected]"
                                        value="1"
                                        @checked($selected)
                                        class="h-4 w-4 rounded border-slate-600 bg-slate-950 text-amber-400"
                                    >
                                    <span class="text-xs font-bold uppercase tracking-wider text-violet-200">Incluir</span>
                                </label>

                                <div>
                                    <p class="text-sm font-bold text-white">{{ $line->description }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $line->product?->sku }} · vendido: {{ rtrim(rtrim($line->quantity, '0'), '.') }} {{ $line->product?->base_unit_code }}
                                    </p>
                                </div>

                                <label class="block">
                                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cantidad solicitada</span>
                                    <input
                                        type="hidden"
                                        name="lines[{{ $index }}][commerce_sale_line_id]"
                                        value="{{ $line->id }}"
                                    >
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        name="lines[{{ $index }}][quantity]"
                                        value="{{ old("lines.$index.quantity", rtrim(rtrim($line->quantity, '0'), '.')) }}"
                                        class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                    >
                                </label>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-5 text-sm text-amber-100">
                            Esta venta no contiene productos físicos elegibles para P8.1.
                        </div>
                    @endforelse
                </div>
            </section>

            <div class="flex flex-wrap justify-end gap-3">
                <a href="{{ route('commerce-sales.show', $sale) }}" class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">
                    Cancelar
                </a>

                <button
                    type="submit"
                    @disabled($productLines->isEmpty())
                    class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Registrar solicitud
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
