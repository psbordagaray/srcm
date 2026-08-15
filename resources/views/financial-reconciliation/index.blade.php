<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6" data-reconciliation-center>
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Finanzas · P6
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">
                    Centro de conciliación
                </h1>
                <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-400">
                    Compara el cobro esperado con movimientos externos candidatos.
                    Bruto, neto, comisiones y retenciones se mantienen separados.
                    Este centro ordena evidencia: no fuerza coincidencias ni ejecuta conciliaciones.
                </p>
            </div>

            <a
                href="{{ route('financial-accounts.index') }}"
                class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-black text-slate-300 hover:border-slate-500 hover:text-white"
            >
                Cuentas financieras
            </a>
        </div>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-white/10 px-5 py-4">
                <p class="text-sm font-bold text-white">
                    {{ $items->count() }} cobro{{ $items->count() === 1 ? '' : 's' }} electrónico{{ $items->count() === 1 ? '' : 's' }} observado{{ $items->count() === 1 ? '' : 's' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Ventana de candidatos: misma organización, cuenta, moneda, ingreso contabilizado y ±7 días.
                </p>
            </div>

            @if($items->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="font-bold text-slate-200">
                        No hay cobros electrónicos para revisar.
                    </p>
                    <p class="mt-2 text-sm text-slate-500">
                        El centro se alimenta de cobros declarados y movimientos externos confirmados.
                    </p>
                </div>
            @else
                <div class="divide-y divide-white/10">
                    @foreach($items as $item)
                        <article class="space-y-4 px-5 py-5" data-reconciliation-payment="{{ $item->paymentId }}">
                            <div class="grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="font-bold text-white">
                                            Venta {{ $item->salePublicId }}
                                        </h2>
                                        <span class="rounded-full bg-slate-800 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-300">
                                            {{ $item->reconciliationStatus }}
                                        </span>
                                    </div>
                                    <p class="mt-2 text-sm text-slate-400">
                                        {{ $item->accountName }} · {{ $item->accountType }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Método {{ $item->paymentMethod }} ·
                                        {{ $item->paidAt->format('d/m/Y H:i:s') }}
                                    </p>
                                    <p class="mt-1 font-mono text-[11px] text-slate-500">
                                        Operación declarada:
                                        {{ $item->declaredExternalOperationId ?: 'sin identificador externo' }}
                                    </p>
                                </div>

                                <div class="rounded-xl border border-cyan-400/10 bg-cyan-400/5 p-4">
                                    <p class="text-[10px] font-black uppercase tracking-wider text-cyan-300">
                                        Cobro esperado
                                    </p>
                                    <p class="mt-2 text-2xl font-black text-white">
                                        {{ $item->currencyCode }}
                                        {{ number_format($item->expectedGrossAmountMinor / 100, 2, ',', '.') }}
                                    </p>

                                    @if($item->latestAllocatedGrossAmountMinor !== null)
                                        <p class="mt-2 text-xs text-slate-400">
                                            Último bruto conciliado:
                                            {{ $item->currencyCode }}
                                            {{ number_format($item->latestAllocatedGrossAmountMinor / 100, 2, ',', '.') }}
                                            · diferencia:
                                            {{ $item->currencyCode }}
                                            {{ number_format($item->latestDifferenceMinor / 100, 2, ',', '.') }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            @if($item->reconciliationStatus === 'difference')
                                <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4" data-reconciliation-resolution>
                                    <div class="grid gap-4 lg:grid-cols-[0.85fr_1.15fr]">
                                        <div>
                                            <p class="text-[10px] font-black uppercase tracking-wider text-amber-300">
                                                Diferencia pendiente de resolución
                                            </p>
                                            <p class="mt-2 text-sm text-slate-300">
                                                La evidencia ya quedó registrada. Resolver agrega un nuevo evento y no modifica el movimiento, el cobro ni la asignación original.
                                            </p>
                                        </div>

                                        <form
                                            method="POST"
                                            action="{{ route('financial-reconciliation.differences.resolve', [
                                                'commercePayment' => $item->paymentId,
                                            ]) }}"
                                            class="space-y-2"
                                        >
                                            @csrf

                                            <textarea
                                                name="note"
                                                rows="3"
                                                minlength="10"
                                                maxlength="2000"
                                                required
                                                placeholder="Describa cómo se resolvió o aceptó esta diferencia"
                                                class="sulu-input w-full text-xs"
                                            ></textarea>

                                            <button
                                                type="submit"
                                                class="w-full rounded-lg border border-amber-400/30 px-3 py-2 text-xs font-black text-amber-100 hover:border-amber-300"
                                            >
                                                Resolver diferencia
                                            </button>

                                            <p class="text-[10px] leading-4 text-slate-500">
                                                La resolución es append-only y requiere una decisión humana explícita.
                                            </p>
                                        </form>
                                    </div>
                                </div>
                            @endif

                            <div>
                                <p class="mb-2 text-[10px] font-black uppercase tracking-wider text-slate-500">
                                    Movimientos candidatos · orden informativo, sin auto-match
                                </p>

                                @if($item->candidates === [])
                                    <div class="rounded-xl border border-amber-400/10 bg-amber-400/5 px-4 py-4 text-sm text-amber-100">
                                        Sin candidatos en la ventana segura.
                                    </div>
                                @else
                                    <div class="overflow-x-auto rounded-xl border border-white/10">
                                        <table class="min-w-full divide-y divide-white/10 text-xs">
                                            <thead class="bg-slate-950/50 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                                <tr>
                                                    <th class="px-4 py-3">Evidencia</th>
                                                    <th class="px-4 py-3">Movimiento</th>
                                                    <th class="px-4 py-3 text-right">Bruto</th>
                                                    <th class="px-4 py-3 text-right">Neto</th>
                                                    <th class="px-4 py-3 text-right">Comisión</th>
                                                    <th class="px-4 py-3 text-right">Retención</th>
                                                    <th class="px-4 py-3 text-right">Dif. bruto</th>
                                                    <th class="px-4 py-3 text-right">Decisión</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/5">
                                                @foreach($item->candidates as $candidate)
                                                    <tr data-reconciliation-candidate="{{ $candidate->movementPublicId }}">
                                                        <td class="px-4 py-3">
                                                            <span class="rounded-full px-2 py-1 text-[10px] font-black uppercase {{ $candidate->evidenceLevel === 'strong' ? 'bg-emerald-500/10 text-emerald-200' : ($candidate->evidenceLevel === 'medium' ? 'bg-cyan-500/10 text-cyan-200' : 'bg-amber-500/10 text-amber-200') }}">
                                                                {{ $candidate->evidenceLevel }}
                                                            </span>
                                                            <p class="mt-2 text-[10px] text-slate-500">
                                                                {{ implode(' · ', $candidate->evidenceCodes) }}
                                                            </p>
                                                        </td>
                                                        <td class="px-4 py-3">
                                                            <p class="font-mono text-slate-300">
                                                                {{ $candidate->externalOperationId ?: $candidate->sourceKey }}
                                                            </p>
                                                            <p class="mt-1 text-[10px] text-slate-500">
                                                                {{ $candidate->source }} ·
                                                                {{ $candidate->occurredAt->format('d/m/Y H:i:s') }}
                                                            </p>
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono font-bold text-white">
                                                            {{ $item->currencyCode }}
                                                            {{ number_format($candidate->grossAmountMinor / 100, 2, ',', '.') }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono text-slate-300">
                                                            {{ $item->currencyCode }}
                                                            {{ number_format($candidate->netAmountMinor / 100, 2, ',', '.') }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono text-slate-400">
                                                            {{ $item->currencyCode }}
                                                            {{ number_format($candidate->feeAmountMinor / 100, 2, ',', '.') }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono text-slate-400">
                                                            {{ $item->currencyCode }}
                                                            {{ number_format($candidate->withholdingAmountMinor / 100, 2, ',', '.') }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono {{ $candidate->grossDifferenceMinor === 0 ? 'text-emerald-300' : 'text-amber-300' }}">
                                                            {{ $item->currencyCode }}
                                                            {{ number_format($candidate->grossDifferenceMinor / 100, 2, ',', '.') }}
                                                        </td>
                                                        <td class="min-w-64 px-4 py-3">
                                                            <form
                                                                method="POST"
                                                                action="{{ route('financial-reconciliation.candidates.reconcile', [
                                                                    'commercePayment' => $item->paymentId,
                                                                    'financialExternalMovement' => $candidate->movementPublicId,
                                                                ]) }}"
                                                                class="space-y-2"
                                                            >
                                                                @csrf

                                                                @if($candidate->grossDifferenceMinor !== 0)
                                                                    <textarea
                                                                        name="note"
                                                                        rows="2"
                                                                        minlength="10"
                                                                        maxlength="2000"
                                                                        required
                                                                        placeholder="Explique la diferencia antes de confirmar"
                                                                        class="sulu-input w-full text-xs"
                                                                    ></textarea>
                                                                @endif

                                                                <button
                                                                    type="submit"
                                                                    class="w-full rounded-lg border border-cyan-400/30 px-3 py-2 text-xs font-black text-cyan-100 hover:border-cyan-300"
                                                                >
                                                                    Conciliar este movimiento
                                                                </button>

                                                                <p class="text-[10px] leading-4 text-slate-500">
                                                                    Acción explícita. El ranking no concilia automáticamente.
                                                                </p>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
