<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Posventa · Resolución económica</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Resolver valor reconocido</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Venta #{{ $case->sale->sale_number }} · {{ $case->sale->customer_name_snapshot }} · {{ $case->sale->currency_code }}
                </p>
            </div>

            <a
                href="{{ route('commerce-post-sale.show', $case) }}"
                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
            >
                Volver al expediente
            </a>
        </div>

        <section class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-amber-300">Acto administrativo separado</p>
            <p class="mt-2 text-sm leading-6 text-slate-300">
                Esta acción fija el valor económico reconocido sobre mercadería ya recibida. No entrega reemplazos, no crea saldo a favor, no devuelve efectivo y no llama proveedores externos.
            </p>
        </section>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">No se pudo registrar la resolución.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            $hasPending = $resolvableLines->contains(
                fn (array $item): bool => ! $item['complete']
            );
        @endphp

        @if(!$hasPending)
            <section class="sulu-card p-8 text-center">
                <p class="text-lg font-bold text-emerald-200">No hay cantidades recibidas pendientes de resolución</p>
                <p class="mt-2 text-sm text-slate-500">
                    Para resolver debe existir recepción física confirmada y cantidad aún no incluida en una resolución económica.
                </p>
            </section>
        @else
            <form
                method="POST"
                action="{{ route('commerce-post-sale.resolutions.store', $case) }}"
                class="space-y-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <section class="sulu-card p-6">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Outcome económico</span>
                            <select
                                name="outcome"
                                required
                                class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white"
                            >
                                @foreach($outcomes as $outcome)
                                    <option value="{{ $outcome->value }}" @selected(old('outcome', \App\Enums\CommercePostSaleResolutionOutcome::CustomerCredit->value) === $outcome->value)>
                                        {{ $outcome->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-xs text-slate-500">
                                El outcome se materializa en un paso posterior y conserva sus permisos propios.
                            </p>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Medio original preferido para reembolso</span>
                            <select
                                name="preferred_original_payment_id"
                                class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white"
                            >
                                <option value="">Sin preferencia</option>
                                @foreach($payments as $payment)
                                    <option value="{{ $payment->id }}" @selected((string) old('preferred_original_payment_id') === (string) $payment->id)>
                                        {{ $payment->method->label() }}
                                        · $ {{ number_format($payment->amount_minor / 100, 2, ',', '.') }}
                                        @if($payment->financialAccount)
                                            · {{ $payment->financialAccount->name }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-xs text-slate-500">
                                Sólo aplica a outcome Reembolso. No ejecuta el pago.
                            </p>
                        </label>
                    </div>

                    <label class="mt-5 block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Motivo de la resolución</span>
                        <textarea
                            name="reason"
                            rows="3"
                            required
                            minlength="10"
                            maxlength="1000"
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                            placeholder="Explicá por qué se reconoce este valor y outcome."
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
                        <h2 class="text-lg font-bold text-white">Mercadería recibida pendiente de valuación</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Podés resolver parcialmente. El valor máximo se controla contra el precio original proporcional y el acumulado se revalida dentro de la transacción.
                        </p>
                    </div>

                    <div class="mt-5 space-y-4">
                        @foreach($resolvableLines as $item)
                            @php
                                $line = $item['line'];
                                $index = $loop->index;
                                $selected = old("lines.$index.selected") === '1';
                            @endphp

                            <article class="rounded-xl border {{ $item['complete'] ? 'border-emerald-500/20 bg-emerald-500/5' : 'border-amber-500/20 bg-amber-500/5' }} p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-white">
                                            {{ $line->requestLine->saleLine->description }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Recepción {{ \Illuminate\Support\Str::limit($item['receipt']->public_id, 12) }}
                                            · {{ $line->condition->label() }}
                                            · recibido {{ rtrim(rtrim($item['received'], '0'), '.') }}
                                            · resuelto {{ rtrim(rtrim($item['resolved'], '0'), '.') }}
                                        </p>
                                    </div>

                                    <div class="rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-xs">
                                        <span class="text-slate-500">Pendiente</span>
                                        <span class="ml-2 font-mono font-bold {{ $item['complete'] ? 'text-emerald-200' : 'text-amber-100' }}">
                                            {{ rtrim(rtrim($item['remaining'], '0'), '.') }}
                                            {{ $line->requestLine->saleLine->product?->base_unit_code }}
                                        </span>
                                    </div>
                                </div>

                                <p class="mt-3 text-xs text-slate-500">
                                    Precio original unitario:
                                    <span class="font-mono text-slate-300">
                                        $ {{ number_format($line->requestLine->saleLine->unit_price_minor / 100, 2, ',', '.') }}
                                    </span>
                                </p>

                                @if(!$item['complete'])
                                    <div class="mt-4 grid gap-4 xl:grid-cols-[auto_9rem_12rem_minmax(18rem,1fr)] xl:items-end">
                                        <label class="flex items-center gap-3 pb-2">
                                            <input
                                                type="checkbox"
                                                name="lines[{{ $index }}][selected]"
                                                value="1"
                                                @checked($selected)
                                                class="h-4 w-4 rounded border-slate-600 bg-slate-950 text-amber-400"
                                            >
                                            <span class="text-xs font-bold uppercase tracking-wider text-amber-200">Resolver</span>
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cantidad</span>
                                            <input
                                                type="hidden"
                                                name="lines[{{ $index }}][commerce_post_sale_receipt_line_id]"
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
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Valor reconocido</span>
                                            <div class="mt-1 flex rounded-xl border border-slate-700 bg-slate-950">
                                                <span class="border-r border-slate-700 px-3 py-2 text-sm text-slate-500">$</span>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    name="lines[{{ $index }}][recognized_amount]"
                                                    value="{{ old("lines.$index.recognized_amount") }}"
                                                    placeholder="0,00"
                                                    class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm text-white placeholder:text-slate-600 focus:ring-0"
                                                >
                                            </div>
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Motivo de ajuste si reduce valor</span>
                                            <input
                                                type="text"
                                                name="lines[{{ $index }}][adjustment_reason]"
                                                value="{{ old("lines.$index.adjustment_reason") }}"
                                                maxlength="1000"
                                                placeholder="Obligatorio cuando el valor reconocido es menor al proporcional original."
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder:text-slate-600"
                                            >
                                        </label>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
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
                        class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-amber-300"
                    >
                        Registrar resolución económica
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
