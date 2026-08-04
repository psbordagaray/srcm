<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-rose-300">Reparaciones · Cancelación</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Solicitar cancelación · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">{{ $order->asset->brand_name }} {{ $order->asset->model_name }} · La solicitud conserva una fotografía inmutable del estado operativo y financiero.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('cancellation'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('cancellation') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Equipo</p>
                <p class="mt-3 text-sm font-bold text-white">{{ $order->asset->brand_name }} {{ $order->asset->model_name }}</p>
                <p class="mt-2 text-xs leading-5 text-slate-400">{{ $order->intake->customer_reported_issue }}</p>
            </article>
            <article class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-rose-300">Efecto inmediato</p>
                <p class="mt-3 text-sm leading-6 text-slate-200">La orden pasará a <strong>Cancelación solicitada</strong>. Los trabajos internos activos se detendrán según las reglas del dominio; cualquier custodia externa deberá retornar antes de resolver.</p>
            </article>
        </section>

        <form method="POST" action="{{ route('service-orders.cancellation.request.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <input type="hidden" name="requester_business_party_id" value="{{ old('requester_business_party_id', $order->customer?->id) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Motivo y persona solicitante</h2>
                <p class="mt-1 text-xs text-slate-500">Registrá lo informado sin reinterpretar ni borrar el historial aprobado.</p>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="reason" class="text-sm font-semibold text-slate-200">Motivo</label>
                        <select id="reason" name="reason" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400">
                            <option value="">Seleccionar…</option>
                            @foreach($reasons as $reason)
                                <option value="{{ $reason->value }}" @selected(old('reason') === $reason->value)>{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>
                    <div>
                        <label for="requester_name" class="text-sm font-semibold text-slate-200">Nombre de quien solicita</label>
                        <input id="requester_name" name="requester_name" type="text" required value="{{ old('requester_name', $order->customer?->name ?? $order->intake->customer_name_snapshot) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400">
                        <x-input-error :messages="$errors->get('requester_name')" class="mt-2" />
                    </div>
                    <div>
                        <label for="channel" class="text-sm font-semibold text-slate-200">Canal</label>
                        <input id="channel" name="channel" type="text" required value="{{ old('channel', 'WhatsApp') }}" placeholder="WhatsApp, teléfono, presencial…" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-rose-400 focus:ring-rose-400">
                        <x-input-error :messages="$errors->get('channel')" class="mt-2" />
                    </div>
                    <div>
                        <label for="customer_reference" class="text-sm font-semibold text-slate-200">Referencia de contacto</label>
                        <input id="customer_reference" name="customer_reference" type="text" value="{{ old('customer_reference', $order->intake->contact_reference) }}" placeholder="Teléfono, usuario o referencia verificable" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-rose-400 focus:ring-rose-400">
                        <x-input-error :messages="$errors->get('customer_reference')" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="details" class="text-sm font-semibold text-slate-200">Detalle comunicado</label>
                        <textarea id="details" name="details" rows="5" placeholder="Qué pidió la persona, qué cambió y cualquier precisión relevante…" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-rose-400 focus:ring-rose-400">{{ old('details') }}</textarea>
                        <x-input-error :messages="$errors->get('details')" class="mt-2" />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-rose-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-rose-300">Registrar solicitud inmutable</button>
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
