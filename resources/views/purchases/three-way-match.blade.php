<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Compras · 3-way match</p>
                <h1 class="mt-2 text-2xl font-bold text-white">Orden {{ strtoupper(substr($order->public_id, 0, 8)) }}</h1>
                <p class="mt-2 text-sm text-slate-400">Orden ↔ recepción confirmada ↔ documento del proveedor. Vista derivada y sin efectos contables.</p>
            </div>
            <a href="{{ route('purchase-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200">Volver al expediente</a>
        </div>

        <section class="sulu-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Estado derivado</p>
                    <p class="mt-2 text-xl font-bold {{ $match['exact'] ? 'text-emerald-300' : 'text-amber-200' }}">{{ $match['status_label'] }}</p>
                    <p class="mt-1 text-sm text-slate-400">Este estado nunca crea obligación ni ejecuta pago.</p>
                </div>
                <div class="text-right font-mono text-sm text-slate-300">
                    <p>{{ $match['summary']['document_count'] }} documento(s)</p>
                    <p>{{ $match['summary']['receipt_count'] }} recepción(es)</p>
                </div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach([
                ['Orden', $match['summary']['order_total_minor']],
                ['Recibido', $match['summary']['receipt_total_minor']],
                ['Documentado', $match['summary']['document_total_minor']],
            ] as [$label, $minor])
                <article class="sulu-card p-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ $label }}</p>
                    <p class="mt-3 font-mono text-xl font-bold text-white">{{ $match['summary']['currency_code'] }} {{ number_format($minor / 100, 2, ',', '.') }}</p>
                </article>
            @endforeach
        </div>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-slate-800 px-5 py-4">
                <h2 class="font-bold text-white">Comparación por línea</h2>
                <p class="mt-1 text-xs text-slate-500">Las diferencias se muestran; SRCM no las corrige ni las compensa automáticamente.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/70">
                        <tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="px-4 py-4">Producto</th>
                            <th class="px-4 py-4">Orden</th>
                            <th class="px-4 py-4">Recepción</th>
                            <th class="px-4 py-4">Documento</th>
                            <th class="px-4 py-4">Resultado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @foreach($match['lines'] as $line)
                            <tr>
                                <td class="px-4 py-4">
                                    <p class="text-sm font-semibold text-white">{{ $line['sku'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $line['description'] }}</p>
                                </td>
                                <td class="px-4 py-4 font-mono text-xs text-slate-300">
                                    <p>{{ \App\Domain\Inventory\InventoryQuantity::display($line['ordered_quantity'], $line['quantity_scale']) }} {{ $line['base_unit_code'] }}</p>
                                    <p class="mt-1">{{ number_format($line['order_subtotal_minor'] / 100, 2, ',', '.') }}</p>
                                </td>
                                <td class="px-4 py-4 font-mono text-xs text-slate-300">
                                    <p>{{ \App\Domain\Inventory\InventoryQuantity::display($line['received_quantity'], $line['quantity_scale']) }} {{ $line['base_unit_code'] }}</p>
                                    <p class="mt-1">{{ number_format($line['received_subtotal_minor'] / 100, 2, ',', '.') }}</p>
                                </td>
                                <td class="px-4 py-4 font-mono text-xs text-slate-300">
                                    <p>{{ \App\Domain\Inventory\InventoryQuantity::display($line['documented_quantity'], $line['quantity_scale']) }} {{ $line['base_unit_code'] }}</p>
                                    <p class="mt-1">{{ number_format($line['documented_subtotal_minor'] / 100, 2, ',', '.') }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="rounded-full border px-3 py-1 text-xs font-bold {{ $line['exact'] ? 'border-emerald-400/30 text-emerald-300' : 'border-amber-400/30 text-amber-200' }}">{{ $line['exact'] ? 'Exacta' : 'Diferencia' }}</span>
                                    @unless($line['exact'])
                                        <div class="mt-3 space-y-1 font-mono text-[11px] text-slate-500">
                                            <p>Doc−Orden qty: {{ $line['quantity_document_order_delta'] }}</p>
                                            <p>Doc−Recepción qty: {{ $line['quantity_document_receipt_delta'] }}</p>
                                            <p>Doc−Orden $: {{ $line['money_document_order_delta_minor'] }}</p>
                                            <p>Doc−Recepción $: {{ $line['money_document_receipt_delta_minor'] }}</p>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if($match['unmatched_document_lines'] !== [])
            <section class="sulu-card p-5">
                <h2 class="font-bold text-amber-200">Líneas documentales sin vínculo a la orden</h2>
                <p class="mt-1 text-sm text-slate-400">Son evidencia explícita del documento y no se inventa un producto de catálogo.</p>
                <div class="mt-4 space-y-3">
                    @foreach($match['unmatched_document_lines'] as $line)
                        <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4 text-sm text-slate-300">
                            <p class="font-semibold text-white">{{ $line['description'] }}</p>
                            <p class="mt-1 font-mono text-xs">Cantidad {{ $line['quantity'] }} · unitario {{ number_format($line['unit_cost_minor'] / 100, 2, ',', '.') }} · subtotal {{ number_format($line['subtotal_minor'] / 100, 2, ',', '.') }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="sulu-card p-5">
            <h2 class="font-bold text-white">Documentos considerados</h2>
            @forelse($match['documents'] as $document)
                <div class="mt-3 rounded-xl border border-slate-800 p-4 text-sm text-slate-300">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <p class="font-semibold text-white">{{ $document['document_number'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">Emitido {{ $document['issued_on'] }}{{ $document['due_on'] ? ' · vence '.$document['due_on'] : '' }}</p>
                        </div>
                        <p class="font-mono font-bold">{{ $document['currency_code'] }} {{ number_format($document['total_minor'] / 100, 2, ',', '.') }}</p>
                    </div>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">Todavía no hay documento económico del proveedor.</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
