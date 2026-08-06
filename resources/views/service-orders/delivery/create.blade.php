<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Custodia final</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Registrar entrega</h1>
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
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">Último control aprobado</p>
                    <h2 class="mt-2 text-lg font-bold text-white">Revisión {{ $inspection->revision }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $inspection->inspected_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                </div>
                <p class="font-mono text-sm font-bold text-emerald-200">{{ $inspection->check_count }}/{{ $inspection->check_count }} pruebas</p>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Condición aprobada</p>
                    <p class="mt-2 whitespace-pre-line text-xs text-slate-300">{{ $inspection->condition_notes }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Accesorios aprobados</p>
                    <p class="mt-2 whitespace-pre-line text-xs text-slate-300">{{ $inspection->accessories_snapshot }}</p>
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('service-orders.delivery.store', $order) }}" class="sulu-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <input type="hidden" name="customer_conformity" value="0">

            <div>
                <label for="recipient_business_party_id" class="text-sm font-semibold text-slate-200">Persona vinculada</label>
                <select id="recipient_business_party_id" name="recipient_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                    <option value="">Receptor autorizado sin ficha vinculada</option>
                    @foreach($recipients as $recipient)
                        <option
                            value="{{ $recipient->id }}"
                            @selected(
                                (string) old(
                                    'recipient_business_party_id',
                                    $order->owner?->id
                                ) === (string) $recipient->id
                            )
                        >
                            {{ $recipient->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="recipient_name" class="text-sm font-semibold text-slate-200">Nombre de quien recibe</label>
                    <input id="recipient_name" name="recipient_name" type="text" required maxlength="255" value="{{ old('recipient_name', $order->owner?->name) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                </div>
                <div>
                    <label for="recipient_document" class="text-sm font-semibold text-slate-200">Documento o referencia</label>
                    <input id="recipient_document" name="recipient_document" type="text" maxlength="255" value="{{ old('recipient_document') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                </div>
            </div>

            <div>
                <label for="delivered_at" class="text-sm font-semibold text-slate-200">Fecha y hora de entrega</label>
                <input id="delivered_at" name="delivered_at" type="datetime-local" value="{{ old('delivered_at') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                <p class="mt-2 text-xs text-slate-500">Dejalo vacío para registrar el momento actual.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="condition_notes" class="text-sm font-semibold text-slate-200">Condición en la entrega</label>
                    <textarea id="condition_notes" name="condition_notes" rows="5" required maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">{{ old('condition_notes', $inspection->condition_notes) }}</textarea>
                </div>
                <div>
                    <label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios entregados</label>
                    <textarea id="accessories_snapshot" name="accessories_snapshot" rows="5" required maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">{{ old('accessories_snapshot', $inspection->accessories_snapshot) }}</textarea>
                </div>
            </div>

            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                <input type="checkbox" name="customer_conformity" value="1" @checked(old('customer_conformity', '1') === '1' || old('customer_conformity') === 1) class="mt-0.5 rounded border-slate-600 bg-slate-950 text-emerald-400 focus:ring-emerald-400">
                <span>
                    <span class="block text-sm font-semibold text-emerald-100">El receptor manifiesta conformidad</span>
                    <span class="mt-1 block text-xs text-slate-500">Si no hay conformidad, detallá el motivo en observaciones.</span>
                </span>
            </label>

            <div>
                <label for="notes" class="text-sm font-semibold text-slate-200">Observaciones de entrega</label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">{{ old('notes') }}</textarea>
            </div>

            <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-xs text-cyan-100">
                La confirmación transfiere la custodia, genera las garantías declaradas por los trabajos y deja la entrega inmutable.
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                <button type="submit" class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Confirmar entrega</button>
            </div>
        </form>
    </div>
</x-app-layout>
