@php
    $editing = isset($party);
@endphp

<div class="grid gap-6 md:grid-cols-2">
    <div>
        <label for="party_type" class="block text-sm font-medium text-slate-300">
            Tipo de identidad
        </label>
        <select
            id="party_type"
            name="party_type"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
            required
        >
            <option value="person" @selected(old('party_type', $party->party_type ?? 'person') === 'person')>
                Persona
            </option>
            <option value="organization" @selected(old('party_type', $party->party_type ?? '') === 'organization')>
                Organización / empresa
            </option>
        </select>
        @error('party_type')
            <p class="mt-1 text-sm text-rose-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="name" class="block text-sm font-medium text-slate-300">
            Nombre / razón social
        </label>
        <input
            id="name"
            name="name"
            type="text"
            value="{{ old('name', $party->name ?? '') }}"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
            maxlength="255"
            required
        >
        @error('name')
            <p class="mt-1 text-sm text-rose-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="tax_id" class="block text-sm font-medium text-slate-300">
            DNI / CUIT / documento fiscal
        </label>
        <input
            id="tax_id"
            name="tax_id"
            type="text"
            value="{{ old('tax_id', $party->tax_id ?? '') }}"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
            maxlength="64"
        >
        @error('tax_id')
            <p class="mt-1 text-sm text-rose-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="email" class="block text-sm font-medium text-slate-300">
            Correo
        </label>
        <input
            id="email"
            name="email"
            type="email"
            value="{{ old('email', $party->email ?? '') }}"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
            maxlength="255"
        >
        @error('email')
            <p class="mt-1 text-sm text-rose-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="phone" class="block text-sm font-medium text-slate-300">
            Teléfono
        </label>
        <input
            id="phone"
            name="phone"
            type="text"
            value="{{ old('phone', $party->phone ?? '') }}"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
            maxlength="80"
        >
        @error('phone')
            <p class="mt-1 text-sm text-rose-400">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="website" class="block text-sm font-medium text-slate-300">
            Sitio web
        </label>
        <input
            id="website"
            name="website"
            type="text"
            value="{{ old('website', $party->website ?? '') }}"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
            maxlength="2048"
            placeholder="https://..."
        >
        @error('website')
            <p class="mt-1 text-sm text-rose-400">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-8 flex flex-wrap items-center gap-3">
    <button
        type="submit"
        class="rounded-xl bg-cyan-400 px-5 py-2.5 font-semibold text-slate-950 hover:bg-cyan-300"
    >
        {{ $editing ? 'Guardar identidad' : 'Registrar identidad' }}
    </button>

    <a
        href="{{ $editing ? route('business-parties.show', $party) : route('business-parties.index') }}"
        class="rounded-xl border border-slate-700 px-5 py-2.5 font-semibold text-slate-200 hover:bg-slate-800"
    >
        Cancelar
    </a>
</div>
