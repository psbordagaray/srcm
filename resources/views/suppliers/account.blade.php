<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Proveedor · Estado de cuenta</p>
                <h1 class="mt-2 text-2xl font-bold text-white">{{ $supplier->party->name }}</h1>
                <p class="mt-2 text-sm text-slate-400">Historia derivada al {{ $statement['as_of']->format('d/m/Y') }}. Las obligaciones permanecen inmutables.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('suppliers.show', $supplier) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300">Ficha del proveedor</a>
                <a href="{{ route('supplier-payables.aging') }}" class="rounded-xl border border-amber-400/30 px-4 py-2.5 text-sm font-semibold text-amber-200">Aging global</a>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @forelse($statement['totals'] as $currency => $total)
                <article class="sulu-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pendiente {{ $currency }}</p>
                    <p class="mt-3 font-mono text-2xl font-bold text-amber-200">{{ number_format($total['outstanding_minor'] / 100, 2, ',', '.') }}</p>
                    <p class="mt-2 text-xs text-slate-400">Original {{ number_format($total['original_minor'] / 100, 2, ',', '.') }} · imputado {{ number_format($total['settled_minor'] / 100, 2, ',', '.') }}</p>
                    <p class="mt-1 text-xs text-red-300">Vencido {{ number_format($total['overdue_minor'] / 100, 2, ',', '.') }}</p>
                </article>
            @empty
                <article class="sulu-card p-5 md:col-span-2 xl:col-span-4"><p class="text-sm text-slate-400">Este proveedor aún no posee obligaciones reconocidas.</p></article>
            @endforelse
        </div>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4"><h2 class="font-bold text-white">Obligaciones</h2><p class="mt-1 text-xs text-slate-500">Las canceladas salen de la exposición abierta, pero permanecen en este estado de cuenta.</p></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-800">
                <thead class="bg-slate-950/70"><tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500"><th class="px-5 py-4">Obligación / beneficiario</th><th class="px-5 py-4">Condición</th><th class="px-5 py-4">Aging</th><th class="px-5 py-4 text-right">Original</th><th class="px-5 py-4 text-right">Imputado</th><th class="px-5 py-4 text-right">Pendiente</th></tr></thead>
                <tbody class="divide-y divide-slate-800/80">
                    @forelse($statement['obligations'] as $row)
                        <tr>
                            <td class="px-5 py-4"><a href="{{ route('purchase-orders.show', $row['order']) }}" class="font-mono text-xs font-bold text-cyan-200">{{ strtoupper(substr($row['obligation']->public_id, 0, 8)) }}</a><p class="mt-1 text-xs text-slate-500">{{ $row['beneficiary']->name }} · {{ $row['obligation']->kind->label() }}</p></td>
                            <td class="px-5 py-4 text-xs text-slate-300">{{ $row['obligation']->payment_condition->label() }}<p class="mt-1 text-slate-500">{{ $row['effective_due_on']?->format('d/m/Y') ?? 'Sin fecha' }}</p></td>
                            <td class="px-5 py-4 text-xs {{ $row['overdue'] ? 'text-red-300' : 'text-slate-300' }}">{{ $row['aging_label'] }}</td>
                            <td class="px-5 py-4 text-right font-mono text-xs text-slate-300">{{ $row['currency_code'] }} {{ number_format($row['original_minor'] / 100, 2, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right font-mono text-xs text-emerald-300">{{ number_format($row['settled_minor'] / 100, 2, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right font-mono text-sm font-bold text-amber-200">{{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-slate-500">Sin obligaciones.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4"><h2 class="font-bold text-white">Movimientos derivados</h2><p class="mt-1 text-xs text-slate-500">Débito: obligación. Crédito: pago legacy, allocation de desembolso, nota de crédito aplicada o anticipo aplicado.</p></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-800">
                <thead class="bg-slate-950/70"><tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500"><th class="px-5 py-4">Fecha / movimiento</th><th class="px-5 py-4">Beneficiario</th><th class="px-5 py-4">Referencia</th><th class="px-5 py-4 text-right">Débito</th><th class="px-5 py-4 text-right">Crédito</th><th class="px-5 py-4 text-right">Saldo</th></tr></thead>
                <tbody class="divide-y divide-slate-800/80">
                    @forelse($statement['entries'] as $entry)
                        <tr>
                            <td class="px-5 py-4"><p class="text-xs text-slate-300">{{ $entry['occurred_at']->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p><p class="mt-1 text-xs font-semibold text-white">{{ $entry['label'] }}</p></td>
                            <td class="px-5 py-4 text-xs text-slate-300">{{ $entry['beneficiary']->name }}<p class="mt-1 font-mono text-[10px] text-slate-600">{{ $entry['currency_code'] }}</p></td>
                            <td class="px-5 py-4 break-all font-mono text-[11px] text-slate-500">{{ $entry['reference'] }}</td>
                            <td class="px-5 py-4 text-right font-mono text-xs text-amber-200">{{ $entry['debit_minor'] > 0 ? number_format($entry['debit_minor'] / 100, 2, ',', '.') : '—' }}</td>
                            <td class="px-5 py-4 text-right font-mono text-xs text-emerald-300">{{ $entry['credit_minor'] > 0 ? number_format($entry['credit_minor'] / 100, 2, ',', '.') : '—' }}</td>
                            <td class="px-5 py-4 text-right font-mono text-sm font-bold text-white">{{ number_format($entry['running_balance_minor'] / 100, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-slate-500">Sin movimientos.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</x-app-layout>
