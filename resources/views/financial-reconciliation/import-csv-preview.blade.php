<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6" data-statement-preview>
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Finanzas · P7.2
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">
                    Vista previa CSV validada
                </h1>
                <p class="mt-2 text-sm text-slate-400">
                    {{ $preview->accountName }} · {{ $preview->currencyCode }} · {{ $preview->rowCount() }} movimiento{{ $preview->rowCount() === 1 ? '' : 's' }}
                </p>
            </div>

            <a
                href="{{ route('financial-statement-imports.csv.create') }}"
                class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-black text-slate-300 hover:border-slate-500 hover:text-white"
            >
                Probar otro archivo
            </a>
        </div>

        <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 px-4 py-3 text-sm text-emerald-100">
            La vista previa sigue sin alterar la verdad financiera. El siguiente botón ejecuta un commit explícito e idempotente sobre el ledger externo.
        </div>

        <section class="sulu-card p-5">
            <form
                method="POST"
                action="{{ route('financial-statement-imports.csv.store') }}"
                class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center"
            >
                @csrf
                <input
                    type="hidden"
                    name="token"
                    value="{{ $token }}"
                >

                <div>
                    <p class="text-sm font-black text-white">
                        Confirmación explícita
                    </p>
                    <p class="mt-1 text-xs leading-5 text-slate-400">
                        Se registrarán como movimientos externos CSV con estado posted. No se conciliará automáticamente ni se modificará Venta o Caja.
                    </p>
                </div>

                <button
                    type="submit"
                    class="rounded-xl border border-cyan-400/30 bg-cyan-400/10 px-5 py-3 text-sm font-black text-cyan-100 hover:border-cyan-300"
                >
                    Importar estos movimientos
                </button>
            </form>
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-white/10 px-5 py-4">
                <p class="font-mono text-xs text-slate-400">
                    Archivo: {{ $preview->fileName }}
                </p>
                <p class="mt-1 break-all font-mono text-[10px] text-slate-600">
                    SHA-256 {{ $preview->fileSha256 }}
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-xs">
                    <thead class="bg-slate-950/50 text-left text-[10px] font-black uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Línea</th>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Dirección</th>
                            <th class="px-4 py-3 text-right">Bruto</th>
                            <th class="px-4 py-3 text-right">Comisión</th>
                            <th class="px-4 py-3 text-right">Retención</th>
                            <th class="px-4 py-3 text-right">Neto</th>
                            <th class="px-4 py-3">Operación / referencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach($preview->rows as $row)
                            <tr data-statement-preview-row="{{ $row->lineNumber }}">
                                <td class="px-4 py-3 font-mono text-slate-500">
                                    {{ $row->lineNumber }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-slate-300">
                                    {{ $row->occurredAt->format('d/m/Y H:i:s') }} UTC
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-slate-800 px-2 py-1 text-[10px] font-black uppercase text-slate-300">
                                        {{ $row->direction->value }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-bold text-white">
                                    {{ $row->currencyCode }}
                                    {{ number_format($row->grossAmountMinor / 100, 2, ',', '.') }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-slate-400">
                                    {{ number_format($row->feeAmountMinor / 100, 2, ',', '.') }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-slate-400">
                                    {{ number_format($row->withholdingAmountMinor / 100, 2, ',', '.') }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-slate-300">
                                    {{ number_format($row->netAmountMinor / 100, 2, ',', '.') }}
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-mono text-slate-300">
                                        {{ $row->externalOperationId ?: 'sin ID externo' }}
                                    </p>
                                    <p class="mt-1 max-w-md text-[10px] text-slate-500">
                                        {{ $row->reference ?: 'sin referencia' }}
                                    </p>
                                    <p class="mt-1 max-w-md break-all font-mono text-[9px] text-slate-700">
                                        {{ $row->sourceKey }}
                                    </p>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
