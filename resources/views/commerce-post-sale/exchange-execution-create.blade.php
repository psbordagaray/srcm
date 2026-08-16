<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-violet-300">Comercio · Posventa · Ejecución de cambio</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Entregar reemplazo y resolver diferencia</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Venta #{{ $selection->resolution->request->sale->sale_number }}
                    · selección {{ \Illuminate\Support\Str::limit($selection->public_id, 12) }}
                    · {{ $selection->currency_code }}
                </p>
            </div>

            <a
                href="{{ route('commerce-post-sale.show', $selection->resolution->request) }}"
                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
            >
                Volver al expediente
            </a>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">No se pudo ejecutar el cambio.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($selection->execution)
            <section class="sulu-card p-8 text-center">
                <p class="text-lg font-bold text-emerald-200">Cambio ya ejecutado</p>
                <p class="mt-2 text-sm text-slate-500">
                    Movimiento de inventario #{{ $selection->execution->inventory_movement_id }}
                    · {{ $selection->execution->executed_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                    · {{ $selection->execution->executedBy->name }}
                </p>
            </section>
        @else
            <section class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-rose-300">Acción final con efectos reales</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    Confirmar este formulario genera la salida real de inventario de los reemplazos. Si la diferencia es positiva también registra el cobro; si es negativa genera un crédito específico a favor del cliente.
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    El ejecutor debe ser distinto tanto del resolutor económico como de quien seleccionó el reemplazo.
                </p>
            </section>

            @php
                $difference = $selection->differenceAmountMinor();
                $hasUnavailableLine = $selectionLines->contains(
                    fn ($line): bool => ($availability->get($line->id)?->isEmpty() ?? true)
                );
            @endphp

            <section class="sulu-card p-6">
                <dl class="grid gap-5 md:grid-cols-3">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Valor reconocido</dt>
                        <dd class="mt-2 font-mono text-xl font-bold text-white">
                            $ {{ number_format($selection->recognized_amount_minor / 100, 2, ',', '.') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Reemplazo</dt>
                        <dd class="mt-2 font-mono text-xl font-bold text-white">
                            $ {{ number_format($selection->replacementAmountMinor() / 100, 2, ',', '.') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Diferencia</dt>
                        <dd class="mt-2 font-mono text-xl font-bold {{ $difference > 0 ? 'text-rose-200' : ($difference < 0 ? 'text-emerald-200' : 'text-slate-200') }}">
                            $ {{ number_format($difference / 100, 2, ',', '.') }}
                        </dd>
                    </div>
                </dl>
            </section>

            @if($hasUnavailableLine)
                <section class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5">
                    <p class="font-bold text-amber-100">Falta stock suficiente en una dimensión física única.</p>
                    <p class="mt-2 text-sm text-slate-400">
                        Cada línea seleccionada debe salir completa desde una ubicación y condición concretas. La ejecución permanecerá bloqueada hasta que exista saldo suficiente.
                    </p>
                </section>
            @endif

            <form
                method="POST"
                action="{{ route('commerce-post-sale.exchange-executions.store', $selection) }}"
                class="space-y-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Origen físico de los reemplazos</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        El stock se vuelve a validar y bloquear dentro de la transacción.
                    </p>

                    <div class="mt-5 space-y-4">
                        @foreach($selectionLines as $line)
                            @php
                                $options = $availability->get($line->id, collect());
                                $oldSource = old("lines.$loop->index.source_balance");
                            @endphp

                            <article class="rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_28rem] lg:items-center">
                                    <div>
                                        <p class="text-sm font-bold text-white">{{ $line->product->name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $line->product->sku }}
                                            · {{ rtrim(rtrim($line->quantity, '0'), '.') }} {{ $line->product->base_unit_code }}
                                        </p>
                                    </div>

                                    <label class="block">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Ubicación y condición de salida</span>

                                        <input
                                            type="hidden"
                                            name="lines[{{ $loop->index }}][commerce_post_sale_exchange_selection_line_id]"
                                            value="{{ $line->id }}"
                                        >

                                        <select
                                            name="lines[{{ $loop->index }}][source_balance]"
                                            required
                                            class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                        >
                                            <option value="">Seleccioná saldo físico</option>
                                            @foreach($options as $balance)
                                                @php
                                                    $value = $balance->inventory_location_id.'|'.$balance->condition->value;
                                                @endphp
                                                <option value="{{ $value }}" @selected($oldSource === $value)>
                                                    {{ $balance->location->name }}
                                                    · {{ $balance->condition->label() }}
                                                    · disponible {{ rtrim(rtrim($balance->quantity, '0'), '.') }} {{ $balance->base_unit_code }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                @if($difference > 0)
                    <section class="sulu-card p-6">
                        <h2 class="text-lg font-bold text-white">Cobro exacto de la diferencia</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Seleccioná uno o más medios cuya suma sea exactamente $ {{ number_format($difference / 100, 2, ',', '.') }}. Los medios no efectivos se registran localmente; este flujo no fabrica movimientos externos ni llama proveedores.
                        </p>
                        <p class="mt-2 text-sm text-emerald-200">
                            Saldo a favor disponible:
                            <span class="font-mono font-bold">
                                $ {{ number_format($customerCreditBalanceMinor / 100, 2, ',', '.') }}
                            </span>
                            @if($selection->resolution->request->sale->customer_business_party_id === null)
                                · requiere cliente identificado.
                            @else
                                · puede combinarse con otros medios.
                            @endif
                        </p>

                        <div class="mt-5 space-y-4">
                            @for($index = 0; $index < 3; $index++)
                                <article class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-4">
                                    <div class="grid gap-4 xl:grid-cols-[auto_12rem_12rem_minmax(14rem,1fr)_12rem] xl:items-end">
                                        <label class="flex items-center gap-3 pb-2">
                                            <input
                                                type="checkbox"
                                                name="payments[{{ $index }}][selected]"
                                                value="1"
                                                @checked(old("payments.$index.selected") === '1')
                                                class="h-4 w-4 rounded border-slate-600 bg-slate-950 text-rose-400"
                                            >
                                            <span class="text-xs font-bold uppercase tracking-wider text-rose-200">Usar</span>
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Medio</span>
                                            <select
                                                name="payments[{{ $index }}][method]"
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                            >
                                                @foreach($paymentMethods as $method)
                                                    <option value="{{ $method->value }}" @selected(old("payments.$index.method", \App\Enums\CommercePaymentMethod::BankTransfer->value) === $method->value)>
                                                        {{ $method->label() }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Importe</span>
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                name="payments[{{ $index }}][amount]"
                                                value="{{ old("payments.$index.amount") }}"
                                                placeholder="0,00"
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                            >
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cuenta · dejar vacía para Crédito en cuenta</span>
                                            <select
                                                name="payments[{{ $index }}][financial_account_id]"
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                            >
                                                <option value="">Seleccioná cuenta</option>
                                                @foreach($accounts as $account)
                                                    <option value="{{ $account->id }}" @selected((string) old("payments.$index.financial_account_id") === (string) $account->id)>
                                                        {{ $account->name }} · {{ $account->type->label() }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Efectivo entregado</span>
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                name="payments[{{ $index }}][tendered_amount]"
                                                value="{{ old("payments.$index.tendered_amount") }}"
                                                placeholder="Sólo efectivo"
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                            >
                                        </label>
                                    </div>

                                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Referencia</span>
                                            <input
                                                type="text"
                                                name="payments[{{ $index }}][reference]"
                                                value="{{ old("payments.$index.reference") }}"
                                                maxlength="255"
                                                placeholder="No usar para Crédito en cuenta"
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                            >
                                        </label>

                                        <label class="block">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Nota</span>
                                            <input
                                                type="text"
                                                name="payments[{{ $index }}][notes]"
                                                value="{{ old("payments.$index.notes") }}"
                                                maxlength="2000"
                                                class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                                            >
                                        </label>
                                    </div>
                                </article>
                            @endfor
                        </div>
                    </section>
                @elseif($difference < 0)
                    <section class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-5">
                        <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">Diferencia a favor del cliente</p>
                        <p class="mt-2 text-sm text-slate-300">
                            La ejecución generará un crédito específico por $ {{ number_format(abs($difference) / 100, 2, ',', '.') }}. No entrega efectivo automáticamente.
                        </p>
                    </section>
                @else
                    <section class="rounded-2xl border border-slate-700 bg-slate-900/60 p-5">
                        <p class="text-sm font-semibold text-slate-200">Cambio sin diferencia monetaria.</p>
                    </section>
                @endif

                <section class="sulu-card p-6">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nota de ejecución</span>
                        <textarea
                            name="notes"
                            rows="3"
                            maxlength="2000"
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                            placeholder="Opcional."
                        >{{ old('notes') }}</textarea>
                    </label>

                    <label class="mt-5 flex items-start gap-3 rounded-xl border border-rose-500/20 bg-rose-500/5 p-4">
                        <input
                            type="checkbox"
                            name="confirm_execution"
                            value="1"
                            required
                            class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-rose-400"
                        >
                        <span class="text-sm leading-6 text-slate-300">
                            Confirmo que la mercadería indicada se entrega ahora y que comprendo que esta acción puede registrar cobros o crédito a favor de forma atómica e inmutable.
                        </span>
                    </label>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <a
                        href="{{ route('commerce-post-sale.show', $selection->resolution->request) }}"
                        class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        @disabled($hasUnavailableLine)
                        class="rounded-xl bg-violet-300 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-violet-200 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Ejecutar cambio
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
