<article data-quote-option data-option-index="{{ $optionIndex }}" class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex-1">
            <label class="text-sm font-semibold text-slate-200">Nombre de la alternativa</label>
            <input name="options[{{ $optionIndex }}][label]" value="{{ $option['label'] ?? '' }}" required placeholder="Ej.: Solución integral" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-amber-400 focus:ring-amber-400">
        </div>
        <div class="flex items-center gap-3 pt-7">
            <label class="flex items-center gap-2 text-sm font-semibold text-amber-200"><input type="hidden" name="options[{{ $optionIndex }}][recommended]" value="0"><input type="checkbox" data-recommended name="options[{{ $optionIndex }}][recommended]" value="1" @checked($option['recommended'] ?? false) class="rounded border-slate-600 bg-slate-950 text-amber-400 focus:ring-amber-400"> Recomendada</label>
            <button type="button" data-remove-option class="rounded-xl border border-red-500/30 px-3 py-2 text-xs font-bold text-red-300 transition hover:bg-red-500/10">Quitar alternativa</button>
        </div>
    </div>
    <div class="mt-4">
        <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Alcance <span class="normal-case font-normal">(opcional)</span></label>
        <textarea name="options[{{ $optionIndex }}][description]" rows="2" placeholder="Qué incluye, ventajas o límites de esta alternativa..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-amber-400 focus:ring-amber-400">{{ $option['description'] ?? '' }}</textarea>
    </div>
    <div class="mt-5 flex items-center justify-between gap-3">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Conceptos presupuestados</p>
        <button type="button" data-add-line class="rounded-xl border border-amber-500/40 px-3 py-2 text-xs font-bold text-amber-300 transition hover:bg-amber-500/10">Agregar concepto</button>
    </div>
    <div class="mt-3 space-y-3" data-option-lines>
        @foreach(($option['lines'] ?? []) as $lineIndex => $line)
            @include('service-orders.quotes._line', compact('optionIndex', 'lineIndex', 'line', 'lineTypes'))
        @endforeach
    </div>
</article>
