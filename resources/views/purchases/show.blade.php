<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Compras · Expediente</p><h1 class="mt-2 text-2xl font-bold text-white">Orden {{ strtoupper(substr($order->public_id, 0, 8)) }}</h1><p class="mt-2 break-all font-mono text-xs text-slate-500">{{ $order->public_id }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($order->status === \App\Enums\PurchaseOrderStatus::Draft)
                    @can('draft-purchase-orders')<a href="{{ route('purchase-orders.edit', $order) }}" class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200">Editar borrador</a>@endcan
                    @can('issue-purchase-orders')<form method="POST" action="{{ route('purchase-orders.issue', $order) }}">@csrf<button class="rounded-xl bg-amber-400 px-4 py-2 text-sm font-bold text-slate-950">Emitir orden</button></form>@endcan
                @endif
                @if($order->status->acceptsReceipts())
                    @can('receive-purchases')<a href="{{ route('purchase-orders.receipts.create', $order) }}" class="rounded-xl bg-emerald-400 px-4 py-2 text-sm font-bold text-slate-950">Registrar recepción</a>@endcan
                @endif
            </div>
        </div>

        @if(session('success'))<div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>@endif
        @if(session('error'))<div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>@endif
        @if($errors->any())<div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Proveedor</p><p class="mt-3 text-lg font-bold text-white">{{ $order->supplier->party->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->supplier->party->tax_id ?: 'Sin identificación fiscal' }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Estado</p><p class="mt-3 text-lg font-bold text-amber-200">{{ $order->status->label() }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?: 'Aún no emitida' }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Mercadería</p><p class="mt-3 font-mono text-xl font-bold text-white">{{ $order->currency_code }} {{ number_format($order->merchandise_subtotal_minor / 100, 2, ',', '.') }}</p><p class="mt-1 text-xs text-slate-500">Logística esperada: {{ number_format($order->expected_logistics_cost_minor / 100, 2, ',', '.') }}</p></article>
            <article class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total esperado</p><p class="mt-3 font-mono text-xl font-bold text-amber-200">{{ $order->currency_code }} {{ number_format($order->expected_total_minor / 100, 2, ',', '.') }}</p><p class="mt-1 text-xs text-slate-500">{{ $order->receipts->count() }} recepción{{ $order->receipts->count() === 1 ? '' : 'es' }}</p></article>
        </div>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4"><h2 class="font-bold text-white">Líneas y remanentes</h2><p class="mt-1 text-xs text-slate-500">Las cantidades recibidas provienen exclusivamente de recepciones confirmadas.</p></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-800"><thead class="bg-slate-950/70"><tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500"><th class="px-5 py-4">Producto</th><th class="px-5 py-4">Pedido</th><th class="px-5 py-4">Recibido</th><th class="px-5 py-4">Pendiente</th><th class="px-5 py-4 text-right">Costo / subtotal</th></tr></thead><tbody class="divide-y divide-slate-800/80">
                @foreach($order->lines as $line)
                    <tr><td class="px-5 py-4"><p class="text-sm font-semibold text-white">{{ $line->product->sku }} · {{ $line->description }}</p><p class="mt-1 text-xs text-slate-500">{{ $line->supplier_code ?: 'Sin código proveedor' }}{{ $line->supplierOffer ? ' · oferta #'.$line->supplierOffer->id : '' }}</p></td><td class="px-5 py-4 font-mono text-sm text-slate-200">{{ \App\Domain\Inventory\InventoryQuantity::display($line->ordered_quantity, $line->quantity_scale) }} {{ $line->base_unit_code }}</td><td class="px-5 py-4 font-mono text-sm text-emerald-200">{{ \App\Domain\Inventory\InventoryQuantity::display($lineBalances[$line->id]['received'], $line->quantity_scale) }}</td><td class="px-5 py-4 font-mono text-sm text-amber-200">{{ \App\Domain\Inventory\InventoryQuantity::display($lineBalances[$line->id]['remaining'], $line->quantity_scale) }}</td><td class="px-5 py-4 text-right"><p class="font-mono text-sm text-slate-200">{{ $order->currency_code }} {{ number_format($line->unit_cost_minor / 100, 2, ',', '.') }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ number_format($line->subtotal_minor / 100, 2, ',', '.') }}</p></td></tr>
                @endforeach
            </tbody></table></div>
        </section>

        <section class="space-y-4">
            <div><h2 class="text-lg font-bold text-white">Recepciones confirmadas</h2><p class="mt-1 text-sm text-slate-500">Cada recepción conserva documento, costo real, ubicación, condición y evidencia de Inventario.</p></div>
            @forelse($order->receipts as $receipt)
                <article class="sulu-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="font-mono text-sm font-bold text-emerald-200">{{ strtoupper(substr($receipt->public_id, 0, 8)) }}</p><p class="mt-1 text-sm text-white">{{ $receipt->document_reference ?: 'Sin referencia documental' }}</p><p class="mt-1 text-xs text-slate-500">{{ $receipt->received_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $receipt->receivedBy->name }}</p></div><div class="text-right"><p class="font-mono text-sm font-bold text-white">{{ $order->currency_code }} {{ number_format($receipt->actual_total_minor / 100, 2, ',', '.') }}</p><p class="mt-1 text-xs text-slate-500">Movimiento Inventario</p><a href="{{ route('inventory-movements.index', ['search' => $receipt->inventoryMovement->public_id]) }}" class="mt-1 block break-all font-mono text-[11px] text-cyan-300 hover:text-cyan-200">{{ $receipt->inventoryMovement->public_id }}</a></div></div>
                    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-800"><table class="min-w-full divide-y divide-slate-800"><tbody class="divide-y divide-slate-800/80">@foreach($receipt->lines as $line)<tr><td class="px-4 py-3 text-sm text-slate-200">{{ $line->product->sku }} · {{ $line->product->name }}</td><td class="px-4 py-3 font-mono text-sm text-emerald-200">{{ \App\Domain\Inventory\InventoryQuantity::display($line->received_quantity, $line->product->quantity_scale) }}</td><td class="px-4 py-3 text-sm text-slate-300">{{ $line->condition->label() }}</td><td class="px-4 py-3 text-sm text-slate-300">{{ $line->inventoryLocation->name }}</td><td class="px-4 py-3 text-right font-mono text-sm text-slate-200">{{ $order->currency_code }} {{ number_format($line->actual_unit_cost_minor / 100, 2, ',', '.') }}</td></tr>@endforeach</tbody></table></div>

                    <div id="obligations-{{ $receipt->public_id }}" class="mt-5 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Obligaciones económicas</p>
                                <p class="mt-1 text-sm text-slate-300">Reconocer una obligación registra deuda. <strong class="text-white">No ejecuta ningún pago</strong>, no toca Caja y no altera Inventario.</p>
                            </div>
                            <span class="rounded-full border border-amber-400/20 px-3 py-1 text-xs font-semibold text-amber-200">P4F.1</span>
                        </div>

                        @if($receipt->obligations->isNotEmpty())
                            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                @foreach($receipt->obligations as $obligation)
                                    <article class="rounded-xl border border-slate-700 bg-slate-950/50 p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">REGISTRADA · SIN PAGO</p>
                                                <p class="mt-2 text-sm font-bold text-white">{{ $obligation->kind->label() }}</p>
                                                <p class="mt-1 text-xs text-slate-400">Beneficiario: {{ $obligation->beneficiary->name }}</p>
                                            </div>
                                            <p class="font-mono text-sm font-bold text-amber-200">{{ $obligation->currency_code }} {{ number_format($obligation->amount_minor / 100, 2, ',', '.') }}</p>
                                        </div>
                                        <div class="mt-3 border-t border-slate-800 pt-3 text-xs text-slate-400">
                                            <p>{{ $obligation->payment_condition->label() }}{{ $obligation->due_on ? ' · '.$obligation->due_on->format('d/m/Y') : '' }}</p>
                                            @if($obligation->condition_note)<p class="mt-1">{{ $obligation->condition_note }}</p>@endif
                                            <p class="mt-1">Reconocida por {{ $obligation->recognizedBy->name }} · {{ $obligation->recognized_at->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p>
                                        </div>

                                        @php
                                            $activePaymentRequest = $obligation->paymentRequests->first(
                                                fn ($candidate) => $candidate->status->isActive()
                                            );
                                            $executedPaymentMinor = (int) $obligation->paymentRequests->sum(
                                                fn ($candidate) => (int) ($candidate->execution?->amount_minor ?? 0)
                                            );
                                            $remainingPaymentMinor = max(
                                                0,
                                                (int) $obligation->amount_minor - $executedPaymentMinor
                                            );
                                            $compatibleOrigins = $paymentOrigins->where(
                                                'currency_code',
                                                $obligation->currency_code
                                            );
                                        @endphp

                                        <div class="mt-4 border-t border-cyan-400/15 pt-4">
                                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-300">P4F.2 · Solicitud y autorización</p>
                                            <p class="mt-1 text-[11px] text-slate-500"><strong class="text-slate-300">Autorizar no mueve dinero.</strong> P4F.3 será el único que podrá ejecutar un desembolso.</p>

                                            @foreach($obligation->paymentRequests as $paymentRequest)
                                                <div id="payment-request-{{ $paymentRequest->public_id }}" class="mt-3 rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                                        <div>
                                                            <p class="text-xs font-bold uppercase tracking-wider {{ in_array($paymentRequest->status, [\App\Enums\PurchasePaymentRequestStatus::Approved, \App\Enums\PurchasePaymentRequestStatus::Executed], true) ? 'text-emerald-300' : ($paymentRequest->status === \App\Enums\PurchasePaymentRequestStatus::Pending ? 'text-amber-300' : 'text-slate-300') }}">{{ mb_strtoupper($paymentRequest->status->label(), 'UTF-8') }}{{ $paymentRequest->status === \App\Enums\PurchasePaymentRequestStatus::Executed ? '' : ' · SIN PAGO' }}</p>
                                                            <p class="mt-2 text-xs text-slate-400">Solicitó {{ $paymentRequest->requestedBy->name }} · {{ $paymentRequest->requested_at->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p>
                                                            <p class="mt-1 text-xs text-slate-400">Origen propuesto: {{ $paymentRequest->originFinancialAccount->name }} · {{ $paymentRequest->originFinancialAccount->type->label() }}</p>
                                                            @if($paymentRequest->request_note)<p class="mt-1 text-xs text-slate-400">Nota: {{ $paymentRequest->request_note }}</p>@endif
                                                            @if($paymentRequest->approvedBy)<p class="mt-1 text-xs text-emerald-300">Autorizó {{ $paymentRequest->approvedBy->name }} · {{ $paymentRequest->approved_at?->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p>@endif
                                                            @if($paymentRequest->approval_note)<p class="mt-1 text-xs text-slate-400">Nota de autorización: {{ $paymentRequest->approval_note }}</p>@endif
                                                            @if($paymentRequest->resolvedBy)<p class="mt-1 text-xs text-slate-400">Resolvió {{ $paymentRequest->resolvedBy->name }} · {{ $paymentRequest->resolved_at?->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}</p>@endif
                                                            @if($paymentRequest->resolution_note)<p class="mt-1 text-xs text-slate-400">Motivo: {{ $paymentRequest->resolution_note }}</p>@endif
                                                        </div>
                                                        <p class="font-mono text-sm font-bold text-cyan-200">{{ $paymentRequest->currency_code }} {{ number_format($paymentRequest->amount_minor / 100, 2, ',', '.') }}</p>
                                                    </div>


                                                    @if($paymentRequest->execution)
                                                        <div class="mt-3 rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-3">
                                                            <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">Pago en efectivo ejecutado</p>
                                                            <p class="mt-2 text-xs text-slate-300">
                                                                Ejecutó {{ $paymentRequest->execution->executedBy->name }}
                                                                · {{ $paymentRequest->execution->executed_at->timezone(config('app.display_timezone', 'America/Argentina/Buenos_Aires'))->format('d/m/Y H:i') }}
                                                            </p>
                                                            @if($paymentRequest->execution->execution_reference)
                                                                <p class="mt-1 text-xs text-slate-400">Referencia: {{ $paymentRequest->execution->execution_reference }}</p>
                                                            @endif
                                                            @if($paymentRequest->execution->execution_note)
                                                                <p class="mt-1 text-xs text-slate-400">Nota: {{ $paymentRequest->execution->execution_note }}</p>
                                                            @endif
                                                            @if($paymentRequest->execution->cashMovement)
                                                                <p class="mt-1 text-[11px] text-slate-500">
                                                                    CashMovement {{ $paymentRequest->execution->cashMovement->public_id }}
                                                                    · egreso {{ $paymentRequest->execution->cashMovement->currency_code }}
                                                                    {{ number_format($paymentRequest->execution->cashMovement->amount_minor / 100, 2, ',', '.') }}
                                                                </p>
                                                            @endif
                                                        </div>
                                                    @endif

                                                    @if(
                                                        $paymentRequest->status === \App\Enums\PurchasePaymentRequestStatus::Approved
                                                        && $paymentRequest->originFinancialAccount->type === \App\Enums\FinancialAccountType::CashBox
                                                        && (int) $paymentRequest->approved_by_user_id !== (int) auth()->id()
                                                    )
                                                        @can('execute-purchase-payments')
                                                            <form
                                                                method="POST"
                                                                action="{{ route('purchase-payment-requests.execute', $paymentRequest) }}"
                                                                class="mt-3 rounded-xl border border-amber-400/25 bg-amber-400/5 p-3"
                                                                onsubmit="return window.confirm('Confirmar egreso real de {{ $paymentRequest->currency_code }} {{ number_format($paymentRequest->amount_minor / 100, 2, ',', '.') }} desde {{ $paymentRequest->originFinancialAccount->name }}. Esta ejecución afectará Caja.');"
                                                            >
                                                                @csrf
                                                                <input type="hidden" name="idempotency_key" value="purchase-ui:payment-execute:{{ \Illuminate\Support\Str::uuid() }}">
                                                                <p class="text-xs font-bold uppercase tracking-wider text-amber-200">P4F.3 · Ejecución irreversible</p>
                                                                <p class="mt-1 text-[11px] text-slate-400">
                                                                    Esta acción registra un egreso real de
                                                                    <strong class="text-slate-200">{{ $paymentRequest->currency_code }} {{ number_format($paymentRequest->amount_minor / 100, 2, ',', '.') }}</strong>
                                                                    desde {{ $paymentRequest->originFinancialAccount->name }}.
                                                                    Requiere un turno abierto propio sobre esa caja.
                                                                </p>
                                                                <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                                                    <input name="execution_reference" maxlength="180" placeholder="Referencia / recibo opcional" class="rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                                                    <input name="execution_note" maxlength="1000" placeholder="Nota de ejecución opcional" class="rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                                                </div>
                                                                <label class="mt-3 flex items-start gap-2 text-xs text-amber-100">
                                                                    <input type="checkbox" name="confirm_execute" value="1" required class="mt-0.5 rounded border-slate-600 bg-slate-950">
                                                                    <span>Confirmo que el efectivo será entregado al beneficiario y que SRCM debe registrar ahora el egreso real.</span>
                                                                </label>
                                                                <button type="submit" class="mt-3 rounded-lg bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950">Ejecutar pago en efectivo</button>
                                                            </form>
                                                        @endcan
                                                    @endif

                                                    @if($paymentRequest->status === \App\Enums\PurchasePaymentRequestStatus::Pending)
                                                        @can('approve-purchase-payments')
                                                            @if((int) $paymentRequest->requested_by_user_id !== (int) auth()->id())
                                                                <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                                                    <form method="POST" action="{{ route('purchase-payment-requests.approve', $paymentRequest) }}" class="rounded-xl border border-emerald-400/20 p-3">
                                                                        @csrf
                                                                        <input type="hidden" name="idempotency_key" value="purchase-ui:payment-approve:{{ \Illuminate\Support\Str::uuid() }}">
                                                                        <input name="approval_note" maxlength="1000" placeholder="Nota de autorización opcional" class="w-full rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                                                        <button class="mt-2 rounded-lg bg-emerald-400 px-3 py-2 text-xs font-bold text-slate-950">Autorizar · no ejecutar</button>
                                                                    </form>
                                                                    <form method="POST" action="{{ route('purchase-payment-requests.reject', $paymentRequest) }}" class="rounded-xl border border-red-400/20 p-3">
                                                                        @csrf
                                                                        <input type="hidden" name="idempotency_key" value="purchase-ui:payment-resolution:{{ \Illuminate\Support\Str::uuid() }}">
                                                                        <input name="resolution_note" required maxlength="1000" placeholder="Motivo de rechazo obligatorio" class="w-full rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                                                        <button class="mt-2 rounded-lg border border-red-400/40 px-3 py-2 text-xs font-bold text-red-200">Rechazar</button>
                                                                    </form>
                                                                </div>
                                                            @endif
                                                        @endcan
                                                    @endif

                                                    @if($paymentRequest->status->isActive() && ((int) $paymentRequest->requested_by_user_id === (int) auth()->id() || auth()->user()?->can('approve-purchase-payments')))
                                                        <form method="POST" action="{{ route('purchase-payment-requests.cancel', $paymentRequest) }}" class="mt-3 flex flex-wrap gap-2">
                                                            @csrf
                                                            <input type="hidden" name="idempotency_key" value="purchase-ui:payment-resolution:{{ \Illuminate\Support\Str::uuid() }}">
                                                            <input name="resolution_note" required maxlength="1000" placeholder="Motivo para cancelar" class="min-w-64 flex-1 rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                                            <button class="rounded-lg border border-slate-600 px-3 py-2 text-xs font-semibold text-slate-200">Cancelar solicitud</button>
                                                        </form>
                                                    @endif

                                                    @if($paymentRequest->status->isActive())
                                                        @can('approve-purchase-payments')
                                                            @if((int) $paymentRequest->requested_by_user_id !== (int) auth()->id())
                                                                <form method="POST" action="{{ route('purchase-payment-requests.expire', $paymentRequest) }}" class="mt-2 flex flex-wrap gap-2">
                                                                    @csrf
                                                                    <input type="hidden" name="idempotency_key" value="purchase-ui:payment-resolution:{{ \Illuminate\Support\Str::uuid() }}">
                                                                    <input name="resolution_note" required maxlength="1000" placeholder="Motivo de vencimiento" class="min-w-64 flex-1 rounded-lg border-slate-700 bg-slate-950 text-xs text-slate-100">
                                                                    <button class="rounded-lg border border-amber-400/30 px-3 py-2 text-xs font-semibold text-amber-200">Marcar vencida</button>
                                                                </form>
                                                            @endif
                                                        @endcan
                                                    @endif
                                                </div>
                                            @endforeach

                                            @can('request-purchase-payments')
                                                @if(! $activePaymentRequest && $remainingPaymentMinor > 0)
                                                    @if($compatibleOrigins->isNotEmpty())
                                                        <form method="POST" action="{{ route('purchase-payment-requests.store', ['purchaseOrder' => $order->public_id, 'purchaseObligation' => $obligation->public_id]) }}" class="mt-3 grid gap-3 rounded-xl border border-cyan-400/15 bg-cyan-400/5 p-3 lg:grid-cols-2">
                                                            @csrf
                                                            <input type="hidden" name="idempotency_key" value="purchase-ui:payment-request:{{ \Illuminate\Support\Str::uuid() }}">
                                                            <div>
                                                                <label class="text-[11px] font-semibold text-slate-400">Importe a solicitar</label>
                                                                <input type="number" name="amount" min="0.01" step="0.01" max="{{ number_format($remainingPaymentMinor / 100, 2, '.', '') }}" value="{{ number_format($remainingPaymentMinor / 100, 2, '.', '') }}" required class="mt-1 w-full rounded-lg border-slate-700 bg-slate-950 font-mono text-sm text-slate-100">
                                                                <p class="mt-1 text-[11px] text-slate-500">Puede ser parcial. Nunca puede superar la obligación.</p>
                                                            </div>
                                                            <div>
                                                                <label class="text-[11px] font-semibold text-slate-400">Origen propuesto</label>
                                                                <select name="origin_financial_account_id" required class="mt-1 w-full rounded-lg border-slate-700 bg-slate-950 text-sm text-slate-100">
                                                                    @foreach($compatibleOrigins as $origin)
                                                                        <option value="{{ $origin->id }}">{{ $origin->name }} · {{ $origin->type->label() }} · {{ $origin->currency_code }}</option>
                                                                    @endforeach
                                                                </select>
                                                                <p class="mt-1 text-[11px] text-slate-500">Es parte del fingerprint. Cambiar origen exige una nueva autorización.</p>
                                                            </div>
                                                            <div class="lg:col-span-2">
                                                                <input name="request_note" maxlength="1000" placeholder="Contexto / nota opcional. No reemplaza evidencia de pago." class="w-full rounded-lg border-slate-700 bg-slate-950 text-sm text-slate-100">
                                                            </div>
                                                            <div class="lg:col-span-2 flex flex-wrap items-center justify-between gap-3">
                                                                <p class="text-[11px] text-slate-500">Solicitar no paga. El aprobador debe ser una persona distinta.</p>
                                                                <button class="rounded-lg bg-cyan-300 px-4 py-2.5 text-sm font-bold text-slate-950">Solicitar autorización de pago</button>
                                                            </div>
                                                        </form>
                                                    @else
                                                        <p class="mt-3 text-xs text-amber-200">No hay cuentas financieras activas compatibles con {{ $obligation->currency_code }} para proponer como origen.</p>
                                                    @endif
                                                @elseif($remainingPaymentMinor <= 0)
                                                    <p class="mt-3 text-xs font-semibold text-emerald-300">La obligación quedó cubierta por ejecuciones confirmadas. El hecho original permanece inmutable.</p>
                                                @else
                                                    <p class="mt-3 text-[11px] text-slate-500">Hay una solicitud pendiente o autorizada. Debe resolverse antes de crear otra.</p>
                                                @endif
                                            @endcan
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif

                        @can('create-purchase-obligations')
                            @php
                                $hasMerchandise = $receipt->obligations->contains(fn ($obligation) => $obligation->kind === \App\Enums\PurchaseObligationKind::Merchandise);
                                $hasLogistics = $receipt->obligations->contains(fn ($obligation) => $obligation->kind === \App\Enums\PurchaseObligationKind::Logistics);
                                $canMerchandise = $receipt->merchandise_total_minor > 0 && ! $hasMerchandise;
                                $canLogistics = $receipt->logistics_cost_minor > 0 && ! $hasLogistics;
                            @endphp

                            @if($canMerchandise || $canLogistics)
                                <form method="POST" action="{{ route('purchase-orders.obligations.store', ['purchaseOrder' => $order->public_id, 'purchaseReceipt' => $receipt->public_id]) }}" class="mt-4 grid gap-3 rounded-xl border border-slate-700 bg-slate-950/40 p-4 lg:grid-cols-2">
                                    @csrf
                                    <div>
                                        <label class="text-xs font-semibold text-slate-400">Concepto de obligación</label>
                                        <select name="kind" required class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
                                            @if($canMerchandise)<option value="merchandise">Mercadería · {{ $order->currency_code }} {{ number_format($receipt->merchandise_total_minor / 100, 2, ',', '.') }}</option>@endif
                                            @if($canLogistics)<option value="logistics">Logística / flete · {{ $order->currency_code }} {{ number_format($receipt->logistics_cost_minor / 100, 2, ',', '.') }}</option>@endif
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">El importe y la moneda derivan de la recepción confirmada. No son editables.</p>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-slate-400">Beneficiario</label>
                                        <select name="beneficiary_business_party_id" class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
                                            @foreach($obligationBeneficiaries as $party)
                                                <option value="{{ $party->id }}" @selected((int) $party->id === (int) $order->supplier->business_party_id)>
                                                    {{ $party->name }}{{ $party->tax_id ? ' · '.$party->tax_id : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">Puede ser el proveedor u otra parte comercial autorizada, por ejemplo el transportista.</p>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-slate-400">Condición</label>
                                        <select name="payment_condition" required class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
                                            @foreach(\App\Enums\PurchaseObligationCondition::cases() as $condition)
                                                <option value="{{ $condition->value }}">{{ $condition->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-slate-400">Vencimiento</label>
                                        <input type="date" name="due_on" class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
                                        <p class="mt-1 text-[11px] text-slate-500">Sólo corresponde si elegís “Vencimiento en fecha”.</p>
                                    </div>

                                    <div class="lg:col-span-2">
                                        <label class="text-xs font-semibold text-slate-400">Detalle de condición</label>
                                        <input name="condition_note" maxlength="1000" placeholder="Obligatorio sólo para Otra condición." class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
                                    </div>

                                    <div class="lg:col-span-2 flex flex-wrap items-center justify-between gap-3">
                                        <p class="text-xs text-slate-500">Recepción → obligación. Autorización y ejecución pertenecen a P4F.2/P4F.3.</p>
                                        <button class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950">Registrar obligación económica</button>
                                    </div>
                                </form>
                            @else
                                <p class="mt-4 text-xs text-slate-500">Todos los componentes positivos de esta recepción ya tienen obligación reconocida.</p>
                            @endif
                        @endcan
                    </div>
                </article>
            @empty
                <div class="sulu-card px-5 py-10 text-center text-sm text-slate-500">Todavía no hay mercadería recibida para esta orden.</div>
            @endforelse
        </section>

        @if($order->status === \App\Enums\PurchaseOrderStatus::Issued)
            @can('cancel-purchase-orders')
                <section class="sulu-card border-red-500/20 p-5"><h2 class="font-bold text-red-100">Cancelar orden no recibida</h2><p class="mt-1 text-sm text-slate-500">La cancelación exige motivo y deja trazabilidad. Después de una recepción ya no está permitida.</p><form method="POST" action="{{ route('purchase-orders.cancel', $order) }}" class="mt-4 flex flex-col gap-3 md:flex-row">@csrf<input name="reason" required maxlength="1000" value="{{ old('reason') }}" placeholder="Motivo de cancelación" class="flex-1 rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-red-400 focus:ring-red-400"><button class="rounded-xl border border-red-400/30 px-4 py-2.5 text-sm font-semibold text-red-200">Cancelar orden</button></form></section>
            @endcan
        @endif

        @if($order->notes)<section class="sulu-card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Notas</p><p class="mt-3 whitespace-pre-line text-sm text-slate-300">{{ $order->notes }}</p></section>@endif
    </div>
</x-app-layout>
