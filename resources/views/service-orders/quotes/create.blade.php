<x-app-layout>
    @php($optionRows = old('options', [['label' => '', 'description' => '', 'recommended' => true, 'lines' => [['type' => 'labor', 'description' => '', 'quantity' => '1', 'unit_price' => '']]]]))
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Reparaciones · Presupuesto</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Presupuesto · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">Basado en diagnóstico revisión {{ $diagnostic->revision }} · Cada emisión queda preservada como una revisión independiente.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('assessment'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('assessment') }}</div>
        @endif

        <section class="rounded-2xl border border-violet-500/20 bg-violet-500/5 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wider text-violet-300">Diagnóstico vigente</p><h2 class="mt-2 font-bold text-white">{{ $diagnostic->summary }}</h2></div><span class="rounded-lg border border-violet-500/20 px-3 py-1.5 text-xs font-semibold text-violet-200">{{ $diagnostic->findings->count() }} hallazgos</span></div>
            <p class="mt-3 text-sm text-slate-300">{{ $diagnostic->recommendation }}</p>
        </section>

        <form method="POST" action="{{ route('service-orders.quotes.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-[10rem_14rem_minmax(0,1fr)]">
                    <div>
                        <label for="currency_code" class="text-sm font-semibold text-slate-200">Moneda</label>
                        <input id="currency_code" name="currency_code" value="{{ old('currency_code', 'ARS') }}" maxlength="3" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm uppercase text-white focus:border-amber-400 focus:ring-amber-400">
                    </div>
                    <div>
                        <label for="valid_until" class="text-sm font-semibold text-slate-200">Válido hasta <span class="font-normal text-slate-600">(opcional)</span></label>
                        <input id="valid_until" type="date" name="valid_until" value="{{ old('valid_until') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">
                    </div>
                    <div>
                        <label for="terms" class="text-sm font-semibold text-slate-200">Condiciones <span class="font-normal text-slate-600">(opcional)</span></label>
                        <input id="terms" name="terms" value="{{ old('terms') }}" placeholder="Disponibilidad, plazos, condiciones sobre datos..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="flex items-center justify-between gap-4"><div><h2 class="font-bold text-white">Alternativas para el cliente</h2><p class="mt-1 text-xs text-slate-500">Podés comparar caminos distintos. Sólo una alternativa puede marcarse como recomendada.</p></div><button type="button" data-add-option class="shrink-0 rounded-xl bg-amber-400 px-4 py-2.5 text-xs font-bold text-slate-950 transition hover:bg-amber-300">Agregar alternativa</button></div>
                <x-input-error :messages="$errors->get('options')" class="mt-3" />
                <div class="mt-5 space-y-5" data-quote-options>
                    @foreach($optionRows as $optionIndex => $option)
                        @include('service-orders.quotes._option', compact('optionIndex', 'option', 'lineTypes'))
                    @endforeach
                </div>
            </section>

            <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-100">Al emitir, la orden pasará a <strong>Esperando aprobación</strong>. No se podrá alterar este presupuesto: cualquier cambio será una revisión nueva.</div>
            <div class="flex flex-wrap gap-3"><button type="submit" class="rounded-xl bg-amber-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-amber-300">Emitir presupuesto versionado</button><a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a></div>
        </form>

        <template data-option-template>@include('service-orders.quotes._option', ['optionIndex' => '__OPTION__', 'option' => ['label' => '', 'description' => '', 'recommended' => false, 'lines' => [['type' => 'labor', 'description' => '', 'quantity' => '1', 'unit_price' => '']]], 'lineTypes' => $lineTypes])</template>
        <template data-line-template>@include('service-orders.quotes._line', ['optionIndex' => '__OPTION__', 'lineIndex' => '__LINE__', 'line' => ['type' => 'labor', 'description' => '', 'quantity' => '1', 'unit_price' => ''], 'lineTypes' => $lineTypes])</template>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const options = document.querySelector('[data-quote-options]');
            const addOption = document.querySelector('[data-add-option]');
            const optionTemplate = document.querySelector('[data-option-template]');
            const lineTemplate = document.querySelector('[data-line-template]');
            if (!options || !addOption || !optionTemplate || !lineTemplate) return;

            const refresh = () => {
                options.querySelectorAll('[data-remove-option]').forEach((button) => {
                    button.onclick = () => {
                        if (options.children.length > 1) button.closest('[data-quote-option]').remove();
                        refresh();
                    };
                });
                options.querySelectorAll('[data-add-line]').forEach((button) => {
                    button.onclick = () => {
                        const option = button.closest('[data-quote-option]');
                        const rows = option.querySelector('[data-option-lines]');
                        if (rows.children.length >= 30) return;
                        const id = Date.now().toString();
                        const wrapper = document.createElement('div');
                        wrapper.innerHTML = lineTemplate.innerHTML
                            .replaceAll('__OPTION__', option.dataset.optionIndex)
                            .replaceAll('__LINE__', id).trim();
                        rows.appendChild(wrapper.firstElementChild);
                        refresh();
                    };
                });
                options.querySelectorAll('[data-remove-line]').forEach((button) => {
                    button.onclick = () => {
                        const rows = button.closest('[data-option-lines]');
                        if (rows.children.length > 1) button.closest('[data-quote-line]').remove();
                    };
                });
                options.querySelectorAll('[data-recommended]').forEach((checkbox) => {
                    checkbox.onchange = () => {
                        if (checkbox.checked) options.querySelectorAll('[data-recommended]').forEach((other) => { if (other !== checkbox) other.checked = false; });
                    };
                });
                addOption.disabled = options.children.length >= 5;
                addOption.classList.toggle('opacity-40', addOption.disabled);
            };

            addOption.addEventListener('click', () => {
                if (options.children.length >= 5) return;
                const id = Date.now().toString();
                const wrapper = document.createElement('div');
                wrapper.innerHTML = optionTemplate.innerHTML
                    .replaceAll('__OPTION__', id)
                    .replaceAll('__LINE__', id).trim();
                options.appendChild(wrapper.firstElementChild);
                refresh();
            });
            refresh();
        });
    </script>
</x-app-layout>
