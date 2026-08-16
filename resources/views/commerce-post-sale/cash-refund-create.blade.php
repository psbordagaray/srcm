<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-rose-300">Comercio · Posventa · Reembolso en efectivo</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Ejecutar salida de caja</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Venta #{{ $resolution->request->sale->sale_number }}
                    · {{ $resolution->request->sale->customer_name_snapshot }}
                </p>
            </div>

            <a
                href="{{ route('commerce-post-sale.show', $resolution->request) }}"
                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
            >
                Volver al expediente
            </a>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">No se pudo ejecutar el reembolso.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($resolution->cashRefundExecution)
            <section class="sulu-card p-8 text-center">
                <p class="text-lg font-bold text-emerald-200">Reembolso ya ejecutado</p>
                <p class="mt-2 text-sm text-slate-500">
                    Este hecho es append-only y no puede volver a ejecutarse.
                </p>
            </section>
        @else
            <section class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-rose-300">Segregación obligatoria</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    El usuario que resolvió económicamente no puede ejecutar esta salida. El ejecutor debe operar una sesión de caja abierta sobre la misma cuenta usada por el cobro original.
                </p>
            </section>

            <section class="sulu-card p-6">
                <dl class="grid gap-5 md:grid-cols-3">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Valor reconocido</dt>
                        <dd class="mt-2 font-mono text-xl font-bold text-white">
                            $ {{ number_format($resolution->recognizedAmountMinor() / 100, 2, ',', '.') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Pago original</dt>
                        <dd class="mt-2 text-sm font-semibold text-slate-200">
                            {{ $resolution->preferredOriginalPayment->method->label() }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cuenta original</dt>
                        <dd class="mt-2 text-sm font-semibold text-slate-200">
                            {{ $resolution->preferredOriginalPayment->financialAccount?->name ?? 'Sin cuenta' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <form
                method="POST"
                action="{{ route('commerce-post-sale.cash-refunds.store', $resolution) }}"
                class="sulu-card space-y-5 p-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Referencia de ejecución</span>
                    <input
                        type="text"
                        name="execution_reference"
                        value="{{ old('execution_reference') }}"
                        maxlength="180"
                        placeholder="Ej.: comprobante interno o referencia de mostrador"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                    >
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nota de ejecución</span>
                    <textarea
                        name="execution_note"
                        rows="3"
                        maxlength="1000"
                        placeholder="Opcional. Describe la entrega efectiva del dinero."
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder:text-slate-600"
                    >{{ old('execution_note') }}</textarea>
                </label>

                <div class="flex flex-wrap justify-end gap-3">
                    <a
                        href="{{ route('commerce-post-sale.show', $resolution->request) }}"
                        class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300"
                    >
                        Cancelar
                    </a>
                    <button
                        type="submit"
                        class="rounded-xl bg-rose-300 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-rose-200"
                    >
                        Confirmar salida de efectivo
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
