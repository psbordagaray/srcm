@php
    $editing = isset($technicalModel);
@endphp

<div class="space-y-6">

    <div>
        <label for="brand_id" class="mb-2 block text-sm font-semibold text-slate-200">
            Marca
        </label>

        <select
            id="brand_id"
            name="brand_id"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option value="">Seleccionar marca</option>

            @foreach ($brands as $brand)
                <option
                    value="{{ $brand->id }}"
                    @selected((string) old('brand_id', $technicalModel->brand_id ?? '') === (string) $brand->id)
                >
                    {{ $brand->name }}
                    {{ $brand->active ? '' : '(inactiva)' }}
                </option>
            @endforeach
        </select>

        @error('brand_id')
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
                    @selected((string) old('product_category_id', $technicalModel->product_category_id ?? '') === (string) $category->id)
                >
                    {{ $category->name }}
                    {{ $category->active ? '' : '(inactiva)' }}
                </option>
            @endforeach
        </select>

        @error('product_category_id')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="code" class="mb-2 block text-sm font-semibold text-slate-200">
            Código técnico
        </label>

        <input
            id="code"
            name="code"
            type="text"
            required
            maxlength="100"
            value="{{ old('code', $technicalModel->code ?? '') }}"
            placeholder="Ej.: 43LM6300, SM-A325F"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <p class="mt-2 text-xs text-slate-500">
            Es el identificador técnico principal del modelo.
        </p>

        @error('code')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="name" class="mb-2 block text-sm font-semibold text-slate-200">
            Nombre comercial
            <span class="font-normal text-slate-500">(opcional)</span>
        </label>

        <input
            id="name"
            name="name"
            type="text"
            maxlength="255"
            value="{{ old('name', $technicalModel->name ?? '') }}"
            placeholder="Ej.: Galaxy A32"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        @error('name')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="description" class="mb-2 block text-sm font-semibold text-slate-200">
            Descripción
            <span class="font-normal text-slate-500">(opcional)</span>
        </label>

        <textarea
            id="description"
            name="description"
            rows="4"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            placeholder="Información general del modelo..."
        >{{ old('description', $technicalModel->description ?? '') }}</textarea>

        @error('description')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
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
                @selected((string) old('active', isset($technicalModel) ? (int) $technicalModel->active : 1) === '1')
            >
                Activo
            </option>

            <option
                value="0"
                @selected((string) old('active', isset($technicalModel) ? (int) $technicalModel->active : 1) === '0')
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
            Una sola carga
        </p>

        <p class="mt-1 text-xs leading-5 text-slate-400">
            {{ $editing
                ? 'Los cambios de código, nombre, categoría y estado también se sincronizarán con la ficha de conocimiento.'
                : 'Al guardar, SRCM creará o vinculará la ficha de conocimiento y abrirá la pantalla de compatibilidades.' }}
        </p>
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
        <a
            href="{{ route('technical-models.index') }}"
            class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
        >
            Cancelar
        </a>

        <button
            type="submit"
            class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
        >
            {{ $editing ? 'Guardar cambios' : 'Crear modelo técnico' }}
        </button>
    </div>

</div>
