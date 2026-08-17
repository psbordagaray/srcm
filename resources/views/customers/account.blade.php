<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Cuenta corriente · CxC</p>
                <h1 class="mt-2 text-2xl font-bold text-white">{{ $party->name }}</h1>
                <p class="mt-2 text-sm text-slate-400">Las deudas y cobranzas son hechos separados. El saldo se deriva de las aplicaciones confirmadas.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('customers.aging') }}" class="rounded-xl border border-amber-400/30 px-4 py-2 text-sm font-semibold text-amber-200">Reporte de aging</a>
                <a href="{{ route('customers.show', $customer) }}" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200">Volver al cliente</a>
            </div>
        </div>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="sulu-card p-6" data-customer-credit-balance>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-300">Saldo a favor</p>
                    <h2 class="mt-2 text-lg font-bold text-white">Saldo a favor disponible</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Es una lectura derivada de créditos de posventa, diferencias de cambio y anticipos confirmados, menos sus consumos.
                    </p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach($creditBalanceSnapshots as $currency => $balanceMinor)
                    <article class="rounded-2xl border border-slate-800 bg-slate-950/45 p-4">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ $currency }}</p>
                        <p class="mt-2 font-mono text-2xl font-black {{ $balanceMinor > 0 ? 'text-emerald-200' : 'text-slate-500' }}">
                            {{ number_format($balanceMinor / 100, 2, ',', '.') }}
                        </p>
                    </article>
                @endforeach
            </div>

            <div class="mt-5 rounded-2xl border border-cyan-400/20 bg-cyan-400/5 p-4 text-sm text-slate-300">
                <strong class="text-cyan-200">Anticipo a cuenta:</strong>
                el dinero recibido queda disponible para una venta futura mediante crédito en cuenta.
                Este corte <strong>no reserva mercadería</strong>, no crea una venta y no genera una seña/reserva de stock.
            </div>

            @can('record-customer-collections')
                <div class="mt-6 border-t border-slate-800 pt-5" data-customer-advance-form>
                    <h3 class="font-bold text-white">Registrar anticipo a cuenta</h3>
                    <p class="mt-1 text-xs text-slate-500">
                        Administrador u Operador pueden registrar dinero efectivamente recibido. La reserva de mercadería permanece separada.
                    </p>

                    <form method="POST" action="{{ route('customers.advances.store', $customer) }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $advanceIdempotencyKey) }}">

                        <div class="grid gap-4 md:grid-cols-4">
                            <div>
                                <label class="text-xs font-bold text-slate-400">Moneda</label>
                                <input name="currency_code" value="{{ old('currency_code', 'ARS') }}" maxlength="3" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 uppercase text-white">
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-400">Medio</label>
                                <select name="method" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                                    @foreach($methods as $method)
                                        <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-400">Cuenta destino</label>
                                <select name="financial_account_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                                    <option value="">Seleccionar</option>
                                    @foreach($financialAccounts as $financialAccount)
                                        <option value="{{ $financialAccount->id }}" @selected((string) old('financial_account_id') === (string) $financialAccount->id)>
                                            {{ $financialAccount->currency_code }} · {{ $financialAccount->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-400">Importe</label>
                                <input name="amount" value="{{ old('amount') }}" inputmode="decimal" placeholder="0,00" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white">
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="text-xs font-bold text-slate-400">Referencia no efectivo</label>
                                <input name="reference" value="{{ old('reference') }}" maxlength="255" placeholder="Transferencia / operación" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-400">Entregado en efectivo</label>
                                <input name="tendered_amount" value="{{ old('tendered_amount') }}" inputmode="decimal" placeholder="Opcional" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white">
                            </div>

                            <div>
                                <label class="text-xs font-bold text-slate-400">Notas</label>
                                <input name="notes" value="{{ old('notes') }}" maxlength="1000" placeholder="Contexto del anticipo" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                            </div>
                        </div>

                        @if($currentCashSession)
                            <p class="text-xs text-slate-500">
                                Caja abierta: {{ $currentCashSession->currency_code }}. Para efectivo, elegí la cuenta de esa caja.
                            </p>
                        @endif

                        <button class="rounded-xl border border-emerald-400/30 px-4 py-2 text-sm font-black text-emerald-200">
                            Confirmar anticipo
                        </button>
                    </form>
                </div>
            @endcan

            <div class="mt-6 border-t border-slate-800 pt-5">
                <h3 class="font-bold text-white">Anticipos confirmados</h3>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Medio / referencia</th>
                                <th class="px-3 py-2 text-right">Recibido</th>
                                <th class="px-3 py-2 text-right">Consumido</th>
                                <th class="px-3 py-2 text-right">Disponible</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse($advances as $advance)
                                @php($advanceConsumed = (int) $advance->creditAllocations->sum('amount_minor'))
                                <tr data-customer-advance-row>
                                    <td class="px-3 py-3 text-slate-300">{{ $advance->received_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-3">
                                        <p class="font-semibold text-slate-200">{{ $advance->method->label() }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $advance->reference ?: 'Sin referencia electrónica' }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-emerald-200">{{ $advance->currency_code }} {{ number_format($advance->amount_minor / 100, 2, ',', '.') }}</td>
                                    <td class="px-3 py-3 text-right font-mono text-slate-400">{{ number_format($advanceConsumed / 100, 2, ',', '.') }}</td>
                                    <td class="px-3 py-3 text-right font-mono font-black text-emerald-200">{{ number_format(max(0, $advance->amount_minor - $advanceConsumed) / 100, 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-5 text-center text-slate-500">Sin anticipos confirmados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="sulu-card p-6" data-customer-credit-policy>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-violet-300">Política de crédito</p>
                    <h2 class="mt-2 text-lg font-bold text-white">Cupo controlado por cliente y moneda</h2>
                    <p class="mt-1 text-sm text-slate-500">El cupo usa exposición derivada. Con política vigente, deuda vencida o sobrelímite bloquean al Operador y requieren una excepción explícita de Administrador.</p>
                </div>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-2">
                @foreach($creditPolicySnapshots as $currency => $snapshot)
                    <article class="rounded-2xl border border-slate-800 bg-slate-950/45 p-5" data-credit-policy-currency="{{ $currency }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="font-mono text-lg font-black text-white">{{ $currency }}</p>
                                @if($snapshot['policy_configured'])
                                    <p class="mt-1 text-xs font-semibold text-emerald-300">Política vigente · versión {{ $snapshot['policy']->version }}</p>
                                @else
                                    <p class="mt-1 text-xs font-semibold text-amber-300">Modo transitorio · sólo Administrador puede vender a crédito hasta configurar el primer límite.</p>
                                @endif
                            </div>
                            <div class="text-right">
                                <p class="text-xs uppercase tracking-wider text-slate-500">Límite</p>
                                <p class="mt-1 font-mono text-lg font-black text-violet-200">
                                    {{ $snapshot['policy_configured'] ? number_format($snapshot['limit_minor'] / 100, 2, ',', '.') : 'Sin configurar' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-800 p-3">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Exposición</p>
                                <p class="mt-1 font-mono font-bold text-amber-200">{{ number_format($snapshot['exposure_minor'] / 100, 2, ',', '.') }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-800 p-3">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Vencido</p>
                                <p class="mt-1 font-mono font-bold {{ $snapshot['overdue_minor'] > 0 ? 'text-red-300' : 'text-slate-300' }}">{{ number_format($snapshot['overdue_minor'] / 100, 2, ',', '.') }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-800 p-3">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Disponible</p>
                                <p class="mt-1 font-mono font-bold text-emerald-200">{{ $snapshot['policy_configured'] ? number_format($snapshot['available_minor'] / 100, 2, ',', '.') : '—' }}</p>
                            </div>
                        </div>

                        @if($snapshot['overdue_minor'] > 0)
                            <p class="mt-3 rounded-xl border border-red-400/20 bg-red-400/10 px-3 py-2 text-xs font-bold text-red-200">
                                Hay deuda vencida. Aunque exista cupo disponible, el nuevo crédito requiere excepción de Administrador.
                            </p>
                        @endif

                        @can('manage-customer-credit-policies')
                            <form method="POST" action="{{ route('customers.credit-policies.store', $customer) }}" class="mt-5 space-y-3 border-t border-slate-800 pt-4">
                                @csrf
                                <input type="hidden" name="currency_code" value="{{ $currency }}">
                                <input type="hidden" name="idempotency_key" value="{{ $creditPolicyIdempotencyKeys[$currency] }}">

                                <div>
                                    <label class="text-xs font-bold text-slate-400">Nuevo límite {{ $currency }}</label>
                                    <input
                                        name="limit"
                                        inputmode="decimal"
                                        value="{{ old('currency_code') === $currency ? old('limit') : ($snapshot['policy_configured'] ? number_format($snapshot['limit_minor'] / 100, 2, '.', '') : '') }}"
                                        placeholder="0,00"
                                        required
                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white"
                                    >
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-slate-400">Motivo de la nueva versión</label>
                                    <textarea
                                        name="reason"
                                        rows="2"
                                        maxlength="2000"
                                        required
                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
                                        placeholder="Fundamento administrativo del cupo."
                                    >{{ old('currency_code') === $currency ? old('reason') : '' }}</textarea>
                                </div>
                                <button class="rounded-xl border border-violet-400/30 px-4 py-2 text-sm font-black text-violet-200">
                                    Registrar nueva versión
                                </button>
                            </form>
                        @endcan
                    </article>
                @endforeach
            </div>
        </section>
        <div class="grid gap-4 md:grid-cols-3">
            @forelse($account['totals'] as $currency => $total)
                <div class="sulu-card p-5">
                    <p class="text-xs uppercase tracking-wider text-slate-500">{{ $currency }} · Saldo pendiente</p>
                    <p class="mt-2 font-mono text-2xl font-black text-amber-200">{{ number_format($total['outstanding_minor'] / 100, 2, ',', '.') }}</p>
                    <p class="mt-2 text-xs text-slate-500">
                        Original {{ number_format($total['original_minor'] / 100, 2, ',', '.') }}
                        · Cobrado {{ number_format($total['collected_minor'] / 100, 2, ',', '.') }}
                    </p>
                </div>
            @empty
                <div class="sulu-card p-5 md:col-span-3">
                    <p class="text-sm text-slate-500">El cliente todavía no posee cuentas por cobrar reconocidas.</p>
                </div>
            @endforelse
        </div>

        <section class="sulu-card p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-white">Deudas del cliente</h2>
                    <p class="mt-1 text-sm text-slate-500">El pendiente nunca se edita: se calcula desde la deuda original menos cobranzas confirmadas. En cuotas propias, la cobranza se imputa a la cuota más antigua primero.</p>
                </div>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Venta</th>
                            <th class="px-3 py-2">Vencimiento</th>
                            <th class="px-3 py-2">Aging</th>
                            <th class="px-3 py-2 text-right">Original</th>
                            <th class="px-3 py-2 text-right">Cobrado</th>
                            <th class="px-3 py-2 text-right">Pendiente</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($account['receivables'] as $row)
                            <tr data-customer-receivable-row>
                                <td class="px-3 py-3">
                                    <a class="font-semibold text-cyan-200 hover:text-cyan-100" href="{{ route('commerce-sales.show', $row['sale']) }}">
                                        Venta #{{ $row['sale']->sale_number }}
                                    </a>
                                </td>
                                <td class="px-3 py-3 {{ $row['overdue'] ? 'font-bold text-red-300' : 'text-slate-300' }}">
                                    {{ $row['next_due_on'] ? $row['next_due_on']->format('d/m/Y') : ($row['outstanding_minor'] > 0 ? 'Sin vencimiento' : 'Cancelado') }}
                                    @if($row['planned_installments'])
                                        <p class="mt-1 text-[11px] font-semibold text-amber-200">Cronograma de {{ $row['installment_count'] }} cuotas</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <span class="{{ $row['overdue'] ? 'font-bold text-red-300' : 'text-slate-300' }}">{{ $row['aging_label'] }}</span>
                                    @if($row['days_overdue'])
                                        <span class="ml-1 text-xs text-slate-500">({{ $row['days_overdue'] }} días)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right font-mono text-slate-300">{{ $row['receivable']->currency_code }} {{ number_format($row['original_minor'] / 100, 2, ',', '.') }}</td>
                                <td class="px-3 py-3 text-right font-mono text-emerald-300">{{ number_format($row['collected_minor'] / 100, 2, ',', '.') }}</td>
                                <td class="px-3 py-3 text-right font-mono font-black {{ $row['outstanding_minor'] > 0 ? 'text-amber-200' : 'text-slate-500' }}">{{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }}</td>
                            </tr>
                            @if($row['planned_installments'])
                                <tr class="bg-slate-950/30" data-customer-installment-schedule>
                                    <td colspan="6" class="px-3 pb-4 pt-1">
                                        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                            @foreach($row['installments'] as $installment)
                                                <div class="rounded-xl border border-slate-800 px-3 py-2 text-xs">
                                                    <div class="flex items-center justify-between gap-3">
                                                        <span class="font-black text-slate-200">Cuota {{ $installment['sequence'] }}/{{ $installment['installment_count'] }}</span>
                                                        <span class="{{ $installment['overdue'] ? 'font-bold text-red-300' : 'text-slate-400' }}">
                                                            {{ $installment['due_on']?->format('d/m/Y') ?? 'Sin vencimiento' }}
                                                        </span>
                                                    </div>
                                                    <p class="mt-1 font-mono text-slate-400">
                                                        Original {{ number_format($installment['original_minor'] / 100, 2, ',', '.') }}
                                                        · Cobrado {{ number_format($installment['collected_minor'] / 100, 2, ',', '.') }}
                                                        · Pendiente <strong class="{{ $installment['outstanding_minor'] > 0 ? 'text-amber-200' : 'text-emerald-300' }}">{{ number_format($installment['outstanding_minor'] / 100, 2, ',', '.') }}</strong>
                                                    </p>
                                                    <p class="mt-1 {{ $installment['overdue'] ? 'font-bold text-red-300' : 'text-slate-500' }}">{{ $installment['aging_label'] }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                        <p class="mt-2 text-[11px] font-semibold text-cyan-300">FIFO: cada cobranza aplicada a esta deuda consume la cuota pendiente más antigua primero.</p>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Sin deudas reconocidas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @can('record-customer-collections')
            @php($openRows = $account['receivables']->filter(fn ($row) => $row['outstanding_minor'] > 0))
            @if($openRows->isNotEmpty())
                <section class="sulu-card p-6" data-customer-collection-form>
                    <h2 class="text-lg font-bold text-white">Registrar cobranza</h2>
                    <p class="mt-1 text-sm text-slate-500">Una cobranza puede aplicarse parcialmente a una deuda o distribuirse entre varias de la misma moneda. En deudas con cuotas propias, SRCM imputa automáticamente a la cuota más antigua primero. No genera saldo a favor en este corte.</p>

                    <form method="POST" action="{{ route('customers.collections.store', $customer) }}" class="mt-5 space-y-5">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                        <div class="grid gap-4 md:grid-cols-4">
                            <div>
                                <label class="text-xs font-bold text-slate-400">Moneda</label>
                                <input name="currency_code" value="{{ old('currency_code', $openRows->first()['receivable']->currency_code) }}" maxlength="3" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 uppercase text-white">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-400">Medio</label>
                                <select name="method" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                                    @foreach($methods as $method)
                                        <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-400">Cuenta destino</label>
                                <select name="financial_account_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                                    <option value="">Seleccionar…</option>
                                    @foreach($financialAccounts as $financialAccount)
                                        <option value="{{ $financialAccount->id }}" @selected((string) old('financial_account_id') === (string) $financialAccount->id)>
                                            {{ $financialAccount->name }} · {{ $financialAccount->currency_code }} · {{ $financialAccount->type->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-400">Importe cobrado</label>
                                <input name="amount" value="{{ old('amount') }}" inputmode="decimal" placeholder="0,00" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white">
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="text-xs font-bold text-slate-400">Referencia no efectiva</label>
                                <input name="reference" value="{{ old('reference') }}" maxlength="255" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white" placeholder="Transferencia / comprobante">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-400">Dinero entregado (sólo efectivo)</label>
                                <input name="tendered_amount" value="{{ old('tendered_amount') }}" inputmode="decimal" placeholder="Opcional" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-400">Notas</label>
                                <input name="notes" value="{{ old('notes') }}" maxlength="1000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white">
                            </div>
                        </div>

                        @if($currentCashSession)
                            <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-xs text-cyan-100">
                                Turno de caja activo: {{ $currentCashSession->register->name }} · {{ $currentCashSession->currency_code }}. El efectivo sólo puede ingresar por la cuenta de ese turno.
                            </div>
                        @endif

                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Aplicación a deudas</p>
                            <div class="mt-3 space-y-3">
                                @foreach($openRows as $index => $row)
                                    <div class="grid gap-3 rounded-xl border border-slate-800 p-4 md:grid-cols-[1fr_180px]">
                                        <div>
                                            <p class="font-semibold text-slate-200">Venta #{{ $row['sale']->sale_number }} · {{ $row['receivable']->currency_code }}</p>
                                            <p class="mt-1 text-xs text-slate-500">Pendiente {{ number_format($row['outstanding_minor'] / 100, 2, ',', '.') }} · vence {{ $row['receivable']->due_on ? $row['receivable']->due_on->format('d/m/Y') : 'sin fecha' }}</p>
                                        </div>
                                        <div>
                                            <input type="hidden" name="allocations[{{ $index }}][customer_receivable_id]" value="{{ $row['receivable']->id }}">
                                            <input name="allocations[{{ $index }}][amount]" value="{{ old('allocations.'.$index.'.amount') }}" inputmode="decimal" placeholder="Aplicar 0,00" class="w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button class="rounded-xl bg-cyan-500 px-5 py-3 text-sm font-black text-slate-950 hover:bg-cyan-400">Confirmar cobranza</button>
                        </div>
                    </form>
                </section>
            @endif
        @endcan

        <section class="sulu-card p-6">
            <h2 class="text-lg font-bold text-white">Historial de cobranzas</h2>
            <div class="mt-4 space-y-3">
                @forelse($account['collections'] as $collection)
                    <div class="rounded-xl border border-slate-800 p-4" data-customer-collection-history>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-200">{{ $collection->method->label() }} · {{ $collection->financialAccount->name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $collection->collected_at->format('d/m/Y H:i') }} · {{ $collection->receivedBy->name }}</p>
                            </div>
                            <p class="font-mono text-lg font-black text-emerald-200">{{ $collection->currency_code }} {{ number_format($collection->amount_minor / 100, 2, ',', '.') }}</p>
                        </div>
                        <div class="mt-3 text-xs text-slate-400">
                            @foreach($collection->allocations as $allocation)
                                <span class="mr-3">Venta #{{ $allocation->receivable->sale->sale_number }}: {{ number_format($allocation->amount_minor / 100, 2, ',', '.') }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Todavía no hay cobranzas confirmadas.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
