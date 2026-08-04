<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-rose-300">Reparaciones · Garantía</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Devolver equipo por garantía rechazada · Orden #{{ $claim->correctiveOrder->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">La devolución cerrará el reclamo, liberará la garantía para futuros reclamos y cancelará la orden correctiva sin crear una entrega ficticia.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('warranty_claim'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('warranty_claim') }}</div>
        @endif

        <section class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-5">
            <p class="text-xs font-semibold uppercase tracking-wider text-rose-300">Fundamento del rechazo</p>
            <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-200">{{ $claim->resolution->technical_basis }}</p>
            <p class="mt-3 text-xs text-slate-400">Excluido: {{ $claim->resolution->excluded_scope }}</p>
        </section>

        <form method="POST" action="{{ route('service-orders.warranty-claims.return.store', [$order, $claim]) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Receptor y momento de devolución</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div><label for="recipient_business_party_id" class="text-sm font-semibold text-slate-200">Persona vinculada</label><select id="recipient_business_party_id" name="recipient_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400"><option value="">Receptor sin vínculo formal</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected((string) old('recipient_business_party_id', $claim->claimant?->id) === (string) $party->id)>{{ $party->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('recipient_business_party_id')" class="mt-2" /></div>
                    <div><label for="recipient_name" class="text-sm font-semibold text-slate-200">Nombre del receptor</label><input id="recipient_name" name="recipient_name" type="text" required value="{{ old('recipient_name', $claim->claimant_name) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400"><x-input-error :messages="$errors->get('recipient_name')" class="mt-2" /></div>
                    <div><label for="recipient_document" class="text-sm font-semibold text-slate-200">Documento o referencia</label><input id="recipient_document" name="recipient_document" type="text" value="{{ old('recipient_document') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400"><x-input-error :messages="$errors->get('recipient_document')" class="mt-2" /></div>
                    <div><label for="returned_at" class="text-sm font-semibold text-slate-200">Fecha y hora</label><input id="returned_at" name="returned_at" type="datetime-local" step="1" value="{{ old('returned_at', now()->format('Y-m-d\TH:i:s')) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400"><x-input-error :messages="$errors->get('returned_at')" class="mt-2" /></div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Fotografía de la devolución</h2>
                <div class="mt-5 space-y-5">
                    <div><label for="condition_notes" class="text-sm font-semibold text-slate-200">Condición física</label><textarea id="condition_notes" name="condition_notes" rows="4" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400">{{ old('condition_notes', $claim->reentry_condition_notes) }}</textarea><x-input-error :messages="$errors->get('condition_notes')" class="mt-2" /></div>
                    <div><label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios entregados</label><textarea id="accessories_snapshot" name="accessories_snapshot" rows="3" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400">{{ old('accessories_snapshot', $claim->accessories_snapshot) }}</textarea><x-input-error :messages="$errors->get('accessories_snapshot')" class="mt-2" /></div>
                    <div><label for="notes" class="text-sm font-semibold text-slate-200">Notas internas</label><textarea id="notes" name="notes" rows="3" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-rose-400 focus:ring-rose-400">{{ old('notes') }}</textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-rose-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-rose-300">Registrar devolución y cerrar</button>
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
