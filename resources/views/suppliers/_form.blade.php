@php
    $editing = isset($supplier);
    $party = $supplier->party ?? null;
@endphp

<div class="space-y-6">
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="party_type" class="mb-2 block text-sm font-semibold text-slate-200">
                Tipo de proveedor
            </label>

            <select
                id="party_type"
                name="party_type"
                required
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
            >
                <option value="">Seleccionar tipo</option>
                <option
                    value="organization"
                    @selected(old('party_type', $party->party_type ?? '') === 'organization')
                >
                    Empresa u organización
                </option>
                <option
                    value="person"
                    @selected(old('party_type', $party->party_type ?? '') === 'person')
                >
                    Persona
                </option>
            </select>

            @error('party_type')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="name" class="mb-2 block text-sm font-semibold text-slate-200">
                Nombre comercial o nombre completo
            </label>

            <input
                id="name"
                name="name"
                type="text"
                required
                maxlength="255"
                value="{{ old('name', $party->name ?? '') }}"
                placeholder="Ejemplo: Electrónica del Sur"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            <p class="mt-2 text-xs leading-5 text-slate-500">
                Esta identidad podrá asumir otros roles en el futuro sin volver a cargarse.
            </p>

            @error('name')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="tax_id" class="mb-2 block text-sm font-semibold text-slate-200">
                CUIT, CUIL o identificación fiscal
            </label>

            <input
                id="tax_id"
                name="tax_id"
                type="text"
                maxlength="64"
                value="{{ old('tax_id', $party->tax_id ?? '') }}"
                placeholder="Ejemplo: 30-12345678-9"
                class="w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            <p class="mt-2 text-xs text-slate-500">
                SRCM ignora guiones, espacios y mayúsculas para detectar equivalencias.
            </p>

            @error('tax_id')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email" class="mb-2 block text-sm font-semibold text-slate-200">
                Correo
            </label>

            <input
                id="email"
                name="email"
                type="email"
                maxlength="255"
                value="{{ old('email', $party->email ?? '') }}"
                placeholder="ventas@proveedor.com"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            @error('email')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="phone" class="mb-2 block text-sm font-semibold text-slate-200">
                Teléfono
            </label>

            <input
                id="phone"
                name="phone"
                type="text"
                maxlength="80"
                value="{{ old('phone', $party->phone ?? '') }}"
                placeholder="Ejemplo: +54 11 5555-5555"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            @error('phone')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="website" class="mb-2 block text-sm font-semibold text-slate-200">
                Sitio web
            </label>

            <input
                id="website"
                name="website"
                type="text"
                maxlength="2048"
                value="{{ old('website', $party->website ?? '') }}"
                placeholder="proveedor.com"
                class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            >

            <p class="mt-2 text-xs text-slate-500">
                Puede escribirse sin https://; SRCM lo normaliza.
            </p>

            @error('website')
                <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label for="notes" class="mb-2 block text-sm font-semibold text-slate-200">
            Nota interna del vínculo como proveedor
        </label>

        <textarea
            id="notes"
            name="notes"
            rows="5"
            maxlength="5000"
            placeholder="Condiciones generales, formas habituales de contacto o advertencias internas."
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >{{ old('notes', $supplier->notes ?? '') }}</textarea>

        @error('notes')
            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="active" class="mb-2 block text-sm font-semibold text-slate-200">
            Estado como proveedor
        </label>

        <select
            id="active"
            name="active"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option
                value="1"
                @selected((string) old('active', $editing ? (int) $supplier->active : 1) === '1')
            >
                Activo
            </option>
            <option
                value="0"
                @selected((string) old('active', $editing ? (int) $supplier->active : 1) === '0')
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
            Una identidad, varios roles
        </p>

        <p class="mt-1 text-xs leading-5 text-slate-400">
            Una persona o empresa podrá ser cliente y proveedor sin duplicarse.
            El rol comercial se conserva separado de su identidad.
        </p>
    </div>

    <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-4 text-xs leading-5 text-amber-100">
        El código del proveedor, su descripción publicada, costo, disponibilidad y URL del artículo pertenecen a cada oferta, no a esta ficha.
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
        <a
            href="{{ $editing ? route('suppliers.show', $supplier) : route('suppliers.index') }}"
            class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
        >
            Cancelar
        </a>

        <button
            type="submit"
            class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
        >
            {{ $editing ? 'Guardar cambios' : 'Crear proveedor' }}
        </button>
    </div>
</div>
