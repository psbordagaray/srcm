<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Reparaciones · Cierre técnico</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Control de calidad</h1>
            <p class="mt-2 text-sm text-slate-400">
                Orden #{{ $order->order_number }} · {{ $order->asset->brand_name }} {{ $order->asset->model_name }}
            </p>
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
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Autoridad del expediente</p>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500">Trabajos completados</p>
                    <p class="mt-2 text-xl font-bold text-white">{{ $order->workItems->count() }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500">Repuestos afectados</p>
                    <p class="mt-2 text-xl font-bold text-white">{{ $order->partRequirements->count() }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500">Revisión a crear</p>
                    <p class="mt-2 text-xl font-bold text-white">R{{ $order->qualityInspections->count() + 1 }}</p>
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('service-orders.quality-inspections.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="text-lg font-bold text-white">Protocolo obligatorio</h2>
                <p class="mt-1 text-sm text-slate-500">Respondé las seis comprobaciones. Una falla exige indicar el retrabajo.</p>

                <div class="mt-5 space-y-4">
                    @foreach($qualityChecks as $index => $check)
                        <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                            <input type="hidden" name="checks[{{ $index }}][code]" value="{{ $check['code'] }}">
                            <input type="hidden" name="checks[{{ $index }}][passed]" value="0">

                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-bold text-white">{{ $check['label'] }}</p>
                                    <p class="mt-1 font-mono text-[10px] uppercase tracking-wider text-slate-600">{{ $check['code'] }}</p>
                                </div>

                                <label class="inline-flex cursor-pointer items-center gap-3 rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-2.5 text-sm font-semibold text-emerald-100">
                                    <input
                                        type="checkbox"
                                        name="checks[{{ $index }}][passed]"
                                        value="1"
                                        @checked(old('checks.'.$index.'.passed', '1') === '1' || old('checks.'.$index.'.passed') === 1)
                                        class="rounded border-slate-600 bg-slate-950 text-emerald-400 focus:ring-emerald-400"
                                    >
                                    Prueba aprobada
                                </label>
                            </div>

                            <div class="mt-4">
                                <label for="check_notes_{{ $index }}" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Observaciones</label>
                                <textarea id="check_notes_{{ $index }}" name="checks[{{ $index }}][notes]" rows="2" maxlength="2000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('checks.'.$index.'.notes') }}</textarea>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="condition_notes" class="text-sm font-semibold text-slate-200">Condición final del equipo</label>
                        <textarea id="condition_notes" name="condition_notes" rows="5" required maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('condition_notes') }}</textarea>
                    </div>
                    <div>
                        <label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios verificados</label>
                        <textarea id="accessories_snapshot" name="accessories_snapshot" rows="5" required maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('accessories_snapshot', $order->intake->received_accessories) }}</textarea>
                    </div>
                </div>

                <div class="mt-5">
                    <label for="rework_reason" class="text-sm font-semibold text-slate-200">Retrabajo requerido</label>
                    <textarea id="rework_reason" name="rework_reason" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">{{ old('rework_reason') }}</textarea>
                    <p class="mt-2 text-xs text-slate-500">Obligatorio cuando alguna comprobación queda sin aprobar.</p>
                </div>

                <div class="mt-5">
                    <label for="notes" class="text-sm font-semibold text-slate-200">Notas internas</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">{{ old('notes') }}</textarea>
                </div>

                <div class="mt-5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-xs text-emerald-100">
                    El control confirmado es inmutable. Si falla, la orden vuelve a trabajo y deberá aprobar una revisión posterior antes de la entrega.
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                    <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                    <button type="submit" class="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-emerald-300">Confirmar control</button>
                </div>
            </section>
        </form>
    </div>
</x-app-layout>
