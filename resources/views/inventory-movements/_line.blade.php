@php
    $line = $line ?? [];
    $field = fn (string $name, mixed $default = ''): mixed => data_get(
        $line,
        $name,
        $default
    );
@endphp

<article data-movement-line class="rounded-2xl border border-slate-800 bg-slate-950/40 p-5">
    <div class="flex items-center justify-between gap-4">
        <h3 class="text-sm font-semibold text-white">
            Línea <span data-line-number>{{ is_numeric($index) ? ((int) $index + 1) : '' }}</span>
        </h3>
        <button type="button" data-remove-line class="text-xs font-semibold text-red-300 transition hover:text-red-200">Quitar</button>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-12">
        <label class="block xl:col-span-5">
            <span class="text-xs font-semibold text-slate-300">Producto</span>
            <select name="lines[{{ $index }}][catalog_product_id]" required data-product class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-900 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                <option value="">Seleccionar producto</option>
                @foreach($products as $product)
                    <option
                        value="{{ $product->id }}"
                        data-unit="{{ $product->baseUnit()->label() }}"
                        data-scale="{{ $product->quantity_scale }}"
                        @selected((string) $field('catalog_product_id') === (string) $product->id)
                    >
                        {{ $product->sku }} · {{ $product->name }}
                    </option>
                @endforeach
            </select>
            <span data-product-unit class="mt-1 block text-xs text-slate-500"></span>
        </label>

        <label class="block xl:col-span-3">
            <span class="text-xs font-semibold text-slate-300">Condición</span>
            <select name="lines[{{ $index }}][condition]" required class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-900 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                @foreach($conditions as $condition)
                    <option value="{{ $condition->value }}" @selected($field('condition', 'new') === $condition->value)>{{ $condition->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="block xl:col-span-4">
            <span class="text-xs font-semibold text-slate-300">Cantidad</span>
            <input
                type="text"
                inputmode="decimal"
                name="lines[{{ $index }}][entered_quantity]"
                value="{{ $field('entered_quantity') }}"
                required
                placeholder="Ej.: 1 o 2,500"
                class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-900 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >
        </label>

        <label data-source-field class="block xl:col-span-4">
            <span class="text-xs font-semibold text-slate-300">Ubicación de origen</span>
            <select name="lines[{{ $index }}][source_location_id]" data-source class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-900 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                <option value="">Sin origen</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" @selected((string) $field('source_location_id') === (string) $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
        </label>

        <label data-destination-field class="block xl:col-span-4">
            <span class="text-xs font-semibold text-slate-300">Ubicación de destino</span>
            <select name="lines[{{ $index }}][destination_location_id]" data-destination class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-900 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                <option value="">Sin destino</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" @selected((string) $field('destination_location_id') === (string) $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block xl:col-span-4">
            <span class="text-xs font-semibold text-slate-300">Nota de línea</span>
            <input
                type="text"
                name="lines[{{ $index }}][notes]"
                value="{{ $field('notes') }}"
                maxlength="1000"
                placeholder="Opcional"
                class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-900 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >
        </label>
    </div>
</article>
