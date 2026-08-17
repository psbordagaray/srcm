<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">CxC · Aging</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Antigüedad de cuentas por cobrar</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Corte al {{ $report['as_of']->format('d/m/Y') }}. Los importes se derivan de deudas reconocidas menos aplicaciones de cobranzas confirmadas.
                </p>
            </div>
            <a href="{{ route('customers.index') }}" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200">Clientes</a>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @forelse($report['totals'] as $currency => $total)
                <section class="sulu-card p-5" data-aging-currency="{{ $currency }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $currency }} · Pendiente total</p>
                            <p class="mt-2 font-mono text-2xl font-black text-amber-200">{{ number_format($total['outstanding_minor'] / 100, 2, ',', '.') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wider text-slate-500">Vencido</p>
                            <p class="mt-2 font-mono text-xl font-black text-red-300">{{ number_format($total['overdue_minor'] / 100, 2, ',', '.') }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $total['receivable_count'] }} deuda(s) abierta(s)</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($total['buckets'] as $bucket => $amount)
                            <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-3">
                                <p class="text-xs font-semibold text-slate-400">{{ $report['bucket_labels'][$bucket] }}</p>
                                <p class="mt-1 font-mono font-bold text-slate-200">{{ number_format($amount / 100, 2, ',', '.') }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <section class="sulu-card p-6 xl:col-span-2">
                    <p class="text-sm text-slate-500">No hay cuentas por cobrar pendientes al corte.</p>
                </section>
            @endforelse
        </div>

        <section class="sulu-card p-6">
            <div>
                <h2 class="text-lg font-bold text-white">Exposición por cliente</h2>
                <p class="mt-1 text-sm text-slate-500">Prioriza clientes con mayor importe vencido y conserva cada moneda separada.</p>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Cliente</th>
                            <th class="px-3 py-2">Moneda</th>
                            <th class="px-3 py-2 text-right">Pendiente</th>
                            <th class="px-3 py-2 text-right">Vencido</th>
                            <th class="px-3 py-2 text-right">Mayor atraso</th>
                            <th class="px-3 py-2 text-right">Deudas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($report['customers'] as $row)
                            <tr data-aging-customer-row>
                                <td class="px-3 py-3">
                                    @if($row['customer'])
                                        <a href="{{ route('customers.account', $row['customer']) }}" class="font-semibold text-cyan-200 hover:text-cyan-100">{{ $row['party']->name }}</a>
                                    @else
                                        <span class="font-semibold text-slate-200">{{ $row['party']->name }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-slate-300">{{ $row['currency_code'] }}</td>
                                <td class="px-3 py-3 text-right font-mono font-bold text-amber-200">{{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }}</td>
                                <td class="px-3 py-3 text-right font-mono font-bold {{ $row['overdue_minor'] > 0 ? 'text-red-300' : 'text-slate-500' }}">{{ number_format($row['overdue_minor'] / 100, 2, ',', '.') }}</td>
                                <td class="px-3 py-3 text-right text-slate-300">{{ $row['oldest_days_overdue'] > 0 ? $row['oldest_days_overdue'].' días' : 'Al día' }}</td>
                                <td class="px-3 py-3 text-right text-slate-300">{{ $row['receivable_count'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Sin exposición pendiente.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sulu-card p-6">
            <div>
                <h2 class="text-lg font-bold text-white">Detalle de vencimientos abiertos</h2>
                <p class="mt-1 text-sm text-slate-500">Una deuda con cuotas propias aparece por vencimiento para clasificar sólo la porción realmente vencida. Los buckets siguen siendo una lectura derivada.</p>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Cliente</th>
                            <th class="px-3 py-2">Venta</th>
                            <th class="px-3 py-2">Cuota</th>
                            <th class="px-3 py-2">Vencimiento</th>
                            <th class="px-3 py-2">Aging</th>
                            <th class="px-3 py-2 text-right">Pendiente</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($report['receivables'] as $row)
                            <tr data-aging-receivable-row>
                                <td class="px-3 py-3">
                                    @if($row['customer'])
                                        <a href="{{ route('customers.account', $row['customer']) }}" class="font-semibold text-cyan-200 hover:text-cyan-100">{{ $row['party']->name }}</a>
                                    @else
                                        <span class="text-slate-200">{{ $row['party']->name }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <a href="{{ route('commerce-sales.show', $row['sale']) }}" class="text-cyan-200 hover:text-cyan-100">#{{ $row['sale']->sale_number }}</a>
                                </td>
                                <td class="px-3 py-3 text-slate-300">
                                    {{ $row['planned'] ? 'Cuota '.$row['sequence'].'/'.$row['installment_count'] : 'Única' }}
                                </td>
                                <td class="px-3 py-3 text-slate-300">{{ $row['due_on'] ? $row['due_on']->format('d/m/Y') : 'Sin vencimiento' }}</td>
                                <td class="px-3 py-3">
                                    <span class="{{ $row['overdue'] ? 'font-bold text-red-300' : 'text-slate-300' }}">{{ $row['aging_label'] }}</span>
                                    @if($row['days_overdue'])
                                        <span class="ml-1 text-xs text-slate-500">({{ $row['days_overdue'] }} días)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right font-mono font-black text-amber-200">{{ $row['receivable']->currency_code }} {{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Sin vencimientos abiertos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
