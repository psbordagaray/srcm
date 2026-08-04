<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-fuchsia-300">Reparaciones · Retorno externo</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Recuperar custodia · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">Trabajo {{ $work->sequence }} · {{ $work->title }}</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('cancellation'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('cancellation') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-2">
            <article class="rounded-2xl border border-fuchsia-500/20 bg-fuchsia-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-fuchsia-300">Especialista externo</p>
                <p class="mt-3 text-sm font-bold text-white">{{ $work->provider?->name ?? 'Prestador no disponible' }}</p>
                <p class="mt-2 text-xs leading-5 text-slate-400">{{ $work->description }}</p>
            </article>
            <article class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-amber-300">Control obligatorio</p>
                <p class="mt-3 text-sm leading-6 text-slate-200">Confirmá físicamente condición y accesorios. Esta operación devuelve la custodia al comercio y cancela el trabajo externo pendiente.</p>
            </article>
        </section>

        <form method="POST" action="{{ route('service-orders.cancellation.recall.store', [$order, $work]) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Recepción desde el especialista</h2>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="condition_notes" class="text-sm font-semibold text-slate-200">Condición al retornar</label>
                        <textarea id="condition_notes" name="condition_notes" rows="5" required placeholder="Estado comprobado del equipo al recuperar la custodia…" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-fuchsia-400 focus:ring-fuchsia-400">{{ old('condition_notes') }}</textarea>
                        <x-input-error :messages="$errors->get('condition_notes')" class="mt-2" />
                    </div>
                    <div>
                        <label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios retornados</label>
                        <textarea id="accessories_snapshot" name="accessories_snapshot" rows="5" required placeholder="Equipo, funda, cargador u otros elementos…" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-fuchsia-400 focus:ring-fuchsia-400">{{ old('accessories_snapshot', $order->intake->received_accessories ?: 'Equipo sin accesorios.') }}</textarea>
                        <x-input-error :messages="$errors->get('accessories_snapshot')" class="mt-2" />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-fuchsia-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-fuchsia-300">Registrar retorno y cancelar trabajo</button>
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
