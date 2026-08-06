<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Reparaciones · Resultado técnico</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Cerrar trabajo</h1>
            <p class="mt-2 text-sm text-slate-400">Orden #{{ $order->order_number }} · Trabajo {{ $work->sequence }}: {{ $work->title }}</p>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="sulu-card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ $work->execution_mode->label() }}</p>
                    <p class="mt-2 text-lg font-bold text-white">{{ $work->assignedUser?->name ?? $work->provider?->name }}</p>
                </div>
                <span class="rounded-full border border-slate-700 bg-slate-950 px-3 py-1 text-xs text-slate-300">{{ $work->status->label() }}</span>
            </div>
            <p class="mt-4 whitespace-pre-line text-sm text-slate-300">{{ $work->description }}</p>
        </section>

        <form method="POST" action="{{ route('service-orders.work-items.report.store', [$order, $work]) }}" class="sulu-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <div>
                <label for="outcome" class="text-sm font-semibold text-slate-200">Resultado</label>
                <select id="outcome" name="outcome" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                    <option value="">Seleccionar</option>
                    @foreach($outcomes as $outcome)
                        <option value="{{ $outcome->value }}" @selected(old('outcome') === $outcome->value)>{{ $outcome->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="result_summary" class="text-sm font-semibold text-slate-200">Resumen del resultado</label>
                <input id="result_summary" name="result_summary" type="text" maxlength="1000" required value="{{ old('result_summary') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
            </div>

            <div>
                <label for="work_performed" class="text-sm font-semibold text-slate-200">Trabajo realizado</label>
                <textarea id="work_performed" name="work_performed" rows="6" maxlength="5000" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('work_performed') }}</textarea>
            </div>

            <div class="rounded-xl border border-red-500/20 bg-red-500/5 p-4">
                <label for="unresolved_reason" class="text-sm font-semibold text-red-200">Motivo sin solución</label>
                <textarea id="unresolved_reason" name="unresolved_reason" rows="4" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-red-400 focus:ring-red-400">{{ old('unresolved_reason') }}</textarea>
                <p class="mt-2 text-xs text-slate-500">Obligatorio únicamente cuando el resultado es “Sin solución”.</p>
            </div>

            <div class="grid gap-5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4 md:grid-cols-[10rem_minmax(0,1fr)]">
                <div>
                    <label for="warranty_days" class="text-sm font-semibold text-emerald-200">Días de garantía</label>
                    <input id="warranty_days" name="warranty_days" type="number" min="0" max="3650" value="{{ old('warranty_days') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                </div>
                <div>
                    <label for="warranty_terms" class="text-sm font-semibold text-emerald-200">Condiciones de garantía</label>
                    <textarea id="warranty_terms" name="warranty_terms" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('warranty_terms') }}</textarea>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                <button type="submit" class="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-emerald-300">Registrar resultado</button>
            </div>
        </form>
    </div>
</x-app-layout>
