<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-orange-300">Reparaciones · Resolución administrativa</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Resolver cancelación · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">Decisión reservada a administración. Debe explicar trabajo, repuestos, dinero y condición de devolución.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('cancellation'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('cancellation') }}</div>
        @endif

        <section class="grid gap-4 lg:grid-cols-3">
            <article class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-5 lg:col-span-2">
                <p class="text-xs font-semibold uppercase tracking-wider text-rose-300">Solicitud registrada</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <strong class="text-white">{{ $cancellation->reason->label() }}</strong>
                    <span class="rounded-full bg-slate-950 px-2.5 py-1 text-xs text-slate-400">{{ $cancellation->channel }}</span>
                </div>
                <p class="mt-3 text-sm text-slate-300">{{ $cancellation->requester_name }} · {{ $cancellation->requested_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                @if($cancellation->details)<p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-400">{{ $cancellation->details }}</p>@endif
            </article>
            <article class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Exposición capturada</p>
                <dl class="mt-3 space-y-2 text-xs">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Trabajo iniciado</dt><dd class="font-semibold {{ $cancellation->has_started_work ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_started_work ? 'Sí' : 'No' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Compras de repuestos</dt><dd class="font-semibold {{ $cancellation->has_part_purchases ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_part_purchases ? 'Sí' : 'No' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Consumos</dt><dd class="font-semibold {{ $cancellation->has_part_consumptions ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_part_consumptions ? 'Sí' : 'No' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Custodia externa</dt><dd class="font-semibold {{ $cancellation->has_external_custody ? 'text-red-300' : 'text-emerald-300' }}">{{ $cancellation->has_external_custody ? 'Sí' : 'No' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Pagos registrados</dt><dd class="font-semibold {{ $cancellation->has_registered_payments ? 'text-amber-300' : 'text-emerald-300' }}">{{ $cancellation->has_registered_payments ? 'Sí' : 'No' }}</dd></div>
                </dl>
            </article>
        </section>

        <form method="POST" action="{{ route('service-orders.cancellation.resolution.store', [$order, $cancellation]) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">1. Resultado financiero</h2>
                <div class="mt-5 grid gap-5 lg:grid-cols-3">
                    <div>
                        <label for="financial_outcome" class="text-sm font-semibold text-slate-200">Resultado</label>
                        <select id="financial_outcome" name="financial_outcome" required data-financial-outcome class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">
                            @foreach($outcomes as $outcome)
                                <option value="{{ $outcome->value }}" @selected(old('financial_outcome', 'no_charge') === $outcome->value)>{{ $outcome->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('financial_outcome')" class="mt-2" />
                    </div>
                    <div>
                        <label for="currency_code" class="text-sm font-semibold text-slate-200">Moneda</label>
                        <input id="currency_code" name="currency_code" type="text" required maxlength="3" value="{{ old('currency_code', 'ARS') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-sm uppercase text-white focus:border-orange-400 focus:ring-orange-400">
                        <x-input-error :messages="$errors->get('currency_code')" class="mt-2" />
                    </div>
                    <div data-charge-fields>
                        <label for="customer_charge" class="text-sm font-semibold text-slate-200">Cargo acordado</label>
                        <input id="customer_charge" name="customer_charge" type="text" inputmode="decimal" value="{{ old('customer_charge') }}" placeholder="0,00" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-sm text-white placeholder:text-slate-600 focus:border-orange-400 focus:ring-orange-400">
                        <x-input-error :messages="$errors->get('customer_charge')" class="mt-2" />
                    </div>
                    <div class="lg:col-span-3" data-charge-fields>
                        <label for="customer_acceptance_reference" class="text-sm font-semibold text-slate-200">Aceptación verificable del cliente</label>
                        <input id="customer_acceptance_reference" name="customer_acceptance_reference" type="text" value="{{ old('customer_acceptance_reference') }}" placeholder="Mensaje, llamada registrada, documento o referencia concreta…" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-orange-400 focus:ring-orange-400">
                        <x-input-error :messages="$errors->get('customer_acceptance_reference')" class="mt-2" />
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">2. Disposiciones obligatorias</h2>
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div><label for="work_disposition" class="text-sm font-semibold text-slate-200">Trabajo realizado o detenido</label><textarea id="work_disposition" name="work_disposition" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">{{ old('work_disposition') }}</textarea><x-input-error :messages="$errors->get('work_disposition')" class="mt-2" /></div>
                    <div><label for="parts_disposition" class="text-sm font-semibold text-slate-200">Repuestos y materiales</label><textarea id="parts_disposition" name="parts_disposition" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">{{ old('parts_disposition') }}</textarea><x-input-error :messages="$errors->get('parts_disposition')" class="mt-2" /></div>
                    <div><label for="financial_disposition" class="text-sm font-semibold text-slate-200">Tratamiento financiero</label><textarea id="financial_disposition" name="financial_disposition" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">{{ old('financial_disposition') }}</textarea><x-input-error :messages="$errors->get('financial_disposition')" class="mt-2" /></div>
                    <div><label for="return_condition_notes" class="text-sm font-semibold text-slate-200">Condición prevista para devolver</label><textarea id="return_condition_notes" name="return_condition_notes" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">{{ old('return_condition_notes') }}</textarea><x-input-error :messages="$errors->get('return_condition_notes')" class="mt-2" /></div>
                    <div><label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios que deberán entregarse</label><textarea id="accessories_snapshot" name="accessories_snapshot" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">{{ old('accessories_snapshot', $order->intake->received_accessories) }}</textarea><x-input-error :messages="$errors->get('accessories_snapshot')" class="mt-2" /></div>
                    <div><label for="notes" class="text-sm font-semibold text-slate-200">Notas administrativas</label><textarea id="notes" name="notes" rows="4" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-orange-400 focus:ring-orange-400">{{ old('notes') }}</textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
                </div>
            </section>

            <div class="rounded-xl border border-orange-500/20 bg-orange-500/5 px-4 py-3 text-sm text-orange-100">Al confirmar, la resolución quedará inmutable y la orden pasará a <strong>Lista para devolver</strong>. El dominio rechazará la operación si aún existe trabajo activo o custodia externa.</div>
            <div class="flex flex-wrap gap-3"><button type="submit" class="rounded-xl bg-orange-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-orange-300">Registrar resolución inmutable</button><a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a></div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const outcome = document.querySelector('[data-financial-outcome]');
            const fields = document.querySelectorAll('[data-charge-fields]');
            if (!outcome) return;
            const refresh = () => fields.forEach((field) => field.classList.toggle('hidden', outcome.value !== 'customer_charge'));
            outcome.addEventListener('change', refresh);
            refresh();
        });
    </script>
</x-app-layout>
