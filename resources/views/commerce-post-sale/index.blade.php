<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Posventa</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Expedientes de posventa</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-400">
                    Solicitudes inmutables vinculadas a ventas confirmadas. La recepción física, la resolución económica y su ejecución permanecen como hechos separados.
                </p>
            </div>

            <a href="{{ route('commerce-sales.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">
                Buscar venta
            </a>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" action="{{ route('commerce-post-sale.index') }}" class="sulu-card grid gap-4 p-5 md:grid-cols-[minmax(0,1fr)_14rem_auto] md:items-end">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Buscar</span>
                <input
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Venta, cliente, UUID o motivo"
                    class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-white placeholder:text-slate-600"
                >
            </label>

            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Intención</span>
                <select name="intent" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-white">
                    <option value="">Todas</option>
                    @foreach($intents as $intent)
                        <option value="{{ $intent->value }}" @selected($selectedIntent === $intent->value)>
                            {{ $intent->label() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">
                Filtrar
            </button>
        </form>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-white/10 px-5 py-4">
                <p class="text-sm text-slate-400">
                    {{ $cases->total() }} expediente{{ $cases->total() === 1 ? '' : 's' }}
                </p>
            </div>

            @forelse($cases as $case)
                <article class="border-b border-white/10 px-5 py-5 last:border-b-0">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-violet-500/10 px-3 py-1 text-xs font-bold text-violet-200">
                                    {{ $case->intent->label() }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ $case->requested_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                </span>
                            </div>

                            <h2 class="mt-2 text-base font-bold text-white">
                                Venta #{{ $case->sale->sale_number }}
                                · {{ $case->sale->customer_name_snapshot }}
                            </h2>

                            <p class="mt-1 line-clamp-2 text-sm text-slate-400">
                                {{ $case->reason }}
                            </p>

                            <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                                <span>Recepciones: {{ $case->receipts_count }}</span>
                                <span>Resoluciones: {{ $case->resolutions_count }}</span>
                                <span>Registró: {{ $case->requestedBy->name }}</span>
                            </div>
                        </div>

                        <a
                            href="{{ route('commerce-post-sale.show', $case) }}"
                            class="inline-flex shrink-0 rounded-xl border border-violet-400/30 px-4 py-2.5 text-sm font-semibold text-violet-200 transition hover:border-violet-300"
                        >
                            Abrir expediente
                        </a>
                    </div>
                </article>
            @empty
                <div class="px-6 py-14 text-center">
                    <p class="text-base font-bold text-white">No hay expedientes para este filtro.</p>
                    <p class="mt-2 text-sm text-slate-500">La posventa se inicia desde una venta confirmada.</p>
                </div>
            @endforelse
        </section>

        {{ $cases->links() }}
    </div>
</x-app-layout>
