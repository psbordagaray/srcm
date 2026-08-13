<x-app-layout>
    @php
        $oldLines = collect(old('lines', []))->filter(fn ($line) => is_array($line))->keyBy(fn ($line) => (string) ($line['purchase_order_line_id'] ?? ''));
    @endphp
    <div class="mx-auto max-w-6xl space-y-6">
        <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Compras · Recepción física</p><h1 class="mt-2 text-2xl font-bold text-white">Recibir orden {{ strtoupper(substr($order->public_id, 0, 8)) }}</h1><p class="mt-2 text-sm text-slate-400">La confirmación crea y confirma el movimiento de Inventario dentro de la misma transacción.</p></div>

        @if($errors->any())<div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <form method="POST" action="{{ route('purchase-orders.receipts.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Proveedor</p><p class="mt-2 text-sm font-bold text-white">{{ $order->supplier->party->name }}</p></div>
                    <div><label for="received_at" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fecha y hora</label><input id="received_at" name="received_at" type="datetime-local" required value="{{ old('received_at', now()->format('Y-m-d\\TH:i')) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400"></div>
                    <div><label for="document_reference" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Remito / factura / referencia</label><input id="document_reference" name="document_reference" maxlength="255" value="{{ old('document_reference') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400"></div>
                    <div><label for="logistics_cost" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Logística real</label><input id="logistics_cost" name="logistics_cost" inputmode="decimal" required value="{{ old('logistics_cost', '0.00') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-emerald-400 focus:ring-emerald-400"></div>
                </div>
                <div class="mt-5"><label for="notes" class="text-sm font-semibold text-slate-200">Notas de recepción</label><textarea id="notes" name="notes" rows="2" maxlength="4000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('notes') }}</textarea></div>
            </section>

            <section class="space-y-4">
                <div><h2 class="text-lg font-bold text-white">Mercadería recibida</h2><p class="mt-1 text-sm text-slate-500">Active sólo las líneas que llegaron en esta entrega. No se admite exceder el pendiente.</p></div>
                @foreach($order->lines as $index => $line)
                    @php
                        $balance = $lineBalances[$line->id];
                        $old = $oldLines->get((string) $line->id, []);
                        $selected = $oldLines->has((string) $line->id);
                    @endphp
                    @if(\App\Domain\Inventory\InventoryQuantity::isPositive($balance['remaining']))
                        <article class="sulu-card p-5" x-data="{ selected: {{ $selected ? 'true' : 'false' }} }">
                            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-sm font-bold text-white">{{ $line->product->sku }} · {{ $line->description }}</p><p class="mt-1 text-xs text-slate-500">Pedido {{ \App\Domain\Inventory\InventoryQuantity::display($line->ordered_quantity, $line->quantity_scale) }} · recibido {{ \App\Domain\Inventory\InventoryQuantity::display($balance['received'], $line->quantity_scale) }} · pendiente {{ \App\Domain\Inventory\InventoryQuantity::display($balance['remaining'], $line->quantity_scale) }} {{ $line->base_unit_code }}</p></div><label class="flex items-center gap-2 text-sm font-semibold text-emerald-200"><input type="checkbox" x-model="selected" class="rounded border-slate-600 bg-slate-900 text-emerald-400 focus:ring-emerald-400"> Incluir</label></div>
                            <input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $line->id }}" :disabled="!selected">
                            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <div><label class="text-xs font-semibold text-slate-400">Cantidad</label><input name="lines[{{ $index }}][quantity]" inputmode="decimal" value="{{ $old['quantity'] ?? \App\Domain\Inventory\InventoryQuantity::input($balance['remaining'], $line->quantity_scale) }}" :disabled="!selected" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 disabled:opacity-40 focus:border-emerald-400 focus:ring-emerald-400"></div>
                                <div><label class="text-xs font-semibold text-slate-400">Ubicación destino</label><select name="lines[{{ $index }}][inventory_location_id]" :disabled="!selected" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 disabled:opacity-40 focus:border-emerald-400 focus:ring-emerald-400"><option value="">Seleccionar</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) ($old['inventory_location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>@endforeach</select></div>
                                <div><label class="text-xs font-semibold text-slate-400">Condición</label><select name="lines[{{ $index }}][condition]" :disabled="!selected" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 disabled:opacity-40 focus:border-emerald-400 focus:ring-emerald-400">@foreach($conditions as $condition)<option value="{{ $condition->value }}" @selected(($old['condition'] ?? \App\Enums\InventoryCondition::New->value) === $condition->value)>{{ $condition->label() }}</option>@endforeach</select></div>
                                <div><label class="text-xs font-semibold text-slate-400">Costo unitario real</label><input name="lines[{{ $index }}][actual_unit_cost]" inputmode="decimal" value="{{ $old['actual_unit_cost'] ?? number_format($line->unit_cost_minor / 100, 2, '.', '') }}" :disabled="!selected" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 disabled:opacity-40 focus:border-emerald-400 focus:ring-emerald-400"><p class="mt-1 text-[11px] text-slate-600">Acordado: {{ $order->currency_code }} {{ number_format($line->unit_cost_minor / 100, 2, ',', '.') }}</p></div>
                            </div>
                        </article>
                    @endif
                @endforeach
            </section>

            <div class="flex justify-end gap-3"><a href="{{ route('purchase-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-5 py-2.5 text-sm font-semibold text-slate-400">Volver</a><button class="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950 hover:bg-emerald-300">Confirmar recepción</button></div>
        </form>
    </div>
</x-app-layout>
