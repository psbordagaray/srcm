<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Reparaciones · Decisión del cliente</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Decisión del presupuesto · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">Revisión {{ $quote->revision }} · {{ $order->asset->brand_name }} {{ $order->asset->model_name }}</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('assessment'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('assessment') }}</div>
        @endif

        <form method="POST" action="{{ route('service-orders.quotes.decisions.store', [$order, $quote]) }}" class="space-y-6" data-decision-form>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="font-bold text-white">1. Alternativas comunicadas</h2><p class="mt-1 text-xs text-slate-500">Importes exactos de la revisión que el cliente está resolviendo.</p></div><span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-slate-400">{{ $quote->currency_code }}</span></div>
                <div class="mt-5 grid gap-4 lg:grid-cols-2">
                    @foreach($quote->options as $option)
                        <label class="relative cursor-pointer rounded-2xl border border-slate-700 bg-slate-950/60 p-5 transition hover:border-emerald-500/50">
                            <input type="radio" name="service_quote_option_id" value="{{ $option->id }}" @checked((string) old('service_quote_option_id') === (string) $option->id) class="absolute right-5 top-5 border-slate-600 bg-slate-950 text-emerald-400 focus:ring-emerald-400">
                            <div class="pr-8"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-white">{{ $option->label }}</h3>@if($option->recommended)<span class="rounded-full bg-amber-400/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-300">Recomendada</span>@endif</div><p class="mt-2 text-sm text-slate-400">{{ $option->description ?: 'Sin observaciones adicionales.' }}</p></div>
                            <div class="mt-4 space-y-2 border-t border-slate-800 pt-4">@foreach($option->lines as $line)<div class="flex justify-between gap-4 text-xs"><span class="text-slate-400">{{ rtrim(rtrim($line->quantity, '0'), '.') }} × {{ $line->description }}</span><span class="whitespace-nowrap font-mono text-slate-200">$ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}</span></div>@endforeach</div>
                            <p class="mt-4 text-right text-xl font-bold text-emerald-300">$ {{ number_format($option->total_minor / 100, 2, ',', '.') }}</p>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('service_quote_option_id')" class="mt-3" />
            </section>

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">2. Hecho informado por el cliente</h2>
                <p class="mt-1 text-xs text-slate-500">Registrá quién decidió, por qué canal y qué expresó. Este asiento no se modifica.</p>
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div>
                        <label for="decision" class="text-sm font-semibold text-slate-200">Decisión</label>
                        <select id="decision" name="decision" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-emerald-400 focus:ring-emerald-400">
                            <option value="">Seleccionar</option>
                            @foreach($decisionTypes as $type)<option value="{{ $type->value }}" @selected(old('decision') === $type->value)>{{ $type->label() }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="customer_name" class="text-sm font-semibold text-slate-200">Persona que decide</label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name', $order->intake->customer_name_snapshot) }}" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-emerald-400 focus:ring-emerald-400">
                    </div>
                    <div>
                        <label for="channel" class="text-sm font-semibold text-slate-200">Canal</label>
                        <input id="channel" name="channel" value="{{ old('channel') }}" required placeholder="Mostrador, WhatsApp, llamada..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-emerald-400 focus:ring-emerald-400">
                    </div>
                    <div>
                        <label for="customer_reference" class="text-sm font-semibold text-slate-200">Referencia <span class="font-normal text-slate-600">(opcional)</span></label>
                        <input id="customer_reference" name="customer_reference" value="{{ old('customer_reference', $order->intake->contact_reference) }}" placeholder="Teléfono, correo o comprobante..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-emerald-400 focus:ring-emerald-400">
                    </div>
                    <div class="lg:col-span-2">
                        <label for="reason" class="text-sm font-semibold text-slate-200">Detalle o motivo <span class="font-normal text-slate-600">(obligatorio si rechaza)</span></label>
                        <textarea id="reason" name="reason" rows="4" placeholder="Qué autoriza, qué rechaza o qué alternativa solicita..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-emerald-400 focus:ring-emerald-400">{{ old('reason') }}</textarea>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>
                </div>
            </section>

            <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-sm text-emerald-100">Si aprueba, la orden pasa a <strong>En trabajo</strong>. Si rechaza, vuelve a <strong>En diagnóstico</strong> para permitir una nueva revisión.</div>
            <div class="flex flex-wrap gap-3"><button type="submit" class="rounded-xl bg-emerald-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-emerald-300">Registrar decisión inmutable</button><a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a></div>
        </form>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const decision = document.querySelector('#decision');
                const radios = document.querySelectorAll('input[name="service_quote_option_id"]');
                if (!decision) return;
                const sync = () => {
                    const rejected = decision.value === 'rejected';
                    radios.forEach((radio) => { radio.disabled = rejected; if (rejected) radio.checked = false; });
                };
                decision.addEventListener('change', sync);
                sync();
            });
        </script>
    </div>
</x-app-layout>
