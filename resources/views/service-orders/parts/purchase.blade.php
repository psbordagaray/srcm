<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Reparaciones · Compra afectada</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Registrar compra de repuestos</h1>
            <p class="mt-2 text-sm text-slate-400">Orden #{{ $order->order_number }} · La compra queda fuera del stock general hasta su consumo.</p>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('service-orders.part-purchases.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-3">
                    <div>
                        <label for="supplier_id" class="text-sm font-semibold text-slate-200">Proveedor</label>
                        <select id="supplier_id" name="supplier_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                            <option value="">Seleccionar</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>
                                    {{ $supplier->party->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="currency_code" class="text-sm font-semibold text-slate-200">Moneda</label>
                        <input id="currency_code" name="currency_code" type="text" maxlength="3" required value="{{ old('currency_code', 'ARS') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 uppercase text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>

                    <div>
                        <label for="purchased_at" class="text-sm font-semibold text-slate-200">Fecha y hora</label>
                        <input id="purchased_at" name="purchased_at" type="datetime-local" required value="{{ old('purchased_at', now()->format('Y-m-d\TH:i')) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <h2 class="text-lg font-bold text-white">Repuestos pendientes de compra</h2>
                <p class="mt-1 text-sm text-slate-500">Podés imputar uno o varios. Dejá ambos campos vacíos para omitir una línea.</p>

                <div class="mt-5 space-y-4">
                    @foreach($requirements as $requirement)
                        <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                            <input type="hidden" name="lines[{{ $requirement->id }}][service_part_requirement_id]" value="{{ $requirement->id }}">

                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-white">{{ $requirement->product->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Trabajo {{ $requirement->workItem->sequence }} · {{ $requirement->condition->label() }}
                                    </p>
                                </div>
                                <p class="font-mono text-sm text-amber-200">
                                    Pendiente {{ $requirement->purchase_remaining }} {{ $requirement->base_unit_code }}
                                </p>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label for="quantity_{{ $requirement->id }}" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cantidad recibida</label>
                                    <input id="quantity_{{ $requirement->id }}" name="lines[{{ $requirement->id }}][quantity]" type="text" value="{{ old('lines.'.$requirement->id.'.quantity', $requirement->purchase_remaining) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                                </div>
                                <div>
                                    <label for="unit_cost_{{ $requirement->id }}" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Costo unitario</label>
                                    <input id="unit_cost_{{ $requirement->id }}" name="lines[{{ $requirement->id }}][unit_cost]" type="text" value="{{ old('lines.'.$requirement->id.'.unit_cost') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400" placeholder="0,00">
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="logistics_cost" class="text-sm font-semibold text-slate-200">Costo logístico</label>
                        <input id="logistics_cost" name="logistics_cost" type="text" required value="{{ old('logistics_cost', '0') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                    <div>
                        <label for="document_reference" class="text-sm font-semibold text-slate-200">Factura, remito o referencia</label>
                        <input id="document_reference" name="document_reference" type="text" maxlength="255" value="{{ old('document_reference') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                </div>

                <div class="mt-5">
                    <label for="notes" class="text-sm font-semibold text-slate-200">Notas</label>
                    <textarea id="notes" name="notes" rows="4" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">{{ old('notes') }}</textarea>
                </div>

                <div class="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs text-amber-100">
                    Esta compra queda afectada a la orden. No crea una recepción de inventario ni aumenta el saldo disponible.
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                    <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                    <button type="submit" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Registrar compra</button>
                </div>
            </section>
        </form>
    </div>
</x-app-layout>
