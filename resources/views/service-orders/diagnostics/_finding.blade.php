<article data-finding-row class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
    <div class="grid gap-4 lg:grid-cols-[12rem_14rem_minmax(0,1fr)_auto] lg:items-end">
        <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Severidad</label>
            <select name="findings[{{ $index }}][severity]" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-violet-400 focus:ring-violet-400">
                @foreach($severities as $severity)
                    <option value="{{ $severity->value }}" @selected(($finding['severity'] ?? '') === $severity->value)>{{ $severity->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Categoría</label>
            <input name="findings[{{ $index }}][category]" value="{{ $finding['category'] ?? '' }}" required placeholder="Pantalla, software, disco..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-violet-400 focus:ring-violet-400">
        </div>
        <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Hallazgo técnico</label>
            <input name="findings[{{ $index }}][description]" value="{{ $finding['description'] ?? '' }}" required placeholder="Qué se verificó concretamente" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-violet-400 focus:ring-violet-400">
        </div>
        <button type="button" data-remove-finding class="rounded-xl border border-red-500/30 px-3 py-2.5 text-xs font-bold text-red-300 transition hover:bg-red-500/10">Quitar</button>
    </div>
    <div class="mt-4">
        <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Evidencia o prueba <span class="normal-case font-normal">(opcional)</span></label>
        <textarea name="findings[{{ $index }}][evidence_notes]" rows="2" placeholder="Prueba realizada, medición, fotografía o referencia..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-violet-400 focus:ring-violet-400">{{ $finding['evidence_notes'] ?? '' }}</textarea>
    </div>
</article>
