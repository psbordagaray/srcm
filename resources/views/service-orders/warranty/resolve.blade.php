<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Reparaciones · Garantía</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Resolver reclamo · Orden correctiva #{{ $claim->correctiveOrder->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">La resolución es administrativa, inmutable y define si la orden puede avanzar sin presupuesto comercial.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('warranty_claim'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('warranty_claim') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-3">
            <article class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5 md:col-span-2"><p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Falla reclamada</p><p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-200">{{ $claim->reported_issue }}</p><p class="mt-3 text-xs text-slate-500">Condición al reingresar: {{ $claim->reentry_condition_notes }}</p></article>
            <article class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5"><p class="text-xs font-semibold uppercase tracking-wider text-amber-300">Estado temporal</p><p class="mt-3 text-lg font-bold text-white">{{ $claim->warranty_status_at_claim->label() }}</p><p class="mt-2 text-xs text-slate-400">La garantía vencía {{ $claim->warrantyGrant->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.</p></article>
        </section>

        <form method="POST" action="{{ route('service-orders.warranty-claims.resolution.store', [$order, $claim]) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Resultado y fundamento</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="outcome" class="text-sm font-semibold text-slate-200">Resultado</label>
                        <select id="outcome" name="outcome" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">
                            <option value="">Seleccionar…</option>
                            @foreach($outcomes as $outcome)
                                <option value="{{ $outcome->value }}" @selected(old('outcome') === $outcome->value)>{{ $outcome->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('outcome')" class="mt-2" />
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 text-xs leading-5 text-slate-400"><strong class="text-slate-200">Reglas:</strong> aceptación total exige sólo alcance cubierto; aceptación parcial exige cubierto y excluido; rechazo exige sólo alcance excluido.</div>
                    <div class="md:col-span-2"><label for="technical_basis" class="text-sm font-semibold text-slate-200">Fundamento técnico</label><textarea id="technical_basis" name="technical_basis" rows="5" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">{{ old('technical_basis') }}</textarea><x-input-error :messages="$errors->get('technical_basis')" class="mt-2" /></div>
                    <div><label for="covered_scope" class="text-sm font-semibold text-emerald-300">Alcance cubierto</label><textarea id="covered_scope" name="covered_scope" rows="5" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-emerald-400 focus:ring-emerald-400">{{ old('covered_scope') }}</textarea><x-input-error :messages="$errors->get('covered_scope')" class="mt-2" /></div>
                    <div><label for="excluded_scope" class="text-sm font-semibold text-rose-300">Alcance excluido</label><textarea id="excluded_scope" name="excluded_scope" rows="5" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400">{{ old('excluded_scope') }}</textarea><x-input-error :messages="$errors->get('excluded_scope')" class="mt-2" /></div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Excepción y notas</h2>
                @if($claim->warranty_status_at_claim === \App\Enums\ServiceWarrantyTemporalStatus::Expired)
                    <p class="mt-2 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-200">Una aceptación total o parcial fuera de término requiere un motivo administrativo verificable.</p>
                @endif
                <div class="mt-5 space-y-5">
                    <div><label for="exception_reason" class="text-sm font-semibold text-slate-200">Motivo de excepción administrativa</label><textarea id="exception_reason" name="exception_reason" rows="4" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">{{ old('exception_reason') }}</textarea><x-input-error :messages="$errors->get('exception_reason')" class="mt-2" /></div>
                    <div><label for="notes" class="text-sm font-semibold text-slate-200">Notas internas</label><textarea id="notes" name="notes" rows="4" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">{{ old('notes') }}</textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-amber-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-amber-300">Registrar resolución inmutable</button>
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
