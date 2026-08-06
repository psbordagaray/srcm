<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-orange-300">Reparaciones · Repuestos</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Planificar repuesto</h1>
            <p class="mt-2 text-sm text-slate-400">
                Orden #{{ $order->order_number }} · Trabajo {{ $work->sequence }}: {{ $work->title }}
            </p>
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

        <section class="sulu-card p-6">
            @if($warrantyMode)
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">Trabajo correctivo de garantía</p>
                <h2 class="mt-2 text-lg font-bold text-white">Alcance autorizado</h2>
                <p class="mt-3 whitespace-pre-line text-sm text-slate-300">{{ $work->warrantyResolution->covered_scope }}</p>
                @if($work->warrantyResolution->excluded_scope)
                    <p class="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs text-amber-200">
                        <strong>Exclusiones:</strong> {{ $work->warrantyResolution->excluded_scope }}
                    </p>
                @endif
            @else
                <p class="text-xs font-bold uppercase tracking-wider text-amber-300">Presupuesto aprobado</p>
                <h2 class="mt-2 text-lg font-bold text-white">{{ $work->approvedOption->label }}</h2>
                <p class="mt-2 text-xs text-slate-500">Sólo se muestran líneas de repuesto todavía no afectadas.</p>
            @endif
        </section>

        <form method="POST" action="{{ route('service-orders.part-requirements.store', [$order, $work]) }}" class="sulu-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            @if($warrantyMode)
                <div>
                    <label for="required_quantity" class="text-sm font-semibold text-slate-200">Cantidad cubierta</label>
                    <input id="required_quantity" name="required_quantity" type="text" required value="{{ old('required_quantity') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-orange-400 focus:ring-orange-400" placeholder="1">
                    <p class="mt-2 text-xs text-slate-500">La cantidad queda vinculada a la resolución correctiva, no a un presupuesto nuevo.</p>
                </div>
            @else
                <div>
                    <label for="service_quote_line_id" class="text-sm font-semibold text-slate-200">Línea aprobada</label>
                    <select id="service_quote_line_id" name="service_quote_line_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-orange-400 focus:ring-orange-400">
                        <option value="">Seleccionar</option>
                        @foreach($quoteLines as $line)
                            <option value="{{ $line->id }}" @selected((string) old('service_quote_line_id') === (string) $line->id)>
                                {{ $line->description }} · {{ $line->quantity }} · $ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">La cantidad se deriva en el servidor de la línea seleccionada.</p>
                </div>
            @endif

            <div>
                <label for="catalog_product_id" class="text-sm font-semibold text-slate-200">Producto del catálogo</label>
                <select id="catalog_product_id" name="catalog_product_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-orange-400 focus:ring-orange-400">
                    <option value="">Seleccionar</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old('catalog_product_id') === (string) $product->id)>
                            {{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }} · {{ $product->base_unit_code }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-2 text-xs text-slate-500">El producto define identidad, unidad y precisión; no crea stock por sí mismo.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="condition" class="text-sm font-semibold text-slate-200">Condición</label>
                    <select id="condition" name="condition" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-orange-400 focus:ring-orange-400">
                        @foreach($conditions as $condition)
                            <option value="{{ $condition->value }}" @selected(old('condition', \App\Enums\InventoryCondition::New->value) === $condition->value)>
                                {{ $condition->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="source" class="text-sm font-semibold text-slate-200">Origen</label>
                    <select id="source" name="source" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-orange-400 focus:ring-orange-400">
                        <option value="">Seleccionar</option>
                        @foreach($sources as $source)
                            <option value="{{ $source->value }}" @selected(old('source') === $source->value)>
                                {{ $source->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">Stock propio genera una salida confirmada al consumir. Compra para la orden nunca infla el stock general.</p>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                <button type="submit" class="rounded-xl bg-orange-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-orange-300">Guardar necesidad</button>
            </div>
        </form>
    </div>
</x-app-layout>
