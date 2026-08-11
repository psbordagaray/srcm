<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Evidencia inmutable</p>
                    <span class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-200">{{ $sale->status->label() }}</span>
                </div>
                <h1 class="mt-2 text-2xl font-bold text-white">Venta #{{ $sale->sale_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">{{ $sale->sold_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · registrada por {{ $sale->recordedBy->name }}</p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('commerce-sales.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver a ventas</a>
                @can('record-commerce-sales')
                    <a href="{{ route('commerce-sales.create') }}" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Nueva venta</a>
                @endcan
            </div>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif

        <div class="grid gap-4 md:grid-cols-4">
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Servicios</p>
                <p class="mt-3 font-mono text-xl font-bold text-cyan-200">$ {{ number_format($sale->service_subtotal_minor / 100, 2, ',', '.') }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Productos</p>
                <p class="mt-3 font-mono text-xl font-bold text-violet-200">$ {{ number_format($sale->product_subtotal_minor / 100, 2, ',', '.') }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Pagos</p>
                <p class="mt-3 text-xl font-bold text-emerald-200">{{ $sale->payments->count() }}</p>
            </article>
            <article class="rounded-2xl border border-amber-400/30 bg-amber-400/10 p-5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-300">Total cobrado</p>
                <p class="mt-3 font-mono text-2xl font-bold text-white">$ {{ number_format($sale->total_minor / 100, 2, ',', '.') }}</p>
            </article>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,0.7fr)]">
            <div class="space-y-6">
                <section class="sulu-card p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-white">Conceptos vendidos</h2>
                            <p class="mt-1 text-sm text-slate-500">Servicio derivado y productos vinculados con inventario.</p>
                        </div>
                        <span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-400">{{ $sale->lines->count() }} línea{{ $sale->lines->count() === 1 ? '' : 's' }}</span>
                    </div>

                    <div class="mt-5 space-y-3">
                        @foreach($sale->lines as $line)
                            <article class="rounded-xl border {{ $line->line_type === \App\Enums\CommerceSaleLineType::Service ? 'border-cyan-500/20 bg-cyan-500/5' : 'border-violet-500/20 bg-violet-500/5' }} p-4">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider {{ $line->line_type === \App\Enums\CommerceSaleLineType::Service ? 'text-cyan-300' : 'text-violet-300' }}">{{ $line->line_type->label() }}</p>
                                        <h3 class="mt-2 text-sm font-bold text-white">{{ $line->description }}</h3>
                                        @if($line->product)
                                            <p class="mt-1 text-xs text-slate-500">{{ $line->product->sku }} · {{ $line->product->base_unit_code }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <p class="font-mono text-sm font-bold text-white">$ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ rtrim(rtrim($line->quantity, '0'), '.') }} × $ {{ number_format($line->unit_price_minor / 100, 2, ',', '.') }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Pagos exactos</h2>
                    <p class="mt-1 text-sm text-slate-500">La suma confirmada coincide con el total de la operación.</p>

                    <div class="mt-5 space-y-3">
                        @foreach($sale->payments as $payment)
                            <article class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-bold text-emerald-100">{{ $payment->method->label() }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $payment->paid_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · recibió {{ $payment->receivedBy->name }}</p>
                                    </div>
                                    <p class="font-mono text-lg font-bold text-white">$ {{ number_format($payment->amount_minor / 100, 2, ',', '.') }}</p>
                                </div>

                                @if(
                                    $payment->financialAccount
                                    || $payment->reference
                                    || $payment->card_brand
                                    || $payment->card_network
                                    || $payment->card_last4
                                    || $payment->installments
                                    || $payment->processor
                                    || $payment->external_operation_id
                                    || $payment->authorization_code
                                    || $payment->provider_status
                                    || $payment->notes
                                )
                                    <div class="mt-3 space-y-2 border-t border-emerald-500/10 pt-3 text-xs text-slate-400">
                                        @if($payment->financialAccount)
                                            <p>
                                                <strong>Cuenta destino:</strong>
                                                {{ $payment->financialAccount->name }}
                                                · {{ $payment->financialAccount->currency_code }}
                                                · {{ $payment->financialAccount->type->label() }}
                                            </p>
                                        @endif
                                        @if($payment->reference)
                                            <p><strong>Referencia:</strong> {{ $payment->reference }}</p>
                                        @endif

                                        @if(
                                            $payment->card_brand
                                            || $payment->card_network
                                            || $payment->card_last4
                                            || $payment->installments
                                        )
                                            <p>
                                                <strong>Tarjeta:</strong>
                                                {{ $payment->card_brand ?: 'Marca no informada' }}
                                                @if($payment->card_network)
                                                    · red {{ $payment->card_network }}
                                                @endif
                                                @if($payment->card_last4)
                                                    · •••• {{ $payment->card_last4 }}
                                                @endif
                                                @if($payment->installments)
                                                    · {{ $payment->installments }} cuota{{ $payment->installments === 1 ? '' : 's' }}
                                                @endif
                                            </p>
                                        @endif

                                        @if($payment->processor)
                                            <p><strong>Procesador / proveedor:</strong> {{ $payment->processor }}</p>
                                        @endif
                                        @if($payment->external_operation_id)
                                            <p><strong>Operación externa:</strong> {{ $payment->external_operation_id }}</p>
                                        @endif
                                        @if($payment->authorization_code)
                                            <p><strong>Autorización:</strong> {{ $payment->authorization_code }}</p>
                                        @endif
                                        @if($payment->provider_status)
                                            <p><strong>Estado informado:</strong> {{ $payment->provider_status }}</p>
                                        @endif
                                        @if($payment->notes)
                                            <p><strong>Notas:</strong> {{ $payment->notes }}</p>
                                        @endif

                                        @if(
                                            $payment->processor
                                            || $payment->external_operation_id
                                            || $payment->provider_status
                                        )
                                            <p class="pt-1 text-[11px] text-slate-500">
                                                Snapshot declarado al cobrar; no equivale a acreditación ni conciliación.
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            </div>

            <div class="space-y-6">
                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Cliente</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Nombre registrado</dt>
                            <dd class="mt-1 text-slate-200">{{ $sale->customer_name_snapshot }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Documento</dt>
                            <dd class="mt-1 text-slate-200">{{ $sale->customer_document_snapshot ?: 'No informado' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Moneda</dt>
                            <dd class="mt-1 font-mono text-slate-200">{{ $sale->currency_code }}</dd>
                        </div>
                    </dl>
                </section>

                @if($sale->serviceOrder)
                    <section class="sulu-card p-6">
                        <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Reparación liquidada</p>
                        <h2 class="mt-2 text-lg font-bold text-white">Orden #{{ $sale->serviceOrder->order_number }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ $sale->serviceOrder->asset->brand_name }} {{ $sale->serviceOrder->asset->model_name }}</p>
                        <a href="{{ route('service-orders.show', $sale->serviceOrder) }}" class="mt-4 inline-flex rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200 transition hover:border-cyan-300">Abrir expediente</a>
                    </section>
                @endif

                <section class="sulu-card p-6">
                    <h2 class="text-lg font-bold text-white">Salida de inventario</h2>
                    @if($sale->inventoryMovement)
                        <p class="mt-2 text-sm text-slate-400">Movimiento confirmado para {{ $sale->inventoryMovement->lines->count() }} producto{{ $sale->inventoryMovement->lines->count() === 1 ? '' : 's' }}.</p>
                        <a href="{{ route('inventory-movements.index', ['search' => $sale->public_id]) }}" class="mt-4 inline-flex rounded-xl border border-violet-400/30 px-4 py-2 text-sm font-semibold text-violet-200 transition hover:border-violet-300">Ver movimiento</a>
                    @else
                        <p class="mt-2 text-sm text-slate-500">La operación no incluyó productos físicos.</p>
                    @endif
                </section>

                @if($sale->notes)
                    <section class="sulu-card p-6">
                        <h2 class="text-lg font-bold text-white">Notas internas</h2>
                        <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $sale->notes }}</p>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
