<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-red-300">Reparaciones · Devolución final</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Entregar equipo cancelado · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">Último acto del expediente: identifica a quien recibe, condición y accesorios efectivamente entregados.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('cancellation'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('cancellation') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-orange-300">Resolución administrativa</p>
                <p class="mt-3 text-sm font-bold text-white">{{ $resolution->financial_outcome->label() }}</p>
                <p class="mt-2 text-xs leading-5 text-slate-400">{{ $resolution->financial_disposition }}</p>
                @if($resolution->customer_charge_minor > 0)<p class="mt-3 font-mono text-sm text-orange-200">{{ $resolution->currency_code }} {{ number_format($resolution->customer_charge_minor / 100, 2, ',', '.') }}</p>@endif
            </article>
            <article class="rounded-2xl border border-red-500/20 bg-red-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-red-300">Cierre irreversible</p>
                <p class="mt-3 text-sm leading-6 text-slate-200">La confirmación crea el evento final de custodia, registra la devolución y pasa la orden a <strong>Cancelada y devuelta</strong>.</p>
            </article>
        </section>

        <form method="POST" action="{{ route('service-orders.cancellation.return.store', [$order, $resolution]) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <input type="hidden" name="recipient_business_party_id" value="{{ old('recipient_business_party_id', $order->customer?->id) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Persona y evidencia de entrega</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div><label for="recipient_name" class="text-sm font-semibold text-slate-200">Nombre de quien recibe</label><input id="recipient_name" name="recipient_name" type="text" required value="{{ old('recipient_name', $order->customer?->name ?? $resolution->request->requester_name) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-red-400 focus:ring-red-400"><x-input-error :messages="$errors->get('recipient_name')" class="mt-2" /></div>
                    <div><label for="recipient_document" class="text-sm font-semibold text-slate-200">Documento o referencia</label><input id="recipient_document" name="recipient_document" type="text" value="{{ old('recipient_document') }}" placeholder="DNI u otra referencia verificable" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-red-400 focus:ring-red-400"><x-input-error :messages="$errors->get('recipient_document')" class="mt-2" /></div>
                    <div><label for="condition_notes" class="text-sm font-semibold text-slate-200">Condición comprobada al entregar</label><textarea id="condition_notes" name="condition_notes" rows="5" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-red-400 focus:ring-red-400">{{ old('condition_notes', $resolution->return_condition_notes) }}</textarea><x-input-error :messages="$errors->get('condition_notes')" class="mt-2" /></div>
                    <div><label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios efectivamente entregados</label><textarea id="accessories_snapshot" name="accessories_snapshot" rows="5" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-red-400 focus:ring-red-400">{{ old('accessories_snapshot', $resolution->accessories_snapshot) }}</textarea><x-input-error :messages="$errors->get('accessories_snapshot')" class="mt-2" /></div>
                    <div class="md:col-span-2"><label for="notes" class="text-sm font-semibold text-slate-200">Notas de entrega</label><textarea id="notes" name="notes" rows="3" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-red-400 focus:ring-red-400">{{ old('notes') }}</textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3"><button type="submit" class="rounded-xl bg-red-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-red-300">Confirmar devolución y cerrar</button><a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a></div>
        </form>
    </div>
</x-app-layout>
