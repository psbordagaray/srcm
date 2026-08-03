<div data-quote-line class="grid gap-3 rounded-xl border border-slate-800 bg-slate-950/70 p-3 lg:grid-cols-[10rem_minmax(0,1fr)_8rem_11rem_auto] lg:items-end">
    <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tipo</label>
        <select name="options[{{ $optionIndex }}][lines][{{ $lineIndex }}][type]" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">
            @foreach($lineTypes as $type)<option value="{{ $type->value }}" @selected(($line['type'] ?? '') === $type->value)>{{ $type->label() }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Concepto</label>
        <input name="options[{{ $optionIndex }}][lines][{{ $lineIndex }}][description]" value="{{ $line['description'] ?? '' }}" required placeholder="Repuesto, tarea o logística..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-amber-400 focus:ring-amber-400">
    </div>
    <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cantidad</label>
        <input name="options[{{ $optionIndex }}][lines][{{ $lineIndex }}][quantity]" value="{{ $line['quantity'] ?? '1' }}" inputmode="decimal" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">
    </div>
    <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Precio unitario</label>
        <div class="relative mt-2"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500">$</span><input name="options[{{ $optionIndex }}][lines][{{ $lineIndex }}][unit_price]" value="{{ $line['unit_price'] ?? '' }}" inputmode="decimal" required placeholder="0,00" class="w-full rounded-xl border-slate-700 bg-slate-950 py-2 pl-7 pr-3 text-sm text-white placeholder:text-slate-600 focus:border-amber-400 focus:ring-amber-400"></div>
    </div>
    <button type="button" data-remove-line class="rounded-xl border border-red-500/30 px-3 py-2.5 text-xs font-bold text-red-300 transition hover:bg-red-500/10">Quitar</button>
</div>
