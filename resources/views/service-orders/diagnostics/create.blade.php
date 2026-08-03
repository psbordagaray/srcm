<x-app-layout>
    @php($findingRows = old('findings', [['severity' => 'attention', 'category' => '', 'description' => '', 'evidence_notes' => '']]))
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-violet-300">Reparaciones · Diagnóstico</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Diagnóstico técnico · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">{{ $order->asset->brand_name }} {{ $order->asset->model_name }} · {{ $order->diagnostics->isEmpty() ? 'Primer diagnóstico del expediente.' : 'Nueva revisión '.($order->diagnostics->count() + 1).'; las anteriores permanecerán intactas.' }}</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('assessment'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('assessment') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Declarado al ingresar</p>
                <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-200">{{ $order->intake->customer_reported_issue }}</p>
            </article>
            <article class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-amber-300">Observado en recepción</p>
                <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-200">{{ $order->intake->intake_observations ?: 'Sin observaciones adicionales.' }}</p>
            </article>
        </section>

        <form method="POST" action="{{ route('service-orders.diagnostics.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div><h2 class="font-bold text-white">1. Hallazgos verificables</h2><p class="mt-1 text-xs text-slate-500">Separá cada falla, condición o riesgo para conservar evidencia precisa.</p></div>
                    <div class="flex flex-wrap gap-2">
                        <select data-diagnostic-template class="rounded-xl border-slate-700 bg-slate-950 text-xs font-semibold text-slate-300 focus:border-violet-400 focus:ring-violet-400">
                            <option value="">Plantilla rápida…</option>
                            <option value="screen">Pantalla o módulo</option>
                            <option value="battery">Batería</option>
                            <option value="charging">Conector de carga</option>
                            <option value="software">Software</option>
                        </select>
                        <button type="button" data-add-finding class="shrink-0 rounded-xl border border-violet-500/40 px-3 py-2 text-xs font-bold text-violet-300 transition hover:bg-violet-500/10">Agregar hallazgo</button>
                    </div>
                </div>
                <div class="mt-5 space-y-4" data-findings>
                    @foreach($findingRows as $index => $finding)
                        @include('service-orders.diagnostics._finding', compact('index', 'finding', 'severities'))
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('findings')" class="mt-3" />
            </section>

            <section class="sulu-card p-6">
                <div><h2 class="font-bold text-white">2. Conclusión y recomendación</h2><p class="mt-1 text-xs text-slate-500">La recepción permanece intacta; esta conclusión es una revisión técnica nueva.</p></div>
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div>
                        <label for="summary" class="text-sm font-semibold text-slate-200">Resumen del diagnóstico</label>
                        <textarea id="summary" name="summary" rows="5" required placeholder="Causa técnica y estado general comprobado..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-violet-400 focus:ring-violet-400">{{ old('summary') }}</textarea>
                        <x-input-error :messages="$errors->get('summary')" class="mt-2" />
                    </div>
                    <div>
                        <label for="recommendation" class="text-sm font-semibold text-slate-200">Recomendación técnica</label>
                        <textarea id="recommendation" name="recommendation" rows="5" required placeholder="Qué conviene hacer y por qué..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-violet-400 focus:ring-violet-400">{{ old('recommendation') }}</textarea>
                        <x-input-error :messages="$errors->get('recommendation')" class="mt-2" />
                    </div>
                    <div>
                        <label for="data_risk_present" class="text-sm font-semibold text-slate-200">Riesgo sobre datos</label>
                        <select id="data_risk_present" name="data_risk_present" required data-risk-selector class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-violet-400 focus:ring-violet-400">
                            <option value="0" @selected((string) old('data_risk_present', '0') === '0')>Sin riesgo identificado</option>
                            <option value="1" @selected((string) old('data_risk_present') === '1')>Existe riesgo sobre datos</option>
                        </select>
                    </div>
                    <div data-risk-notes>
                        <label for="data_risk_notes" class="text-sm font-semibold text-slate-200">Descripción del riesgo</label>
                        <textarea id="data_risk_notes" name="data_risk_notes" rows="3" placeholder="Posible pérdida de información, necesidad de backup o restauración..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-violet-400 focus:ring-violet-400">{{ old('data_risk_notes') }}</textarea>
                        <x-input-error :messages="$errors->get('data_risk_notes')" class="mt-2" />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-violet-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-violet-300">Registrar diagnóstico inmutable</button>
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>

        <template data-finding-template>@include('service-orders.diagnostics._finding', ['index' => '__INDEX__', 'finding' => ['severity' => 'attention', 'category' => '', 'description' => '', 'evidence_notes' => ''], 'severities' => $severities])</template>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const rows = document.querySelector('[data-findings]');
            const add = document.querySelector('[data-add-finding]');
            const template = document.querySelector('[data-finding-template]');
            const quickTemplate = document.querySelector('[data-diagnostic-template]');
            const riskSelector = document.querySelector('[data-risk-selector]');
            const riskNotes = document.querySelector('[data-risk-notes]');
            if (!rows || !add || !template) return;
            const presets = {
                screen: ['attention', 'Pantalla y chasis', 'Daño de pantalla o módulo comprobado.', 'Daño de pantalla comprobado mediante inspección y prueba funcional.', 'Reemplazar el módulo y completar pruebas antes de la entrega.'],
                battery: ['attention', 'Batería', 'Batería degradada o con autonomía insuficiente.', 'La batería no conserva una autonomía normal.', 'Reemplazar la batería y verificar carga, consumo y temperatura.'],
                charging: ['attention', 'Carga', 'Falla del conector o circuito de carga comprobada.', 'El equipo no carga de manera estable.', 'Reparar el sistema de carga y verificar tensión y consumo.'],
                software: ['informational', 'Software', 'Falla de software comprobada durante las pruebas.', 'El funcionamiento anormal tiene origen en el software.', 'Realizar mantenimiento correctivo y pruebas funcionales.'],
            };
            const refresh = () => {
                rows.querySelectorAll('[data-remove-finding]').forEach((button) => {
                    button.onclick = () => {
                        if (rows.children.length > 1) button.closest('[data-finding-row]').remove();
                        refresh();
                    };
                });
                add.disabled = rows.children.length >= 20;
                add.classList.toggle('opacity-40', add.disabled);
            };
            add.addEventListener('click', () => {
                if (rows.children.length >= 20) return;
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', Date.now().toString()).trim();
                rows.appendChild(wrapper.firstElementChild);
                refresh();
            });
            quickTemplate?.addEventListener('change', () => {
                const preset = presets[quickTemplate.value];
                const first = rows.querySelector('[data-finding-row]');
                if (!preset || !first) return;
                first.querySelector('select').value = preset[0];
                const inputs = first.querySelectorAll('input');
                inputs[0].value = preset[1];
                inputs[1].value = preset[2];
                document.querySelector('#summary').value = preset[3];
                document.querySelector('#recommendation').value = preset[4];
            });
            const syncRisk = () => {
                if (!riskSelector || !riskNotes) return;
                const present = riskSelector.value === '1';
                riskNotes.classList.toggle('hidden', !present);
                riskNotes.querySelector('textarea').required = present;
            };
            riskSelector?.addEventListener('change', syncRisk);
            syncRisk();
            refresh();
        });
    </script>
</x-app-layout>
