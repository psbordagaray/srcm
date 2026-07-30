@php
    $editing = isset($offer);
    $currentSupplierId = old('supplier_id', $offer->supplier_id ?? $selectedSupplierId);
    $currentProductId = old('catalog_product_id', $offer->catalog_product_id ?? $selectedProductId);
@endphp

<div class="space-y-6">
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="supplier_id" class="mb-2 block text-sm font-semibold text-slate-200">
                Proveedor
            </label>

            <select
                id="supplier_id"
                name="supplier_id"
                required
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                <option value="">Seleccionar proveedor</option>

                @foreach ($suppliers as $supplier)
                    <option
                        value="{{ $supplier->id }}"
                        @selected((string) $currentSupplierId === (string) $supplier->id)
                    >
                        {{ $supplier->party->name }}{{ $supplier->active ? '' : ' — Inactivo' }}
                    </option>
                @endforeach
            </select>

            @error('supplier_id')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="catalog_product_id" class="mb-2 block text-sm font-semibold text-slate-200">
                Producto maestro
            </label>

            <select
                id="catalog_product_id"
                name="catalog_product_id"
                required
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                <option value="">Seleccionar producto</option>

                @foreach ($products as $product)
                    <option
                        value="{{ $product->id }}"
                        @selected((string) $currentProductId === (string) $product->id)
                    >
                        {{ $product->sku }} — {{ $product->name }}{{ $product->active ? '' : ' — Inactivo' }}
                    </option>
                @endforeach
            </select>

            @error('catalog_product_id')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="supplier_code" class="mb-2 block text-sm font-semibold text-slate-200">
            Código del proveedor
        </label>

        <input
            id="supplier_code"
            name="supplier_code"
            type="text"
            maxlength="120"
            value="{{ old('supplier_code', $offer->supplier_code ?? '') }}"
            placeholder="Ejemplo: PROV-001928"
            class="w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <p class="mt-2 text-xs leading-5 text-slate-500">
            Puede quedar vacío. Dentro de un proveedor, un mismo código no puede identificar productos distintos.
        </p>

        @error('supplier_code')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="published_description" class="mb-2 block text-sm font-semibold text-slate-200">
            Descripción publicada por el proveedor
        </label>

        <textarea
            id="published_description"
            name="published_description"
            rows="3"
            maxlength="2000"
            placeholder="Nombre o descripción usada en la lista, web o catálogo del proveedor."
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >{{ old('published_description', $offer->published_description ?? '') }}</textarea>

        @error('published_description')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <div>
            <label for="cost_amount" class="mb-2 block text-sm font-semibold text-slate-200">
                Costo informado
            </label>

            <input
                id="cost_amount"
                name="cost_amount"
                type="text"
                inputmode="decimal"
                value="{{ old('cost_amount', $offer->cost_amount ?? '') }}"
                placeholder="0,00"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            @error('cost_amount')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="currency" class="mb-2 block text-sm font-semibold text-slate-200">
                Moneda
            </label>

            <input
                id="currency"
                name="currency"
                type="text"
                maxlength="3"
                value="{{ old('currency', $offer->currency ?? 'ARS') }}"
                placeholder="ARS"
                class="w-full rounded-xl border-slate-700 bg-slate-950 font-mono uppercase text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            @error('currency')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="availability_status" class="mb-2 block text-sm font-semibold text-slate-200">
                Disponibilidad informada
            </label>

            <select
                id="availability_status"
                name="availability_status"
                required
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                @foreach ($availabilityOptions as $value => $label)
                    <option
                        value="{{ $value }}"
                        @selected(old('availability_status', $offer->availability_status ?? 'unknown') === $value)
                    >
                        {{ $label }}
                    </option>
                @endforeach
            </select>

            @error('availability_status')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="checked_at" class="mb-2 block text-sm font-semibold text-slate-200">
                Fecha de verificación
            </label>

            <input
                id="checked_at"
                name="checked_at"
                type="date"
                required
                max="{{ now()->toDateString() }}"
                value="{{ old('checked_at', isset($offer) ? $offer->checked_at?->toDateString() : now()->toDateString()) }}"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >

            @error('checked_at')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="source_url" class="mb-2 block text-sm font-semibold text-slate-200">
                URL de origen
            </label>

            <input
                id="source_url"
                name="source_url"
                type="text"
                maxlength="2048"
                value="{{ old('source_url', $offer->source_url ?? '') }}"
                placeholder="proveedor.com/producto"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            @error('source_url')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="commercial_terms" class="mb-2 block text-sm font-semibold text-slate-200">
            Condiciones comerciales
        </label>

        <textarea
            id="commercial_terms"
            name="commercial_terms"
            rows="4"
            maxlength="5000"
            placeholder="Compra mínima, forma de pago, plazo, bonificaciones u otras condiciones."
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >{{ old('commercial_terms', $offer->commercial_terms ?? '') }}</textarea>

        @error('commercial_terms')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="active" class="mb-2 block text-sm font-semibold text-slate-200">
            Estado de la oferta
        </label>

        <select
            id="active"
            name="active"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option value="1" @selected((string) old('active', isset($offer) ? (int) $offer->active : 1) === '1')>
                Activa
            </option>
            <option value="0" @selected((string) old('active', isset($offer) ? (int) $offer->active : 1) === '0')>
                Inactiva
            </option>
        </select>

        @error('active')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-4 text-xs leading-5 text-amber-100">
        Esta ficha no crea stock, una compra, una deuda, una venta ni un precio final al cliente.
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
        <a
            href="{{ $editing ? route('supplier-offers.show', $offer) : route('supplier-offers.index') }}"
            class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
        >
            Cancelar
        </a>

        <button
            type="submit"
            class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
        >
            {{ $editing ? 'Guardar cambios' : 'Crear oferta' }}
        </button>
    </div>
</div>
