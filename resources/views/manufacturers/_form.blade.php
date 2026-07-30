@php
    $editing = isset($manufacturer);
@endphp

<div class="space-y-6">
    <div>
        <label
            for="name"
            class="mb-2 block text-sm font-semibold text-slate-200"
        >
            Nombre del fabricante
        </label>

        <input
            id="name"
            name="name"
            type="text"
            required
            maxlength="255"
            value="{{ old('name', $manufacturer->name ?? '') }}"
            placeholder="Ejemplo: TP Vision"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <p class="mt-2 text-xs text-slate-500">
            SRCM evita duplicados aunque cambien mayúsculas,
            espacios o signos del nombre.
        </p>

        @error('name')
            <p class="mt-2 text-sm text-red-400">
                {{ $message }}
            </p>
        @enderror
    </div>

    <div>
        <label
            for="website"
            class="mb-2 block text-sm font-semibold text-slate-200"
        >
            Sitio web oficial
        </label>

        <input
            id="website"
            name="website"
            type="text"
            maxlength="2048"
            value="{{ old('website', $manufacturer->website ?? '') }}"
            placeholder="tpvision.com"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <p class="mt-2 text-xs text-slate-500">
            Podés escribir el dominio sin https://; SRCM lo normalizará.
        </p>

        @error('website')
            <p class="mt-2 text-sm text-red-400">
                {{ $message }}
            </p>
        @enderror
    </div>

    <div>
        <label
            for="description"
            class="mb-2 block text-sm font-semibold text-slate-200"
        >
            Descripción
        </label>

        <textarea
            id="description"
            name="description"
            rows="5"
            maxlength="5000"
            placeholder="Qué produce, líneas conocidas o contexto verificable..."
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >{{ old('description', $manufacturer->description ?? '') }}</textarea>

        @error('description')
            <p class="mt-2 text-sm text-red-400">
                {{ $message }}
            </p>
        @enderror
    </div>

    <div>
        <label
            for="active"
            class="mb-2 block text-sm font-semibold text-slate-200"
        >
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
                @selected((string) old('active', isset($manufacturer) ? (int) $manufacturer->active : 1) === '1')
            >
                Activo
            </option>

            <option
                value="0"
                @selected((string) old('active', isset($manufacturer) ? (int) $manufacturer->active : 1) === '0')
            >
                Inactivo
            </option>
        </select>

        @error('active')
            <p class="mt-2 text-sm text-red-400">
                {{ $message }}
            </p>
        @enderror
    </div>

    <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/10 px-4 py-4">
        <p class="text-sm font-semibold text-cyan-200">
            Identidad maestra
        </p>

        <p class="mt-1 text-xs leading-5 text-slate-400">
            El fabricante describe quién produce el artículo.
            No representa una oferta, un costo, un proveedor ni una ubicación.
        </p>
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
        <a
            href="{{ route('manufacturers.index') }}"
            class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
        >
            Cancelar
        </a>

        <button
            type="submit"
            class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
        >
            {{ $editing ? 'Guardar cambios' : 'Crear fabricante' }}
        </button>
    </div>
</div>
