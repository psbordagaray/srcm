<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Garantía</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Registrar reclamo · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">{{ $order->asset->brand_name }} {{ $order->asset->model_name }} · Se conservará la orden entregada y se abrirá una nueva orden correctiva para el mismo equipo.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('warranty_claim'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('warranty_claim') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Garantía otorgada</p>
                <p class="mt-3 text-sm font-bold text-white">{{ $warranty->warranty_days }} días · vence {{ $warranty->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $warranty->coverage_terms }}</p>
            </article>
            <article class="rounded-2xl border border-violet-500/20 bg-violet-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-violet-300">Efecto del registro</p>
                <p class="mt-3 text-sm leading-6 text-slate-200">Se registrará el reingreso, la custodia y un expediente correctivo. La vigencia será fotografiada al momento informado, pero una garantía vencida sólo podrá aceptarse mediante una excepción administrativa.</p>
            </article>
        </section>

        <form method="POST" action="{{ route('service-orders.warranty-claims.store', [$order, $warranty]) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Persona, fecha y canal</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="claimant_business_party_id" class="text-sm font-semibold text-slate-200">Persona vinculada</label>
                        <select id="claimant_business_party_id" name="claimant_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Sin vínculo formal</option>
                            @foreach($parties as $party)
                                <option value="{{ $party->id }}" @selected((string) old('claimant_business_party_id', $order->customer?->id) === (string) $party->id)>{{ $party->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('claimant_business_party_id')" class="mt-2" />
                    </div>
                    <div>
                        <label for="claimant_name" class="text-sm font-semibold text-slate-200">Nombre de quien reclama</label>
                        <input id="claimant_name" name="claimant_name" type="text" required value="{{ old('claimant_name', $order->customer?->name ?? $order->intake->customer_name_snapshot) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('claimant_name')" class="mt-2" />
                    </div>
                    <div>
                        <label for="channel" class="text-sm font-semibold text-slate-200">Canal</label>
                        <input id="channel" name="channel" type="text" required value="{{ old('channel', 'Presencial') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('channel')" class="mt-2" />
                    </div>
                    <div>
                        <label for="claimed_at" class="text-sm font-semibold text-slate-200">Fecha y hora del reclamo</label>
                        <input id="claimed_at" name="claimed_at" type="datetime-local" step="1" required value="{{ old('claimed_at', now()->format('Y-m-d\TH:i:s')) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('claimed_at')" class="mt-2" />
                    </div>
                    <div>
                        <label for="customer_reference" class="text-sm font-semibold text-slate-200">Referencia verificable</label>
                        <input id="customer_reference" name="customer_reference" type="text" value="{{ old('customer_reference', $order->intake->contact_reference) }}" placeholder="Mensaje, teléfono, ticket o referencia" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('customer_reference')" class="mt-2" />
                    </div>
                    <div>
                        <label for="intake_location_id" class="text-sm font-semibold text-slate-200">Ubicación de reingreso</label>
                        <select id="intake_location_id" name="intake_location_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Seleccionar…</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}" @selected((string) old('intake_location_id') === (string) $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('intake_location_id')" class="mt-2" />
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Fotografía del reingreso</h2>
                <p class="mt-1 text-xs text-slate-500">Estos hechos serán inmutables después del registro.</p>
                <div class="mt-5 space-y-5">
                    <div><label for="reported_issue" class="text-sm font-semibold text-slate-200">Falla reclamada</label><textarea id="reported_issue" name="reported_issue" rows="5" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">{{ old('reported_issue') }}</textarea><x-input-error :messages="$errors->get('reported_issue')" class="mt-2" /></div>
                    <div><label for="reentry_condition_notes" class="text-sm font-semibold text-slate-200">Condición física al reingresar</label><textarea id="reentry_condition_notes" name="reentry_condition_notes" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">{{ old('reentry_condition_notes', 'Sin daños nuevos observados.') }}</textarea><x-input-error :messages="$errors->get('reentry_condition_notes')" class="mt-2" /></div>
                    <div><label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios recibidos</label><textarea id="accessories_snapshot" name="accessories_snapshot" rows="3" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">{{ old('accessories_snapshot', $order->intake->received_accessories ?: 'Equipo sin accesorios.') }}</textarea><x-input-error :messages="$errors->get('accessories_snapshot')" class="mt-2" /></div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-cyan-300">Registrar reclamo y reingreso</button>
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
