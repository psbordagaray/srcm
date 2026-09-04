<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">
                Comercio · Integridad numérica · Revisión de liquidación
            </p>
            <h1 class="mt-2 text-2xl font-bold text-white">
                Resolución administrativa
            </h1>
            <p class="mt-2 text-sm text-slate-400">
                Revisión {{ $review->public_id }}
            </p>
        </div>

        <section class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-amber-300">
                Disposición separada de la ejecución
            </p>
            <p class="mt-2 text-sm leading-6 text-slate-300">
                Esta pantalla registra una resolución administrativa sobre una revisión ya persistida.
                No crea una venta, no reescribe pagos ni cuentas por cobrar y no ejecuta el outcome.
            </p>
        </section>

        @if(session('success'))
            <div
                role="status"
                class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"
            >
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div
                role="alert"
                class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100"
            >
                <p class="font-bold">No se pudo registrar la resolución.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="sulu-card p-6">
            <h2 class="text-lg font-bold text-white">Evidencia inmutable de la revisión</h2>

            <dl class="mt-5 grid gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Total de referencia del sistema
                    </dt>
                    <dd class="mt-1 font-mono text-sm text-white">
                        {{ $review->system_total_minor }} minor
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Total observado/liquidado
                    </dt>
                    <dd class="mt-1 font-mono text-sm text-white">
                        {{ $review->settled_total_minor }} minor
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Valor final preservado
                    </dt>
                    <dd class="mt-1 font-mono text-sm text-white">
                        {{ $review->final_value_minor }} minor
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Decisión registrada
                    </dt>
                    <dd class="mt-1 font-mono text-sm text-white">
                        {{ $review->decision }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Warning
                    </dt>
                    <dd class="mt-1 font-mono text-sm text-white">
                        {{ $review->warning_code }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Solicitada por / fecha
                    </dt>
                    <dd class="mt-1 text-sm text-white">
                        {{ $review->requestedBy?->name ?? $review->requestedBy?->email ?? '—' }}
                        · {{ $review->requested_at?->format('Y-m-d H:i:s') ?? '—' }}
                    </dd>
                </div>
            </dl>

            <div class="mt-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Motivo original
                </p>
                <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">
                    {{ $review->reason }}
                </p>
            </div>
        </section>

        @php
            $resolution = $review->resolution;
        @endphp

        @if($resolution)
            <section class="sulu-card p-6">
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">
                            Resolución registrada · inmutable
                        </p>
                        <h2 class="mt-2 text-lg font-bold text-white">
                            {{ $resolution->outcome->value }}
                        </h2>
                    </div>

                    <p class="font-mono text-xs text-slate-500">
                        {{ $resolution->public_id }}
                    </p>
                </div>

                <dl class="mt-5 grid gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Resuelta por
                        </dt>
                        <dd class="mt-1 text-sm text-white">
                            {{ $resolution->resolvedBy?->name ?? $resolution->resolvedBy?->email ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Fecha
                        </dt>
                        <dd class="mt-1 text-sm text-white">
                            {{ $resolution->resolved_at?->format('Y-m-d H:i:s') ?? '—' }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Motivo de resolución
                    </p>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">
                        {{ $resolution->reason }}
                    </p>
                </div>

                @if($resolution->notes)
                    <div class="mt-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Notas internas
                        </p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">
                            {{ $resolution->notes }}
                        </p>
                    </div>
                @endif

                <p class="mt-5 text-xs leading-5 text-slate-500">
                    No se ofrece una segunda acción sobre una resolución ya confirmada.
                    La materialización del outcome continúa fuera de este corte.
                </p>
            </section>
        @else
            <form
                method="POST"
                action="{{ route('commerce-settlement-reviews.resolutions.store', $review) }}"
                data-settlement-review-resolution-form="true"
                class="space-y-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <section class="sulu-card p-6">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Outcome administrativo
                        </span>
                        <select
                            name="outcome"
                            required
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white"
                        >
                            @foreach($outcomes as $outcome)
                                <option
                                    value="{{ $outcome->value }}"
                                    @selected(old('outcome') === $outcome->value)
                                >
                                    @if($outcome === \App\Enums\CommerceSettlementReviewResolutionOutcome::RetryWithReferenceSettlement)
                                        Reintentar usando la liquidación de referencia preservada
                                    @elseif($outcome === \App\Enums\CommerceSettlementReviewResolutionOutcome::AbandonCheckout)
                                        Abandonar este checkout
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mt-5 block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Motivo de la resolución
                        </span>
                        <textarea
                            name="reason"
                            rows="4"
                            required
                            minlength="10"
                            maxlength="1000"
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                            placeholder="Explicá la decisión administrativa."
                        >{{ old('reason') }}</textarea>
                    </label>

                    <label class="mt-5 block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Notas internas
                        </span>
                        <textarea
                            name="notes"
                            rows="3"
                            maxlength="2000"
                            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                            placeholder="Opcional."
                        >{{ old('notes') }}</textarea>
                    </label>
                </section>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-amber-300"
                    >
                        Registrar resolución
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
