@php
    $editing = isset($product);
@endphp

<div class="space-y-6">
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="sku" class="mb-2 block text-sm font-semibold text-slate-200">
                SKU o código principal
            </label>

            <input
                id="sku"
                name="sku"
                type="text"
                required
                maxlength="120"
                value="{{ old('sku', $product->sku ?? '') }}"
                placeholder="Ejemplo: AKB75095308"
                class="w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            <p class="mt-2 text-xs text-slate-500">
                Identidad interna única. SRCM ignora mayúsculas, espacios y signos al detectar duplicados.
            </p>

            @error('sku')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="product_category_id" class="mb-2 block text-sm font-semibold text-slate-200">
                Categoría
            </label>

            <select
                id="product_category_id"
                name="product_category_id"
                required
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                <option value="">Seleccionar categoría</option>

                @foreach ($categories as $category)
                    <option
                        value="{{ $category->id }}"
                        @selected((string) old('product_category_id', $product->product_category_id ?? '') === (string) $category->id)
                    >
                        {{ $category->name }}{{ $category->active ? '' : ' — Inactiva' }}
                    </option>
                @endforeach
            </select>

            @error('product_category_id')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="name" class="mb-2 block text-sm font-semibold text-slate-200">
            Nombre canónico
        </label>

        <input
            id="name"
            name="name"
            type="text"
            required
            maxlength="255"
            value="{{ old('name', $product->name ?? '') }}"
            placeholder="Ejemplo: Control remoto LG AKB75095308"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <p class="mt-2 text-xs text-slate-500">
            Debe identificar el artículo con precisión. Alias y errores frecuentes se registran luego como identificadores adicionales.
        </p>

        @error('name')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="brand_id" class="mb-2 block text-sm font-semibold text-slate-200">
                Marca
            </label>

            <select
                id="brand_id"
                name="brand_id"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                <option value="">Sin marca o genérico</option>

                @foreach ($brands as $brand)
                    <option
                        value="{{ $brand->id }}"
                        @selected((string) old('brand_id', $product->brand_id ?? '') === (string) $brand->id)
                    >
                        {{ $brand->name }}{{ $brand->active ? '' : ' — Inactiva' }}
                    </option>
                @endforeach
            </select>

            @error('brand_id')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="manufacturer_id" class="mb-2 block text-sm font-semibold text-slate-200">
                Fabricante
            </label>

            <select
                id="manufacturer_id"
                name="manufacturer_id"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                <option value="">No informado</option>

                @foreach ($manufacturers as $manufacturer)
                    <option
                        value="{{ $manufacturer->id }}"
                        @selected((string) old('manufacturer_id', $product->manufacturer_id ?? '') === (string) $manufacturer->id)
                    >
                        {{ $manufacturer->name }}{{ $manufacturer->active ? '' : ' — Inactivo' }}
                    </option>
                @endforeach
            </select>

            @error('manufacturer_id')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="description" class="mb-2 block text-sm font-semibold text-slate-200">
            Descripción técnica
        </label>

        <textarea
            id="description"
            name="description"
            rows="5"
            maxlength="5000"
            placeholder="Características estables del artículo; no incluir precio, stock ni datos del proveedor."
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >{{ old('description', $product->description ?? '') }}</textarea>

        @error('description')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div class="rounded-xl border border-slate-700 bg-slate-950/40 p-4">
        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <label for="base_unit_code" class="mb-2 block text-sm font-semibold text-slate-200">
                    Unidad base de inventario
                </label>

                <select
                    id="base_unit_code"
                    name="base_unit_code"
                    required
                    class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                >
                    @foreach ($baseUnits as $baseUnit)
                        <option
                            value="{{ $baseUnit->value }}"
                            @selected((string) old('base_unit_code', $product->base_unit_code ?? 'unit') === $baseUnit->value)
                        >
                            {{ $baseUnit->label() }} ({{ $baseUnit->value }})
                        </option>
                    @endforeach
                </select>

                @error('base_unit_code')
                    <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="quantity_scale" class="mb-2 block text-sm font-semibold text-slate-200">
                    Precisión de cantidad
                </label>

                <select
                    id="quantity_scale"
                    name="quantity_scale"
                    required
                    class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                >
                    @for ($scale = 0; $scale <= 6; $scale++)
                        <option
                            value="{{ $scale }}"
                            @selected((string) old('quantity_scale', $product->quantity_scale ?? 0) === (string) $scale)
                        >
                            {{ $scale === 0 ? 'Solo cantidades enteras' : $scale.' decimal'.($scale === 1 ? '' : 'es') }}
                        </option>
                    @endfor
                </select>

                @error('quantity_scale')
                    <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <p class="mt-3 text-xs leading-5 text-slate-400">
            La venta fraccionada se habilita por artículo: elegí litro, metro o kilogramo y una precisión mayor que cero.
            Para SULU y demás artículos indivisibles, conservá Unidad y Solo cantidades enteras.
            Esta configuración queda fija desde el primer movimiento.
        </p>
    </div>

    <div>
        <label for="active" class="mb-2 block text-sm font-semibold text-slate-200">
            Estado
        </label>

        <select
            id="active"
            name="active"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option
                value="1"
                @selected((string) old('active', isset($product) ? (int) $product->active : 1) === '1')
            >
                Activo
            </option>

            <option
                value="0"
                @selected((string) old('active', isset($product) ? (int) $product->active : 1) === '0')
            >
                Inactivo
            </option>
        </select>

        @error('active')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/10 px-4 py-4">
        <p class="text-sm font-semibold text-cyan-200">
            Ficha de conocimiento automática
        </p>

        <p class="mt-1 text-xs leading-5 text-slate-400">
            SRCM creará o vinculará una ficha catalog-product con el SKU como código principal.
            Desde esa ficha se administran códigos alternativos y compatibilidades.
        </p>
    </div>

    <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-4 text-xs leading-5 text-amber-100">
        Precio, costo, stock, proveedor, garantía, condición y ubicación no pertenecen al artículo maestro.
        La unidad base y su precisión sí forman parte de su identidad operativa.
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
        <a
            href="{{ route('products.index') }}"
            class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
        >
            Cancelar
        </a>

        <button
            type="submit"
            class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
        >
            {{ $editing ? 'Guardar cambios' : 'Crear producto' }}
        </button>
    </div>
</div>
