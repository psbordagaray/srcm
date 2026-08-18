<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Compras · Operación monetaria</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Pagos a proveedores</h1>
                <p class="mt-2 max-w-4xl text-sm text-slate-400">Una autorización no paga. Un desembolso confirmado es una sola salida económica con imputaciones exactas por obligación.</p>
            </div>
            <a href="{{ route('purchase-orders.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300">Volver a Compras</a>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Elegibles para agrupar</p><p class="mt-3 text-3xl font-bold text-cyan-200">{{ $summary['eligible_obligations'] }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Grupos activos</p><p class="mt-3 text-3xl font-bold text-amber-300">{{ $summary['active_groups'] }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Desembolsos canónicos visibles</p><p class="mt-3 text-3xl font-bold text-emerald-300">{{ $summary['canonical_disbursements'] }}</p></article>
        </div>

        @can('request-purchase-payments')
            <section class="space-y-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Nueva autorización agrupada</h2>
                    <p class="mt-1 text-sm text-slate-500">Sólo se agrupan obligaciones del mismo proveedor, beneficiario y moneda. Seleccioná al menos dos.</p>
                </div>

                @forelse($eligibleBuckets as $bucketIndex => $bucket)
                    <form method="POST" action="{{ route('purchase-payment-groups.store') }}" class="sulu-card p-5">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="purchase-ui:payment-group-request:{{ \Illuminate\Support\Str::uuid() }}">

                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-white">{{ $bucket['supplier']->party->name }}</p>
                                <p class="mt-1 text-xs text-slate-400">Beneficiario: {{ $bucket['beneficiary']->name }} · {{ $bucket['currency_code'] }}</p>
                            </div>
                            <span class="rounded-full border border-cyan-400/25 px-3 py-1 text-xs font-semibold text-cyan-200">{{ $bucket['obligations']->count() }} obligaciones compatibles</span>
                        </div>

                        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-800">
                            <table class="min-w-full divide-y divide-slate-800">
                                <thead class="bg-slate-950/70"><tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500"><th class="px-4 py-3">Incluir</th><th class="px-4 py-3">Orden / obligación</th><th class="px-4 py-3">Saldo</th><th class="px-4 py-3">Importe a imputar</th></tr></thead>
                                <tbody class="divide-y divide-slate-800/80">
                                    @foreach($bucket['obligations'] as $itemIndex => $item)
                                        @php
                                            $obligation = $item['model'];
                                        @endphp
                                        <tr>
                                            <td class="px-4 py-3"><input type="checkbox" name="items[{{ $itemIndex }}][selected]" value="1" class="rounded border-slate-600 bg-slate-950"></td>
                                            <td class="px-4 py-3">
                                                <input type="hidden" name="items[{{ $itemIndex }}][purchase_obligation_id]" value="{{ $obligation->public_id }}">
                                                <a href="{{ route('purchase-orders.show', $obligation->order) }}" class="font-mono text-xs font-bold text-cyan-200">{{ strtoupper(substr($obligation->order->public_id, 0, 8)) }}</a>
                                                <p class="mt-1 text-xs text-slate-400">{{ $obligation->kind->label() }} · {{ strtoupper(substr($obligation->public_id, 0, 8)) }}</p>
                                            </td>
                                            <td class="px-4 py-3 font-mono text-sm text-amber-200">{{ $obligation->currency_code }} {{ number_format($item['remaining_minor'] / 100, 2, ',', '.') }}</td>
                                            <td class="px-4 py-3"><input type="number" name="items[{{ $itemIndex }}][amount]" min="0.01" step="0.01" max="{{ number_format($item['remaining_minor'] / 100, 2, '.', '') }}" value="{{ number_format($item['remaining_minor'] / 100, 2, '.', '') }}" class="w-44 rounded-lg border-slate-700 bg-slate-950 font-mono text-sm text-slate-100"></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-400">Cuenta de origen</label>
                                <select name="origin_financial_account_id" required class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-slate-100">
                                    @foreach($bucket['origins'] as $origin)
                                        <option value="{{ $origin->id }}">{{ $origin->name }} · {{ $origin->type->label() }} · {{ $origin->currency_code }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Caja exige turno propio al ejecutar. Non-cash exigirá referencia externa.</p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-400">Contexto de la solicitud</label>
                                <input name="request_note" maxlength="1000" placeholder="Nota opcional; no reemplaza evidencia" class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-slate-100">
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-slate-500">Solicitar reserva el plan. No crea desembolso, Caja ni evidencia bancaria.</p>
                            <button class="rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-bold text-slate-950">Solicitar autorización agrupada</button>
                        </div>
                    </form>
                @empty
                    <div class="sulu-card px-5 py-8 text-sm text-slate-500">No hay actualmente dos obligaciones compatibles y libres para agrupar.</div>
                @endforelse
            </section>
        @endcan

        <section class="space-y-4">
            <div><h2 class="text-lg font-bold text-white">Autorizaciones agrupadas</h2><p class="mt-1 text-sm text-slate-500">Solicitud, aprobación y ejecución permanecen separadas y auditables.</p></div>

            @forelse($groups as $group)
                @php
                    $groupTotal = (int) $group->items->sum('amount_minor');
                    $isCash = $group->originFinancialAccount->type === \App\Enums\FinancialAccountType::CashBox;
                @endphp
                <article id="payment-group-{{ $group->public_id }}" class="sulu-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider {{ in_array($group->status, [\App\Enums\PurchasePaymentRequestStatus::Approved, \App\Enums\PurchasePaymentRequestStatus::Executed], true) ? 'text-emerald-300' : ($group->status === \App\Enums\PurchasePaymentRequestStatus::Pending ? 'text-amber-300' : 'text-slate-300') }}">{{ mb_strtoupper($group->status->label(), 'UTF-8') }}</p>
                            <p class="mt-2 text-sm font-bold text-white">{{ $group->supplier->party->name }} → {{ $group->beneficiary->name }}</p>
                            <p class="mt-1 text-xs text-slate-400">Solicitó {{ $group->requestedBy->name }} · {{ $group->requested_at->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p>
                            <p class="mt-1 text-xs text-slate-400">Origen: {{ $group->originFinancialAccount->name }} · {{ $group->originFinancialAccount->type->label() }}</p>
                            @if($group->request_note)<p class="mt-1 text-xs text-slate-400">Nota: {{ $group->request_note }}</p>@endif
                            @if($group->approvedBy)<p class="mt-1 text-xs text-emerald-300">Autorizó {{ $group->approvedBy->name }} · {{ $group->approved_at?->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p>@endif
                            @if($group->resolution_note)<p class="mt-1 text-xs text-slate-400">Resolución: {{ $group->resolution_note }}</p>@endif
                        </div>
                        <p class="font-mono text-lg font-bold text-cyan-200">{{ $group->currency_code }} {{ number_format($groupTotal / 100, 2, ',', '.') }}</p>
                    </div>

                    <div class="mt-4 grid gap-2 md:grid-cols-2">
                        @foreach($group->items as $item)
                            <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-3">
                                <a href="{{ route('purchase-orders.show', $item->obligation->order) }}" class="font-mono text-xs font-bold text-cyan-200">Orden {{ strtoupper(substr($item->obligation->order->public_id, 0, 8)) }}</a>
                                <p class="mt-1 text-xs text-slate-400">{{ $item->obligation->kind->label() }} · {{ $group->currency_code }} {{ number_format($item->amount_minor / 100, 2, ',', '.') }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if($group->status === \App\Enums\PurchasePaymentRequestStatus::Pending)
                        @can('approve-purchase-payments')
                            @if((int) $group->requested_by_user_id !== (int) auth()->id())
                                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                    <form method="POST" action="{{ route('purchase-payment-groups.approve', $group) }}" class="rounded-xl border border-emerald-400/20 p-3">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="purchase-ui:payment-group-approve:{{ \Illuminate\Support\Str::uuid() }}">
                                        <input name="approval_note" maxlength="1000" placeholder="Nota de autorización opcional" class="w-full rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                        <button class="mt-2 rounded-lg bg-emerald-400 px-3 py-2 text-xs font-bold text-slate-950">Autorizar grupo · no ejecutar</button>
                                    </form>
                                    <form method="POST" action="{{ route('purchase-payment-groups.reject', $group) }}" class="rounded-xl border border-red-400/20 p-3">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="purchase-ui:payment-group-resolution:{{ \Illuminate\Support\Str::uuid() }}">
                                        <input name="resolution_note" required maxlength="1000" placeholder="Motivo de rechazo obligatorio" class="w-full rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                        <button class="mt-2 rounded-lg border border-red-400/40 px-3 py-2 text-xs font-bold text-red-200">Rechazar grupo</button>
                                    </form>
                                </div>
                            @endif
                        @endcan
                    @endif

                    @if($group->status === \App\Enums\PurchasePaymentRequestStatus::Approved && (int) $group->approved_by_user_id !== (int) auth()->id())
                        @can('execute-purchase-payments')
                            <form method="POST" action="{{ route('purchase-payment-groups.execute', $group) }}" class="mt-4 rounded-xl border border-amber-400/25 bg-amber-400/5 p-4" onsubmit="return window.confirm('Confirmar desembolso agrupado de {{ $group->currency_code }} {{ number_format($groupTotal / 100, 2, ',', '.') }}. La operación es irreversible.');">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="purchase-ui:payment-execute:{{ \Illuminate\Support\Str::uuid() }}">
                                <p class="text-xs font-bold uppercase tracking-wider text-amber-200">P9.7j · Desembolso agrupado irreversible</p>
                                <p class="mt-1 text-[11px] text-slate-400">{{ $isCash ? 'Creará exactamente un CashMovement por el total y requiere turno propio.' : 'No crea CashMovement ni evidencia bancaria ficticia; la referencia es obligatoria.' }}</p>
                                <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                    <input name="execution_reference" maxlength="180" {{ $isCash ? '' : 'required' }} placeholder="{{ $isCash ? 'Referencia / recibo opcional' : 'Referencia bancaria / externa obligatoria' }}" class="rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                    <input name="execution_note" maxlength="1000" placeholder="Nota de ejecución opcional" class="rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                </div>
                                <label class="mt-3 flex items-start gap-2 text-xs text-amber-100"><input type="checkbox" name="confirm_execute" value="1" required class="mt-0.5 rounded border-slate-600 bg-slate-950"><span>Confirmo una única salida económica por el total y las imputaciones indicadas.</span></label>
                                <button class="mt-3 rounded-lg bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950">Ejecutar desembolso agrupado</button>
                            </form>
                        @endcan
                    @endif

                    @if($group->status->isActive() && ((int) $group->requested_by_user_id === (int) auth()->id() || auth()->user()?->can('approve-purchase-payments')))
                        <form method="POST" action="{{ route('purchase-payment-groups.cancel', $group) }}" class="mt-3 flex flex-wrap gap-2">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="purchase-ui:payment-group-resolution:{{ \Illuminate\Support\Str::uuid() }}">
                            <input name="resolution_note" required maxlength="1000" placeholder="Motivo para cancelar el grupo" class="min-w-64 flex-1 rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                            <button class="rounded-lg border border-slate-600 px-3 py-2 text-xs font-semibold text-slate-200">Cancelar grupo</button>
                        </form>
                    @endif
                </article>
            @empty
                <div class="sulu-card px-5 py-8 text-sm text-slate-500">Todavía no existen autorizaciones agrupadas.</div>
            @endforelse
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4"><h2 class="font-bold text-white">Desembolsos canónicos recientes</h2><p class="mt-1 text-xs text-slate-500">Historia append-only individual y agrupada, cash y non-cash.</p></div>
            <div class="divide-y divide-slate-800">
                @forelse($disbursements as $disbursement)
                    @php
                        $paymentControl = $controls->get($disbursement->id);
                    @endphp
                    <article class="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">{{ $disbursement->channel->label() }} · {{ $disbursement->purchase_payment_group_request_id ? 'AGRUPADO' : 'INDIVIDUAL' }}</p>
                                <p class="mt-2 text-sm font-bold text-white">{{ $disbursement->beneficiary->name }} · {{ $disbursement->originFinancialAccount->name }}</p>
                                <p class="mt-1 text-xs text-slate-400">Ejecutó {{ $disbursement->executedBy->name }} · {{ $disbursement->executed_at->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }} · {{ $disbursement->allocations->count() }} imputación{{ $disbursement->allocations->count() === 1 ? '' : 'es' }}</p>
                                @if($disbursement->execution_reference)<p class="mt-1 text-xs text-slate-400">Referencia: {{ $disbursement->execution_reference }}</p>@endif
                            </div>
                            <p class="font-mono text-lg font-bold text-emerald-200">{{ $disbursement->currency_code }} {{ number_format($disbursement->amount_minor / 100, 2, ',', '.') }}</p>
                        </div>
                        @if($paymentControl)
                            <div class="mt-3 rounded-xl border border-cyan-400/15 bg-cyan-400/5 p-3">
                                <p class="text-xs font-semibold {{ $paymentControl['severity'] === 'danger' ? 'text-red-300' : ($paymentControl['severity'] === 'warning' ? 'text-amber-300' : 'text-emerald-300') }}">{{ $paymentControl['title'] }}</p>
                                <p class="mt-1 text-[11px] text-slate-400">{{ $paymentControl['detail'] }}</p>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="px-5 py-8 text-sm text-slate-500">Todavía no existen desembolsos canónicos.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
