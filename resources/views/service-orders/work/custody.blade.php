<x-app-layout>
    @php($isDispatch = $direction === 'dispatch')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-fuchsia-300">Reparaciones · Custodia</p>
            <h1 class="mt-2 text-2xl font-bold text-white">{{ $isDispatch ? 'Entregar a especialista externo' : 'Registrar retorno del especialista' }}</h1>
            <p class="mt-2 text-sm text-slate-400">Orden #{{ $order->order_number }} · Trabajo {{ $work->sequence }}: {{ $work->title }}</p>
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
            <p class="text-xs font-bold uppercase tracking-wider text-fuchsia-300">Especialista</p>
            <p class="mt-2 text-lg font-bold text-white">{{ $work->provider->name }}</p>
            <p class="mt-2 text-sm text-slate-400">{{ $work->description }}</p>
        </section>

        <form method="POST" action="{{ $isDispatch ? route('service-orders.work-items.dispatch.store', [$order, $work]) : route('service-orders.work-items.return.store', [$order, $work]) }}" class="sulu-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <div>
                <label for="condition_notes" class="text-sm font-semibold text-slate-200">Condición del equipo</label>
                <textarea id="condition_notes" name="condition_notes" rows="5" maxlength="5000" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-fuchsia-400 focus:ring-fuchsia-400">{{ old('condition_notes', $latestCustody?->condition_notes) }}</textarea>
                <p class="mt-2 text-xs text-slate-500">Registrá golpes, marcas, estado de encendido, piezas desmontadas o cualquier diferencia observable.</p>
            </div>

            <div>
                <label for="accessories_snapshot" class="text-sm font-semibold text-slate-200">Accesorios transferidos</label>
                <textarea id="accessories_snapshot" name="accessories_snapshot" rows="4" maxlength="5000" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-fuchsia-400 focus:ring-fuchsia-400">{{ old('accessories_snapshot', $latestCustody?->accessories_snapshot) }}</textarea>
            </div>

            <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs text-amber-100">
                Esta operación transfiere la custodia y queda registrada como un hecho inmutable.
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                <button type="submit" class="rounded-xl bg-fuchsia-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-fuchsia-300">{{ $isDispatch ? 'Confirmar entrega' : 'Confirmar retorno' }}</button>
            </div>
        </form>
    </div>
</x-app-layout>
