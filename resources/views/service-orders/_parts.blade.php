@php
    $quantitySum = function ($items, string $attribute): string {
        return $items->reduce(
            fn (string $total, $item): string =>
                \App\Domain\Inventory\InventoryQuantity::add(
                    $total,
                    (string) $item->{$attribute}
                ),
            \App\Domain\Inventory\InventoryQuantity::signed('0')
        );
    };

    $eligibleWorks = $order->workItems->filter(function ($work): bool {
        if (! in_array($work->status, [
            \App\Enums\ServiceWorkStatus::Planned,
            \App\Enums\ServiceWorkStatus::InProgress,
        ], true)) {
            return false;
        }

        if ($work->service_warranty_claim_resolution_id !== null) {
            return true;
        }

        return $work->approvedOption?->lines
            ?->contains(
                fn ($line): bool =>
                    $line->line_type
                        === \App\Enums\ServiceQuoteLineType::Part
                    && $line->partRequirement === null
            ) ?? false;
    });

    $directPurchaseOutstanding = $order->partRequirements
        ->filter(function ($requirement) use ($quantitySum): bool {
            if (
                $requirement->source
                    !== \App\Enums\ServicePartSource::DirectPurchase
            ) {
                return false;
            }

            $purchased = $quantitySum(
                $requirement->purchaseLines,
                'quantity'
            );

            return \App\Domain\Inventory\InventoryQuantity::isPositive(
                \App\Domain\Inventory\InventoryQuantity::nonNegative(
                    \App\Domain\Inventory\InventoryQuantity::subtract(
                        $requirement->required_quantity,
                        $purchased
                    )
                )
            );
        });
@endphp

<section class="sulu-card p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-orange-300">Abastecimiento técnico</p>
            <h2 class="mt-1 text-lg font-bold text-white">Repuestos de la reparación</h2>
            <p class="mt-1 text-sm text-slate-500">Necesidades aprobadas, compras afectadas y consumos trazables.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('record-service-part-purchases')
                @if($directPurchaseOutstanding->isNotEmpty())
                    <a href="{{ route('service-orders.part-purchases.create', $order) }}" class="rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Registrar compra</a>
                @endif
            @endcan
        </div>
    </div>

    @if($errors->has('parts'))
        <div role="alert" class="mt-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
            {{ $errors->first('parts') }}
        </div>
    @endif

    @can('plan-service-parts')
        @if(
            in_array($order->status, [
                \App\Enums\ServiceOrderStatus::InProgress,
                \App\Enums\ServiceOrderStatus::AwaitingParts,
            ], true)
            && $eligibleWorks->isNotEmpty()
        )
            <div class="mt-5 flex flex-wrap gap-2 rounded-xl border border-orange-500/20 bg-orange-500/5 p-4">
                @foreach($eligibleWorks as $work)
                    <a href="{{ route('service-orders.part-requirements.create', [$order, $work]) }}" class="rounded-lg border border-orange-400/30 bg-orange-400/10 px-3 py-2 text-xs font-semibold text-orange-200 transition hover:border-orange-300 hover:text-white">
                        Agregar repuesto al trabajo {{ $work->sequence }}
                    </a>
                @endforeach
            </div>
        @endif
    @endcan

    <div class="mt-5 space-y-4">
        @forelse($order->partRequirements as $requirement)
            @php
                $purchased = $quantitySum(
                    $requirement->purchaseLines,
                    'quantity'
                );
                $consumed = $quantitySum(
                    $requirement->consumptions,
                    'quantity'
                );
                $remaining = \App\Domain\Inventory\InventoryQuantity::nonNegative(
                    \App\Domain\Inventory\InventoryQuantity::subtract(
                        $requirement->required_quantity,
                        $consumed
                    )
                );
            @endphp

            <article class="rounded-2xl border border-slate-800 bg-slate-950/50 p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-bold text-white">{{ $requirement->product->name }}</p>
                            <span class="rounded-full border border-orange-500/20 bg-orange-500/10 px-2 py-1 text-[10px] font-bold uppercase text-orange-200">{{ $requirement->source->label() }}</span>
                            @if($requirement->service_warranty_claim_resolution_id)
                                <span class="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-1 text-[10px] font-bold uppercase text-emerald-200">Garantía</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Trabajo {{ $requirement->workItem->sequence }} · {{ $requirement->condition->label() }}
                            @if($requirement->product->sku)
                                · SKU {{ $requirement->product->sku }}
                            @endif
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="font-mono text-sm font-bold text-white">{{ $requirement->required_quantity }} {{ $requirement->base_unit_code }}</p>
                        <p class="mt-1 text-[10px] uppercase tracking-wider text-slate-500">Cantidad requerida</p>
                    </div>
                </div>

                @if($requirement->quoteLine)
                    <p class="mt-4 rounded-xl border border-slate-800 bg-slate-950 px-3 py-2 text-xs text-slate-300">
                        Presupuesto: {{ $requirement->quoteLine->description }}
                    </p>
                @elseif($requirement->warrantyResolution)
                    <p class="mt-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 text-xs text-emerald-100">
                        Alcance de garantía: {{ $requirement->warrantyResolution->covered_scope }}
                    </p>
                @endif

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Comprado</p>
                        <p class="mt-1 font-mono text-sm text-slate-200">{{ $purchased }} {{ $requirement->base_unit_code }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Consumido</p>
                        <p class="mt-1 font-mono text-sm text-slate-200">{{ $consumed }} {{ $requirement->base_unit_code }}</p>
                    </div>
                    <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-cyan-300">Pendiente de consumir</p>
                        <p class="mt-1 font-mono text-sm text-cyan-100">{{ $remaining }} {{ $requirement->base_unit_code }}</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[11px] text-slate-500">
                        Planificó {{ $requirement->createdBy->name }} ·
                        {{ $requirement->planned_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                    </p>

                    @can('consume-service-parts')
                        @if(
                            $order->status === \App\Enums\ServiceOrderStatus::InProgress
                            && $requirement->workItem->status === \App\Enums\ServiceWorkStatus::InProgress
                            && \App\Domain\Inventory\InventoryQuantity::isPositive($remaining)
                        )
                            <a href="{{ route('service-orders.part-requirements.consume.create', [$order, $requirement]) }}" class="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-cyan-300">Registrar consumo</a>
                        @endif
                    @endcan
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-700 bg-slate-950/30 p-6 text-center">
                <p class="text-sm font-semibold text-slate-300">Todavía no se planificaron repuestos.</p>
                <p class="mt-1 text-xs text-slate-500">En trabajos normales deben surgir de una línea aprobada; en garantía, del alcance correctivo autorizado.</p>
            </div>
        @endforelse
    </div>

    @if($order->partPurchases->isNotEmpty())
        <div class="mt-6 border-t border-slate-800 pt-5">
            <h3 class="text-sm font-bold text-white">Compras afectadas a la orden</h3>
            <div class="mt-3 space-y-3">
                @foreach($order->partPurchases as $purchase)
                    <article class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-amber-100">{{ $purchase->supplier->party->name }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $purchase->purchased_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                                    · registró {{ $purchase->purchasedBy->name }}
                                    @if($purchase->document_reference)
                                        · {{ $purchase->document_reference }}
                                    @endif
                                </p>
                            </div>
                            <p class="font-mono text-sm font-bold text-amber-200">
                                {{ $purchase->currency_code }}
                                {{ number_format($purchase->grand_total_minor / 100, 2, ',', '.') }}
                            </p>
                        </div>

                        <div class="mt-3 space-y-1 border-t border-amber-500/10 pt-3">
                            @foreach($purchase->lines as $line)
                                <div class="flex flex-wrap justify-between gap-3 text-xs">
                                    <span class="text-slate-300">
                                        {{ $line->requirement->product->name }}
                                        · {{ $line->quantity }} {{ $line->requirement->base_unit_code }}
                                    </span>
                                    <span class="text-slate-400">
                                        {{ $purchase->currency_code }}
                                        {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    @endif
</section>
