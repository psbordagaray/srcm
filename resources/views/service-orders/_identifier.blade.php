<div data-identifier-row class="grid gap-3 rounded-xl border border-slate-800 bg-slate-950/50 p-4 sm:grid-cols-[14rem_minmax(0,1fr)_auto] sm:items-end">
    <div>
        <label class="text-xs font-semibold text-slate-300">Tipo</label>
        <select name="identifiers[{{ $index }}][type]" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
            <option value="">Seleccionar</option>
            @foreach($identifierTypes as $identifierType)
                <option value="{{ $identifierType->value }}" @selected(($identifier['type'] ?? '') === $identifierType->value)>{{ $identifierType->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-xs font-semibold text-slate-300">Valor</label>
        <input name="identifiers[{{ $index }}][value]" value="{{ $identifier['value'] ?? '' }}" placeholder="Ej.: 358123456789012" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
        <x-input-error :messages="$errors->get('identifiers.'.$index.'.value')" class="mt-2" />
    </div>
    <button type="button" data-remove-identifier class="rounded-xl border border-red-500/30 px-3 py-2.5 text-xs font-bold text-red-300 transition hover:bg-red-500/10">Quitar</button>
</div>
