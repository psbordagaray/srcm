<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Comercio · Posventa · Recepción física</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Confirmar mercadería recibida</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Venta #{{ $case->sale->sale_number }} · {{ $case->sale->customer_name_snapshot }}
                </p>
            </div>

            <a
                href="{{ route('commerce-post-sale.show', $case) }}"
                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
            >
                Volver al expediente
            </a>
        </div>

        <section class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">Hecho físico independiente</p>
            <p class="mt-2 text-sm leading-6 text-slate-300">
                Esta acción confirma únicamente qué mercadería volvió, en qué condición y a qué ubicación ingresó. No aprueba devolución de dinero, saldo a favor ni cambio.
            </p>
        </section>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">No se pudo confirmar la recepción.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            $hasPending = $receivableLines->contains(
                fn (array $item): bool => ! $item['complete']
            );
        @endphp

        @if(!$hasPending)
            <section class="sulu-card p-8 text-center">
                <p class="text-lg font-bold text-emerald-200">Recepción física completa</p>
                <p class="mt-2 text-sm text-slate-500">
                    Todas las cantidades solicitadas ya cuentan con recepción confirmada. No existe saldo físico pendiente para registrar.
                </p>
            </section>
        @elseif($locations->isEmpty())
            <section class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6">
                <p class="font-bold text-amber-100">No hay una ubicación activa disponible.</p>
                <p class="mt-2 text-sm text-slate-400">
                    La recepción física no puede confirmarse hasta que exista una ubicación de inventario activa en la organización.
                </p>
            </section>
        @else
            <form
                method="POST"
                action="{{ route('commerce-post-sale.receipts.store', $case) }}"
                class="space-y-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <section class="sulu-card p-6">
                    <div>
                        <h2 class="text-lg font-bold text-white">Líneas pendientes</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Podés confirmar una recepción parcial. El sistema controla el acumulado contra la solicitud original.
                        </p>
                    </div>

                    <div class="mt-5 space-y-4">
                        @foreach($receivableLines as $item)
                            @php
                                $line = $item['line'];
                                $index = $loop->index;
                                $selected = old("lines.$index.selected") === '1';
                            @endphp

                            <article class="rounded-xl border {{ $item['complete'] ? 'border-emerald-500/20 bg-emerald-500/5' : 'border-cyan-500/20 bg-cyan-500/5' }} p-4">
                                <div class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-white">
                                                {{ $line->saleLine->description }}
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ $line->saleLine->product?->sku }}
                                                · solicitado {{ rtrim(rtrim($item['requested'], '0'), '.') }}
                                                · recibido {{ rtrim(rtrim($item['received'], '0'), '.') }}
                                            </p>
                                        </div>

                                        <div class="rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-xs">
                                            <span class="text-slate-500">Pendiente</span>
                                            <span class="ml-2 font-mono font-bold {{ $item['complete'] ? 'text-emerald-200' : 'text-cyan-100' }}">
                                                {{ rtrim(rtrim($item['remaining'], '0'), '.') }}
                                                {{ $line->saleLine->product?->base_unit_code }}
                                            </span>
                                        </div>
                                    </div>

                                    @if(!$item['complete'])
                                        <div class="grid gap-4 xl:grid-cols-[auto_9rem_13rem_minmax(14rem,1fr)] xl:items-end">
                                            <label class="flex items-center gap-3 pb-2">
                                                <input
                                                    type="checkbox"
                                                    name="lines[{{ $index }}][selected]"
                                                    value="1"
                                                    @checked($selected)
                                                    class="h-4 w-4 rounded border-slate-600 bg-slate-950 text-cyan-400"
                                                >
                                                <span class="text-xs font-bold uppercase tracking-wider text-cyan-200">Recibir</span>
                                            </label>

                                            <label class="block">
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cantidad</span>
                                                <input
                                                    type="hidden"
                                                    name="lines[{{ $index }}][commerce_post_sale_request_line_id]"
                                                    value="{{ $line->id }}"
                                                >
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    name="lines[{{ $index }}][quantity]"
                                                    value="{{ old("lines.$index.quantity", rtrim(rtrim($item['remaining'], '0'), '.')) }}"
                                                    class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                                >
                                            </label>

                                            <label class="block">
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Condición real</span>
                                                <select
                                                    name="lines[{{ $index }}][condition]"
                                                    class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                                >
                                                    @foreach($conditions as $condition)
                                                        <option
                                                            value="{{ $condition->value }}"
                                                            @selected(old("lines.$index.condition", \App\Enums\InventoryCondition::Used->value) === $condition->value)
                                                        >
                                                            {{ $condition->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>

                                            <label class="block">
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Ubicación de ingreso</span>
                                                <select
                                                    name="lines[{{ $index }}][destination_location_id]"
                                                    class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                                >
                                                    @foreach($locations as $location)
                                                        <option
                                                            value="{{ $location->id }}"
                                                            @selected((string) old("lines.$index.destination_location_id", $locations->first()?->id) === (string) $location->id)
                                                        >
                                                            {{ $location->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </div>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Observación de la línea</span>
                                            <input
                                                type="text"
                                                name="lines[{{ $index }}][notes]"
                                                value="{{ old("lines.$index.notes") }}"
                                                maxlength="1000"
                                                placeholder="Ej.: caja abierta, marcas de uso, accesorio faltante."
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder:text-slate-600"
                                            >
                                        </label>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Notas generales de recepción</span>
                        <textarea
                            name="notes"
                            rows="3"
                            maxlength="2000"
                            placeholder="Opcional. Contexto general de esta recepción física."
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                        >{{ old('notes') }}</textarea>
                    </label>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <a
                        href="{{ route('commerce-post-sale.show', $case) }}"
                        class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="rounded-xl bg-cyan-300 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200"
                    >
                        Confirmar recepción física
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
