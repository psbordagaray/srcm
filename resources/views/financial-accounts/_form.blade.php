@php
    $editing = isset($account);
@endphp

<div class="grid gap-6 md:grid-cols-2">
    <div>
        <label for="name" class="text-xs font-bold uppercase tracking-wider text-slate-400">
            Nombre
        </label>
        <input
            id="name"
            name="name"
            type="text"
            maxlength="120"
            required
            value="{{ old('name', $account->name ?? '') }}"
            placeholder="Ej. Mercado Pago principal"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
    </div>

    <div>
        <label for="type" class="text-xs font-bold uppercase tracking-wider text-slate-400">
            Tipo
        </label>
        <select
            id="type"
            name="type"
            required
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option value="">Seleccionar…</option>
            @foreach($types as $type)
                <option
                    value="{{ $type->value }}"
                    @selected(old('type', isset($account) ? $account->type->value : '') === $type->value)
                >
                    {{ $type->label() }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="currency_code" class="text-xs font-bold uppercase tracking-wider text-slate-400">
            Moneda
        </label>
        <input
            id="currency_code"
            name="currency_code"
            type="text"
            maxlength="3"
            required
            value="{{ old('currency_code', $account->currency_code ?? 'ARS') }}"
            placeholder="ARS"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono uppercase text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
        <p class="mt-1 text-xs text-slate-500">Código ISO de tres letras. No cambia después del primer movimiento externo.</p>
    </div>

    <div>
        <label for="provider" class="text-xs font-bold uppercase tracking-wider text-slate-400">
            Proveedor / institución
        </label>
        <input
            id="provider"
            name="provider"
            type="text"
            maxlength="100"
            value="{{ old('provider', $account->provider ?? '') }}"
            placeholder="Mercado Pago, Banco…, Payway…"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
    </div>

    <div class="md:col-span-2">
        <label for="external_label" class="text-xs font-bold uppercase tracking-wider text-slate-400">
            Referencia externa
        </label>
        <input
            id="external_label"
            name="external_label"
            type="text"
            maxlength="191"
            value="{{ old('external_label', $account->external_label ?? '') }}"
            placeholder="Alias, sucursal, identificador visible o referencia operativa"
            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
    </div>
</div>

<div class="mt-8 flex flex-wrap justify-end gap-3">
    <a
        href="{{ route('financial-accounts.index') }}"
        class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-bold text-slate-300 hover:border-slate-500 hover:text-white"
    >
        Cancelar
    </a>
    <button
        type="submit"
        class="rounded-xl bg-amber-400 px-6 py-3 text-sm font-black text-slate-950 hover:bg-amber-300"
    >
        {{ $editing ? 'Guardar cambios' : 'Crear cuenta' }}
    </button>
</div>
