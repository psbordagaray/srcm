<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Compras · Cuentas por pagar</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Exposición y aging de proveedores</h1>
                <p class="mt-2 text-sm text-slate-400">Read model al {{ $report['as_of']->format('d/m/Y') }}. Se deriva de obligaciones e imputaciones confirmadas; no existe saldo paralelo.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('purchase-orders.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300">Compras</a>
                <a href="{{ route('purchase-payment-operations.index') }}" class="rounded-xl border border-cyan-400/30 px-4 py-2.5 text-sm font-semibold text-cyan-200">Operación de pagos</a>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @forelse($report['totals'] as $currency => $total)
                <article class="sulu-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Exposición {{ $currency }}</p>
                    <p class="mt-3 font-mono text-2xl font-bold text-amber-200">{{ number_format($total['outstanding_minor'] / 100, 2, ',', '.') }}</p>
                    <p class="mt-2 text-xs text-red-300">Vencido {{ number_format($total['overdue_minor'] / 100, 2, ',', '.') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $total['obligation_count'] }} obligación{{ $total['obligation_count'] === 1 ? '' : 'es' }} abierta{{ $total['obligation_count'] === 1 ? '' : 's' }}</p>
                </article>
            @empty
                <article class="sulu-card p-5 md:col-span-2 xl:col-span-4">
                    <p class="text-sm text-emerald-200">No existen obligaciones abiertas en la organización.</p>
                </article>
            @endforelse
        </div>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4">
                <h2 class="font-bold text-white">Exposición por proveedor, beneficiario y moneda</h2>
                <p class="mt-1 text-xs text-slate-500">Un beneficiario distinto nunca se mezcla silenciosamente con la cuenta principal del proveedor.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/70">
                        <tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="px-5 py-4">Proveedor / beneficiario</th>
                            <th class="px-5 py-4">Moneda</th>
                            <th class="px-5 py-4 text-right">Pendiente</th>
                            <th class="px-5 py-4 text-right">Vencido</th>
                            <th class="px-5 py-4">Antigüedad</th>
                            <th class="px-5 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @forelse($report['suppliers'] as $row)
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="text-sm font-semibold text-white">{{ $row['supplier_party']->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Beneficiario: {{ $row['beneficiary']->name }}</p>
                                </td>
                                <td class="px-5 py-4 font-mono text-xs text-slate-300">{{ $row['currency_code'] }}</td>
                                <td class="px-5 py-4 text-right font-mono text-sm font-bold text-amber-200">{{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }}</td>
                                <td class="px-5 py-4 text-right font-mono text-sm text-red-300">{{ number_format($row['overdue_minor'] / 100, 2, ',', '.') }}</td>
                                <td class="px-5 py-4 text-xs text-slate-400">{{ $row['oldest_days_overdue'] > 0 ? $row['oldest_days_overdue'].' días' : 'Al día / sin fecha' }}</td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('suppliers.account', $row['supplier']) }}" class="text-xs font-semibold text-cyan-300">Estado de cuenta</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-slate-500">Sin exposición abierta.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4">
                <h2 class="font-bold text-white">Obligaciones abiertas</h2>
                <p class="mt-1 text-xs text-slate-500">Autorizaciones y evidencia externa no reducen estos importes. Sólo cuentan imputaciones económicas confirmadas.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/70"><tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500"><th class="px-5 py-4">Proveedor</th><th class="px-5 py-4">Obligación</th><th class="px-5 py-4">Vencimiento efectivo</th><th class="px-5 py-4">Aging</th><th class="px-5 py-4 text-right">Pendiente</th></tr></thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @forelse($report['obligations'] as $row)
                            <tr>
                                <td class="px-5 py-4"><p class="text-sm text-white">{{ $row['supplier']->party->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $row['beneficiary']->name }}</p></td>
                                <td class="px-5 py-4"><a href="{{ route('purchase-orders.show', $row['order']) }}" class="font-mono text-xs font-bold text-cyan-200">{{ strtoupper(substr($row['obligation']->public_id, 0, 8)) }}</a><p class="mt-1 text-xs text-slate-500">{{ $row['obligation']->kind->label() }}</p></td>
                                <td class="px-5 py-4 text-xs text-slate-300">{{ $row['effective_due_on']?->format('d/m/Y') ?? 'Sin fecha' }}<p class="mt-1 text-[10px] uppercase text-slate-600">{{ $row['due_source'] }}</p></td>
                                <td class="px-5 py-4 text-xs {{ $row['overdue'] ? 'text-red-300' : 'text-slate-300' }}">{{ $row['aging_label'] }}{{ $row['days_overdue'] ? ' · '.$row['days_overdue'].' días' : '' }}</td>
                                <td class="px-5 py-4 text-right font-mono text-sm font-bold text-amber-200">{{ $row['currency_code'] }} {{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-8 text-center text-sm text-slate-500">Sin obligaciones abiertas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
