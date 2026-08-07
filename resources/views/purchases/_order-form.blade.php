@php
    $editing = isset($order);
    $rows = old('lines');

    if (! is_array($rows)) {
        $rows = $editing
            ? $order->lines->map(fn ($line) => [
                'catalog_product_id' => (string) $line->catalog_product_id,
                'supplier_offer_id' => $line->supplier_offer_id === null ? '' : (string) $line->supplier_offer_id,
                'quantity' => str_contains((string) $line->ordered_quantity, '.')
                    ? rtrim(rtrim((string) $line->ordered_quantity, '0'), '.')
                    : (string) $line->ordered_quantity,
                'unit_cost' => number_format($line->unit_cost_minor / 100, 2, '.', ''),
                'supplier_code' => $line->supplier_code ?? '',
                'description' => $line->description ?? '',
            ])->values()->all()
            : [[
                'catalog_product_id' => '',
                'supplier_offer_id' => '',
                'quantity' => '1',
                'unit_cost' => '',
                'supplier_code' => '',
                'description' => '',
            ]];
    }
@endphp

@if($errors->any())
    <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
        <ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="space-y-6" x-data="{
    lines: {{ \Illuminate\Support\Js::from(array_values($rows)) }},
    addLine() { this.lines.push({ catalog_product_id: '', supplier_offer_id: '', quantity: '1', unit_cost: '', supplier_code: '', description: '' }); }
}">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

    <section class="sulu-card p-6">
        <h2 class="text-lg font-bold text-white">Condiciones comerciales</h2>
        <p class="mt-1 text-sm text-slate-500">La orden conserva proveedor, moneda y costo acordado. Inventario sólo cambia cuando exista una recepción confirmada.</p>
        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <label for="supplier_id" class="text-sm font-semibold text-slate-200">Proveedor</label>
                <select id="supplier_id" name="supplier_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    <option value="">Seleccionar proveedor</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $order->supplier_id ?? '') === (string) $supplier->id)>{{ $supplier->party->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="currency_code" class="text-sm font-semibold text-slate-200">Moneda ISO</label>
                <input id="currency_code" name="currency_code" maxlength="3" required value="{{ old('currency_code', $order->currency_code ?? 'ARS') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono uppercase text-slate-100 focus:border-amber-400 focus:ring-amber-400">
            </div>
        </div>
        <div class="mt-5 grid gap-5 md:grid-cols-2">
            <div>
                <label for="expected_logistics_cost" class="text-sm font-semibold text-slate-200">Logística esperada</label>
                <input id="expected_logistics_cost" name="expected_logistics_cost" inputmode="decimal" required value="{{ old('expected_logistics_cost', isset($order) ? number_format($order->expected_logistics_cost_minor / 100, 2, '.', '') : '0.00') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-amber-400 focus:ring-amber-400">
            </div>
            <div>
                <label for="notes" class="text-sm font-semibold text-slate-200">Notas</label>
                <textarea id="notes" name="notes" rows="2" maxlength="4000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">{{ old('notes', $order->notes ?? '') }}</textarea>
            </div>
        </div>
    </section>

    <section class="sulu-card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 class="text-lg font-bold text-white">Líneas de la orden</h2><p class="mt-1 text-sm text-slate-500">La oferta es opcional; producto, cantidad y costo acordado quedan congelados al emitir.</p></div>
            <button type="button" @click="addLine()" class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200 hover:border-cyan-300">Agregar línea</button>
        </div>
        <div class="mt-5 space-y-4">
            <template x-for="(line, index) in lines" :key="'purchase-line-'+index">
                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3"><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Línea <span x-text="index + 1"></span></p><button type="button" @click="lines.splice(index, 1)" class="text-xs font-semibold text-red-300 hover:text-red-200">Quitar</button></div>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div><label class="text-xs font-semibold text-slate-400">Producto</label><select :name="`lines[${index}][catalog_product_id]`" x-model="line.catalog_product_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"><option value="">Seleccionar</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }} · {{ $product->base_unit_code }}</option>@endforeach</select></div>
                        <div><label class="text-xs font-semibold text-slate-400">Oferta de proveedor opcional</label><select :name="`lines[${index}][supplier_offer_id]`" x-model="line.supplier_offer_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"><option value="">Sin oferta vinculada</option>@foreach($offers as $offer)<option value="{{ $offer->id }}">{{ $offer->supplier->party->name }} · {{ $offer->product->sku }} · {{ $offer->currency }} {{ $offer->cost_amount }}</option>@endforeach</select><p class="mt-1 text-[11px] text-slate-600">El dominio rechazará cualquier oferta que no coincida con proveedor y producto.</p></div>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div><label class="text-xs font-semibold text-slate-400">Cantidad</label><input :name="`lines[${index}][quantity]`" x-model="line.quantity" inputmode="decimal" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"></div>
                        <div><label class="text-xs font-semibold text-slate-400">Costo unitario acordado</label><input :name="`lines[${index}][unit_cost]`" x-model="line.unit_cost" inputmode="decimal" placeholder="0,00" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"></div>
                        <div><label class="text-xs font-semibold text-slate-400">Código proveedor</label><input :name="`lines[${index}][supplier_code]`" x-model="line.supplier_code" maxlength="255" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"></div>
                        <div><label class="text-xs font-semibold text-slate-400">Descripción comercial</label><input :name="`lines[${index}][description]`" x-model="line.description" maxlength="1000" placeholder="Se deriva de oferta o producto si queda vacío" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"></div>
                    </div>
                </article>
            </template>
        </div>
    </section>

    <div class="flex flex-wrap justify-end gap-3">
        <a href="{{ isset($order) ? route('purchase-orders.show', $order) : route('purchase-orders.index') }}" class="rounded-xl border border-slate-700 px-5 py-2.5 text-sm font-semibold text-slate-400">Cancelar</a>
        <button type="submit" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-bold text-slate-950 hover:bg-amber-300">{{ $submitLabel }}</button>
    </div>
</form>
