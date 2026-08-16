<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Expediente de posventa</p>
                    <span class="rounded-full bg-violet-500/10 px-3 py-1 text-xs font-bold text-violet-200">
                        {{ $case->intent->label() }}
                    </span>
                </div>

                <h1 class="mt-2 text-2xl font-bold text-white">
                    Venta #{{ $case->sale->sale_number }}
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    Solicitud {{ \Illuminate\Support\Str::limit($case->public_id, 18) }}
                    · {{ $case->requested_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                    · {{ $case->requestedBy->name }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                @can('resolve-commerce-post-sale')
                    @if($case->receipts->isNotEmpty())
                        <a href="{{ route('commerce-post-sale.resolutions.create', $case) }}" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">
                            Registrar resolución económica
                        </a>
                    @endif
                @endcan
                @can('record-commerce-post-sale')
                    <a href="{{ route('commerce-post-sale.receipts.create', $case) }}" class="rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                        Registrar recepción física
                    </a>
                @endcan
                <a href="{{ route('commerce-post-sale.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">
                    Volver a posventa
                </a>
                <a href="{{ route('commerce-sales.show', $case->sale) }}" class="rounded-xl border border-cyan-400/30 px-4 py-2.5 text-sm font-semibold text-cyan-200 transition hover:border-cyan-300">
                    Ver venta original
                </a>
            </div>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-bold">La acción de posventa no pudo completarse.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-4">
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Líneas solicitadas</p>
                <p class="mt-3 text-2xl font-bold text-white">{{ $case->lines->count() }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Recepciones confirmadas</p>
                <p class="mt-3 text-2xl font-bold text-cyan-200">{{ $case->receipts->count() }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Resoluciones económicas</p>
                <p class="mt-3 text-2xl font-bold text-violet-200">{{ $case->resolutions->count() }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cliente</p>
                <p class="mt-3 truncate text-sm font-bold text-white">{{ $case->sale->customer_name_snapshot }}</p>
            </article>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,0.75fr)]">
            <div class="space-y-6">
                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Solicitud original</h2>
                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $case->reason }}</p>

                    @if($case->notes)
                        <div class="mt-4 rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Notas internas</p>
                            <p class="mt-2 whitespace-pre-line text-sm text-slate-400">{{ $case->notes }}</p>
                        </div>
                    @endif

                    <div class="mt-5 space-y-3">
                        @foreach($case->lines as $line)
                            <article class="rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-bold text-white">{{ $line->saleLine->description }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $line->saleLine->product?->sku }}</p>
                                    </div>
                                    <p class="font-mono text-sm font-bold text-violet-100">
                                        {{ rtrim(rtrim($line->quantity, '0'), '.') }}
                                        {{ $line->saleLine->product?->base_unit_code }}
                                    </p>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-white">Recepciones físicas</h2>
                            <p class="mt-1 text-sm text-slate-500">Evidencia de mercadería efectivamente recibida. No implica por sí sola devolución económica.</p>
                        </div>
                        <span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-400">{{ $case->receipts->count() }}</span>
                    </div>

                    <div class="mt-5 space-y-3">
                        @forelse($case->receipts as $receipt)
                            <article class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-cyan-100">
                                            Recepción {{ \Illuminate\Support\Str::limit($receipt->public_id, 18) }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $receipt->received_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                            · {{ $receipt->receivedBy->name }}
                                        </p>
                                    </div>
                                    <span class="rounded-lg border border-cyan-500/20 bg-slate-950/40 px-3 py-1.5 text-xs font-semibold text-cyan-200">
                                        CustomerReturn #{{ $receipt->inventoryMovement->id }}
                                    </span>
                                </div>

                                <div class="mt-4 space-y-2">
                                    @foreach($receipt->lines as $receiptLine)
                                        <div class="rounded-lg border border-white/10 bg-slate-950/40 p-3">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                <div>
                                                    <p class="text-sm font-semibold text-white">
                                                        {{ $receiptLine->requestLine->saleLine->description }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-slate-500">
                                                        {{ $receiptLine->condition->label() }}
                                                        · {{ $receiptLine->destinationLocation->name }}
                                                    </p>
                                                </div>
                                                <p class="font-mono text-sm font-bold text-cyan-100">
                                                    {{ rtrim(rtrim($receiptLine->quantity, '0'), '.') }}
                                                    {{ $receiptLine->requestLine->saleLine->product?->base_unit_code }}
                                                </p>
                                            </div>

                                            @if($receiptLine->notes)
                                                <p class="mt-2 text-xs text-slate-400">{{ $receiptLine->notes }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if($receipt->notes)
                                    <p class="mt-3 border-t border-white/10 pt-3 text-xs text-slate-400">
                                        {{ $receipt->notes }}
                                    </p>
                                @endif
                            </article>
                        @empty
                            <p class="rounded-xl border border-slate-800 bg-slate-950/40 p-4 text-sm text-slate-500">
                                Todavía no existe una recepción física confirmada.
                            </p>
                        @endforelse
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-white">Resoluciones económicas</h2>
                            <p class="mt-1 text-sm text-slate-500">Valor reconocido y outcome comercial, separados de la ejecución.</p>
                        </div>
                        <span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-400">{{ $case->resolutions->count() }}</span>
                    </div>

                    <div class="mt-5 space-y-4">
                        @forelse($case->resolutions as $resolution)
                            @php
                                $materialization = match ($resolution->outcome) {
                                    \App\Enums\CommercePostSaleResolutionOutcome::CustomerCredit =>
                                        $resolution->customerCreditGrant
                                            ? 'Saldo a favor materializado'
                                            : 'Saldo a favor pendiente de materialización',
                                    \App\Enums\CommercePostSaleResolutionOutcome::Refund =>
                                        $resolution->cashRefundExecution
                                            ? 'Reembolso de caja ejecutado'
                                            : (
                                                $resolution->externalRefundInstruction
                                                    ? 'Reembolso externo instruido'
                                                    : 'Reembolso pendiente de ejecución'
                                            ),
                                    \App\Enums\CommercePostSaleResolutionOutcome::Exchange =>
                                        $resolution->exchangeSelection?->execution
                                            ? 'Cambio ejecutado'
                                            : (
                                                $resolution->exchangeSelection
                                                    ? 'Reemplazo seleccionado'
                                                    : 'Selección de reemplazo pendiente'
                                            ),
                                };
                            @endphp

                            <article class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-amber-100">{{ $resolution->outcome->label() }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $resolution->resolved_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                            · {{ $resolution->resolvedBy->name }}
                                        </p>
                                        <p class="mt-2 text-xs font-semibold text-slate-300">{{ $materialization }}</p>
                                    </div>
                                    <p class="font-mono text-lg font-bold text-white">
                                        $ {{ number_format($resolution->recognizedAmountMinor() / 100, 2, ',', '.') }}
                                    </p>
                                </div>

                                @if(
                                    !$resolution->customerCreditGrant
                                    && !$resolution->cashRefundExecution
                                    && !$resolution->externalRefundInstruction
                                    && !$resolution->exchangeSelection
                                )
                                    <div class="mt-4 rounded-xl border border-white/10 bg-slate-950/40 p-4">
                                        @if($resolution->outcome === \App\Enums\CommercePostSaleResolutionOutcome::CustomerCredit)
                                            @can('materialize-commerce-post-sale-customer-credit')
                                                <form method="POST" action="{{ route('commerce-post-sale.customer-credits.store', $resolution) }}">
                                                    @csrf
                                                    <input type="hidden" name="idempotency_key" value="ui:commerce-post-sale-credit:{{ \Illuminate\Support\Str::uuid() }}">
                                                    <p class="text-xs text-slate-400">
                                                        Materializa el saldo reconocido a nombre del cliente. No mueve caja.
                                                    </p>
                                                    <button type="submit" class="mt-3 rounded-xl bg-emerald-300 px-4 py-2.5 text-sm font-bold text-slate-950">
                                                        Materializar saldo a favor
                                                    </button>
                                                </form>
                                            @endcan
                                        @elseif($resolution->outcome === \App\Enums\CommercePostSaleResolutionOutcome::Refund)
                                            @if(!$resolution->preferredOriginalPayment)
                                                <p class="text-xs font-semibold text-amber-200">
                                                    Esta resolución histórica no fijó un pago original y no tiene camino operativo de reembolso. P8.5.4 impide crear nuevos casos así.
                                                </p>
                                            @elseif($resolution->preferredOriginalPayment->method === \App\Enums\CommercePaymentMethod::Cash)
                                                @can('execute-commerce-post-sale-cash-refund')
                                                    @if((int) $resolution->resolved_by_user_id !== (int) request()->user()->id)
                                                        <a href="{{ route('commerce-post-sale.cash-refunds.create', $resolution) }}" class="inline-flex rounded-xl bg-rose-300 px-4 py-2.5 text-sm font-bold text-slate-950">
                                                            Ejecutar reembolso en efectivo
                                                        </a>
                                                    @else
                                                        <p class="text-xs font-semibold text-amber-200">
                                                            El resolutor económico no puede ejecutar su propio reembolso. Debe hacerlo otro operador con la caja correspondiente abierta.
                                                        </p>
                                                    @endif
                                                @endcan
                                            @else
                                                @can('execute-commerce-post-sale-external-refund')
                                                    @if((int) $resolution->resolved_by_user_id !== (int) request()->user()->id)
                                                        <form method="POST" action="{{ route('commerce-post-sale.external-refunds.store', $resolution) }}">
                                                            @csrf
                                                            <input type="hidden" name="idempotency_key" value="ui:commerce-post-sale-external-refund:{{ \Illuminate\Support\Str::uuid() }}">
                                                            <p class="text-xs text-slate-400">
                                                                Crea sólo la instrucción local. El gate del proveedor puede bloquearla y este paso no envía HTTP externo.
                                                            </p>
                                                            <button type="submit" class="mt-3 rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-bold text-slate-950">
                                                                Crear instrucción de reembolso externo
                                                            </button>
                                                        </form>
                                                    @else
                                                        <p class="text-xs font-semibold text-amber-200">
                                                            El resolutor económico no puede instruir su propio reembolso externo.
                                                        </p>
                                                    @endif
                                                @endcan
                                            @endif
                                        @elseif($resolution->outcome === \App\Enums\CommercePostSaleResolutionOutcome::Exchange)
                                            @can('select-commerce-post-sale-exchange')
                                                <a href="{{ route('commerce-post-sale.exchange-selections.create', $resolution) }}" class="inline-flex rounded-xl bg-violet-300 px-4 py-2.5 text-sm font-bold text-slate-950">
                                                    Seleccionar reemplazo
                                                </a>
                                            @endcan
                                        @endif
                                    </div>
                                @endif

                                <div class="mt-4 space-y-2">
                                    @foreach($resolution->lines as $resolutionLine)
                                        <div class="rounded-lg border border-white/10 bg-slate-950/40 p-3">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                <div>
                                                    <p class="text-sm font-semibold text-white">
                                                        {{ $resolutionLine->receiptLine->requestLine->saleLine->description }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-slate-500">
                                                        {{ rtrim(rtrim($resolutionLine->quantity, '0'), '.') }}
                                                        {{ $resolutionLine->receiptLine->requestLine->saleLine->product?->base_unit_code }}
                                                        · base $ {{ number_format($resolutionLine->baseline_amount_minor / 100, 2, ',', '.') }}
                                                    </p>
                                                </div>
                                                <p class="font-mono text-sm font-bold text-amber-100">
                                                    Reconocido $ {{ number_format($resolutionLine->recognized_amount_minor / 100, 2, ',', '.') }}
                                                </p>
                                            </div>

                                            @if($resolutionLine->adjustment_reason)
                                                <p class="mt-2 text-xs text-slate-400">
                                                    Ajuste: {{ $resolutionLine->adjustment_reason }}
                                                </p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if($resolution->exchangeSelection)
                                    <div class="mt-4 rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-wider text-violet-300">Reemplazo fijado</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    Diferencia:
                                                    <span class="font-mono font-bold text-violet-100">
                                                        $ {{ number_format($resolution->exchangeSelection->differenceAmountMinor() / 100, 2, ',', '.') }}
                                                    </span>
                                                </p>
                                            </div>
                                            <p class="font-mono text-sm font-bold text-white">
                                                Total $ {{ number_format($resolution->exchangeSelection->replacementAmountMinor() / 100, 2, ',', '.') }}
                                            </p>
                                        </div>

                                        <div class="mt-3 space-y-2">
                                            @foreach($resolution->exchangeSelection->lines as $selectionLine)
                                                <div class="flex flex-col gap-1 rounded-lg border border-white/10 bg-slate-950/40 p-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <span class="text-sm text-slate-200">{{ $selectionLine->product->name }}</span>
                                                    <span class="font-mono text-xs text-violet-100">
                                                        {{ rtrim(rtrim($selectionLine->quantity, '0'), '.') }}
                                                        × $ {{ number_format($selectionLine->unit_price_minor / 100, 2, ',', '.') }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>

                                        @if(!$resolution->exchangeSelection->execution)
                                            @can('execute-commerce-post-sale-exchange')
                                                <div class="mt-4 border-t border-violet-500/20 pt-4">
                                                    @if(
                                                        (int) $resolution->resolved_by_user_id !== (int) request()->user()->id
                                                        && (int) $resolution->exchangeSelection->selected_by_user_id !== (int) request()->user()->id
                                                    )
                                                        <a href="{{ route('commerce-post-sale.exchange-executions.create', $resolution->exchangeSelection) }}" class="inline-flex rounded-xl bg-violet-300 px-4 py-2.5 text-sm font-bold text-slate-950">
                                                            Ejecutar entrega y diferencia
                                                        </a>
                                                    @else
                                                        <p class="text-xs font-semibold text-amber-200">
                                                            El resolutor o selector económico no puede ejecutar físicamente este cambio.
                                                        </p>
                                                    @endif
                                                </div>
                                            @endcan
                                        @else
                                            <div class="mt-4 border-t border-violet-500/20 pt-4">
                                                <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">Cambio ejecutado</p>
                                                <p class="mt-2 text-xs text-slate-400">
                                                    {{ $resolution->exchangeSelection->execution->executed_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                                    · {{ $resolution->exchangeSelection->execution->executedBy->name }}
                                                    · InventoryMovement #{{ $resolution->exchangeSelection->execution->inventory_movement_id }}
                                                </p>

                                                @if($resolution->exchangeSelection->execution->payments->isNotEmpty())
                                                    <div class="mt-3 space-y-2">
                                                        @foreach($resolution->exchangeSelection->execution->payments as $payment)
                                                            <div class="flex flex-col gap-1 rounded-lg border border-white/10 bg-slate-950/40 p-3 sm:flex-row sm:items-center sm:justify-between">
                                                                <span class="text-xs text-slate-300">
                                                                    {{ $payment->method->label() }}
                                                                    · {{ $payment->financialAccount->name }}
                                                                </span>
                                                                <span class="font-mono text-xs font-bold text-emerald-100">
                                                                    $ {{ number_format($payment->amount_minor / 100, 2, ',', '.') }}
                                                                </span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                @if($resolution->exchangeSelection->execution->creditGrant)
                                                    <p class="mt-3 text-xs text-emerald-200">
                                                        Crédito específico por diferencia:
                                                        $ {{ number_format($resolution->exchangeSelection->execution->creditGrant->amount_minor / 100, 2, ',', '.') }}
                                                        · {{ $resolution->exchangeSelection->execution->creditGrant->party->name }}
                                                    </p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                @if($resolution->preferredOriginalPayment)
                                    <p class="mt-3 text-xs text-slate-400">
                                        Medio original preferido:
                                        <span class="font-semibold text-slate-200">
                                            {{ $resolution->preferredOriginalPayment->method->label() }}
                                            · $ {{ number_format($resolution->preferredOriginalPayment->amount_minor / 100, 2, ',', '.') }}
                                        </span>
                                    </p>
                                @endif

                                @if($resolution->externalRefundInstruction)
                                    @php
                                        $instruction = $resolution->externalRefundInstruction;
                                        $dispatch = $instruction->dispatch;
                                        $evidence = $dispatch?->evidence ?? collect();
                                    @endphp

                                    <div class="mt-4 rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">Reembolso externo instruido</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ \Illuminate\Support\Str::limit($instruction->public_id, 14) }}
                                                    · {{ $instruction->requestedBy->name }}
                                                    · {{ $instruction->providerConnection?->provider_key }}
                                                </p>
                                            </div>
                                            <p class="font-mono text-sm font-bold text-white">
                                                $ {{ number_format($instruction->amount_minor / 100, 2, ',', '.') }}
                                            </p>
                                        </div>

                                        @if($dispatch)
                                            <p class="mt-3 text-xs text-slate-400">
                                                Dispatch {{ \Illuminate\Support\Str::limit($dispatch->public_id, 14) }}
                                                · clave provider-neutral preservada
                                            </p>
                                        @endif

                                        @if($evidence->isNotEmpty())
                                            <div class="mt-3 space-y-2">
                                                @foreach($evidence as $item)
                                                    <div class="rounded-lg border border-white/10 bg-slate-950/40 p-3">
                                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                            <span class="text-xs text-slate-300">
                                                                {{ $item->source->value }}
                                                                · {{ $item->financialMovement->status->value }}
                                                            </span>
                                                            <span class="font-mono text-xs font-bold text-cyan-100">
                                                                $ {{ number_format($item->financialMovement->gross_amount_minor / 100, 2, ',', '.') }}
                                                            </span>
                                                        </div>
                                                        <p class="mt-1 text-[11px] text-slate-500">
                                                            {{ $item->observed_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                                            · {{ $item->financialMovement->external_operation_id }}
                                                        </p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            @can('dispatch-commerce-post-sale-external-refund')
                                                <form method="POST" action="{{ route('commerce-post-sale.external-refunds.submit', $instruction) }}" class="mt-4 border-t border-cyan-500/20 pt-4">
                                                    @csrf
                                                    <label class="flex items-start gap-3">
                                                        <input type="checkbox" name="confirm_submission" value="1" required class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-cyan-400">
                                                        <span class="text-xs leading-5 text-slate-300">
                                                            Confirmo el envío real al proveedor. El gate financiero se vuelve a validar antes del dispatch.
                                                        </span>
                                                    </label>
                                                    <button type="submit" class="mt-3 rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-bold text-slate-950">
                                                        {{ $dispatch ? 'Reintentar mismo dispatch' : 'Enviar reembolso al proveedor' }}
                                                    </button>
                                                </form>
                                            @endcan
                                        @endif
                                    </div>
                                @endif

                                <p class="mt-3 border-t border-white/10 pt-3 text-xs text-slate-400">
                                    {{ $resolution->reason }}
                                </p>
                            </article>
                        @empty
                            <p class="rounded-xl border border-slate-800 bg-slate-950/40 p-4 text-sm text-slate-500">
                                Todavía no existe una resolución económica confirmada.
                            </p>
                        @endforelse
                    </div>
                </section>
            </div>

            <div class="space-y-6">
                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Venta original</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Número</dt>
                            <dd class="mt-1 text-slate-200">#{{ $case->sale->sale_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Cliente</dt>
                            <dd class="mt-1 text-slate-200">{{ $case->sale->customer_name_snapshot }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Documento</dt>
                            <dd class="mt-1 text-slate-200">{{ $case->sale->customer_document_snapshot ?: 'No informado' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Total</dt>
                            <dd class="mt-1 font-mono font-bold text-white">$ {{ number_format($case->sale->total_minor / 100, 2, ',', '.') }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-2xl border border-violet-500/20 bg-violet-500/5 p-6">
                    <p class="text-xs font-bold uppercase tracking-wider text-violet-300">Separación de responsabilidades</p>
                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        El expediente mantiene separados intake, recepción, resolución, materialización y ejecución. P8.5.5 habilita la ejecución física del cambio y el dispatch externo sólo detrás de sus Gates y confirmaciones explícitas; Mercado Pago sigue fallando cerrado mientras su refund gate permanezca degradado.
                    </p>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
