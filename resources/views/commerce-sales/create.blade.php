<x-app-layout>
    @php
        $selectedServiceId = old(
            'service_order_id',
            $selectedServiceOrder?->id
        );
        $selectedCustomerId = old(
            'customer_business_party_id',
            $selectedServiceOrder?->customer_business_party_id
        );
        $productRows = old('product_lines', []);

        if (! is_array($productRows) || $productRows === []) {
            $productRows = [[
                'catalog_product_id' => '',
                'source_location_id' => '',
                'condition' => \App\Enums\InventoryCondition::New->value,
                'quantity' => '1',
                'unit_price' => '',
            ]];
        }

        $paymentRows = old('payments', []);

        if (! is_array($paymentRows) || $paymentRows === []) {
            $paymentRows = [[
                'method' => \App\Enums\CommercePaymentMethod::Cash->value,
                'amount' => '',
                'reference' => '',
                'notes' => '',
                'paid_at' => '',
            ]];
        }
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Confirmación atómica</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Nueva venta y cobro</h1>
            <p class="mt-2 text-sm text-slate-400">La reparación se deriva del presupuesto aprobado. Los productos generan su salida de inventario y los pagos deben cancelar exactamente el total.</p>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('commerce-sales.store') }}"
            class="space-y-6"
            x-data="{
                productLines: {{ \Illuminate\Support\Js::from(array_values($productRows)) }},
                payments: {{ \Illuminate\Support\Js::from(array_values($paymentRows)) }},
                addProduct() {
                    this.productLines.push({
                        catalog_product_id: '',
                        source_location_id: '',
                        condition: 'new',
                        quantity: '1',
                        unit_price: ''
                    });
                },
                addPayment() {
                    this.payments.push({
                        method: 'cash',
                        amount: '',
                        reference: '',
                        notes: '',
                        paid_at: ''
                    });
                }
            }"
        >
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Reparación entregada</h2>
                        <p class="mt-1 text-sm text-slate-500">Opcional para venta minorista. El precio técnico no puede editarse.</p>
                    </div>
                    <span class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 text-xs font-semibold text-emerald-200">{{ $unsettledOrders->count() }} pendiente{{ $unsettledOrders->count() === 1 ? '' : 's' }}</span>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-[minmax(0,1.5fr)_minmax(12rem,0.5fr)]">
                    <div>
                        <label for="service_order_id" class="text-sm font-semibold text-slate-200">Orden a liquidar</label>
                        <select id="service_order_id" name="service_order_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                            <option value="">Venta sin reparación</option>
                            @foreach($unsettledOrders as $serviceOrder)
                                @php
                                    $quote = $serviceOrder->quotes
                                        ->sortByDesc('revision')
                                        ->first();
                                    $option = $quote?->decision?->selectedOption;
                                @endphp
                                <option value="{{ $serviceOrder->id }}" @selected((string) $selectedServiceId === (string) $serviceOrder->id)>
                                    #{{ $serviceOrder->order_number }} · {{ $serviceOrder->customer?->name ?? $serviceOrder->delivery?->recipient_name }} · {{ $serviceOrder->asset->brand_name }} {{ $serviceOrder->asset->model_name }} · $ {{ number_format(($option?->total_minor ?? 0) / 100, 2, ',', '.') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="currency_code" class="text-sm font-semibold text-slate-200">Moneda</label>
                        <input id="currency_code" name="currency_code" type="text" required maxlength="3" value="{{ old('currency_code', 'ARS') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono uppercase text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                </div>

                @if($selectedServiceOrder)
                    @php
                        $selectedQuote = $selectedServiceOrder->quotes
                            ->sortByDesc('revision')
                            ->first();
                        $selectedOption =
                            $selectedQuote?->decision?->selectedOption;
                    @endphp
                    <div class="mt-5 rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">Presupuesto aprobado</p>
                                <p class="mt-2 text-sm font-bold text-white">{{ $selectedOption?->label }}</p>
                            </div>
                            <p class="font-mono text-lg font-bold text-cyan-100">$ {{ number_format(($selectedOption?->total_minor ?? 0) / 100, 2, ',', '.') }}</p>
                        </div>
                        <div class="mt-3 space-y-2 border-t border-cyan-500/10 pt-3">
                            @foreach($selectedOption?->lines ?? [] as $line)
                                <div class="flex justify-between gap-4 text-xs">
                                    <span class="text-slate-400">{{ $line->description }}</span>
                                    <span class="font-mono text-slate-200">$ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="sulu-card p-6">
                <h2 class="text-lg font-bold text-white">Cliente y referencia</h2>
                <p class="mt-1 text-sm text-slate-500">Puede utilizar una ficha existente o conservar una identificación libre de mostrador.</p>

                <div class="mt-5">
                    <label for="customer_business_party_id" class="text-sm font-semibold text-slate-200">Cliente vinculado</label>
                    <select id="customer_business_party_id" name="customer_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                        <option value="">Consumidor final o identificación libre</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((string) $selectedCustomerId === (string) $customer->id)>
                                {{ $customer->name }}{{ $customer->tax_id ? ' · '.$customer->tax_id : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="customer_name" class="text-sm font-semibold text-slate-200">Nombre para la venta</label>
                        <input id="customer_name" name="customer_name" type="text" maxlength="255" value="{{ old('customer_name') }}" placeholder="Se deriva del cliente o receptor cuando queda vacío" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                    <div>
                        <label for="customer_document" class="text-sm font-semibold text-slate-200">Documento</label>
                        <input id="customer_document" name="customer_document" type="text" maxlength="255" value="{{ old('customer_document') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Productos agregados</h2>
                        <p class="mt-1 text-sm text-slate-500">Cada línea crea evidencia de salida desde una ubicación concreta.</p>
                    </div>
                    <button type="button" @click="addProduct()" class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200 transition hover:border-cyan-300">Agregar producto</button>
                </div>

                <div class="mt-5 space-y-4">
                    <template x-for="(line, index) in productLines" :key="'product-'+index">
                        <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Producto <span x-text="index + 1"></span></p>
                                <button type="button" @click="productLines.splice(index, 1)" class="text-xs font-semibold text-red-300 hover:text-red-200">Quitar</button>
                            </div>

                            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Artículo</label>
                                    <select :name="`product_lines[${index}][catalog_product_id]`" x-model="line.catalog_product_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                                        <option value="">Seleccionar</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }} · {{ $product->base_unit_code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Ubicación de salida</label>
                                    <select :name="`product_lines[${index}][source_location_id]`" x-model="line.source_location_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                                        <option value="">Seleccionar</option>
                                        @foreach($locations as $location)
                                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Condición</label>
                                    <select :name="`product_lines[${index}][condition]`" x-model="line.condition" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                                        @foreach($conditions as $condition)
                                            <option value="{{ $condition->value }}">{{ $condition->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Cantidad</label>
                                    <input :name="`product_lines[${index}][quantity]`" x-model="line.quantity" type="text" inputmode="decimal" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Precio unitario</label>
                                    <input :name="`product_lines[${index}][unit_price]`" x-model="line.unit_price" type="text" inputmode="decimal" placeholder="0,00" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                                </div>
                            </div>
                        </article>
                    </template>
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Pagos</h2>
                        <p class="mt-1 text-sm text-slate-500">La suma debe cancelar exactamente reparación y productos.</p>
                    </div>
                    <button type="button" @click="addPayment()" class="rounded-xl border border-emerald-400/30 px-4 py-2 text-sm font-semibold text-emerald-200 transition hover:border-emerald-300">Agregar pago</button>
                </div>

                <div class="mt-5 space-y-4">
                    <template x-for="(payment, index) in payments" :key="'payment-'+index">
                        <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Pago <span x-text="index + 1"></span></p>
                                <button type="button" @click="payments.splice(index, 1)" class="text-xs font-semibold text-red-300 hover:text-red-200">Quitar</button>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Medio</label>
                                    <select :name="`payments[${index}][method]`" x-model="payment.method" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                                        @foreach($paymentMethods as $method)
                                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Importe</label>
                                    <input :name="`payments[${index}][amount]`" x-model="payment.amount" type="text" inputmode="decimal" placeholder="0,00" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Referencia</label>
                                    <input :name="`payments[${index}][reference]`" x-model="payment.reference" type="text" maxlength="255" placeholder="Obligatoria salvo efectivo" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-400">Fecha y hora</label>
                                    <input :name="`payments[${index}][paid_at]`" x-model="payment.paid_at" type="datetime-local" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="text-xs font-semibold text-slate-400">Notas del pago</label>
                                <input :name="`payments[${index}][notes]`" x-model="payment.notes" type="text" maxlength="2000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400">
                            </div>
                        </article>
                    </template>
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="sold_at" class="text-sm font-semibold text-slate-200">Fecha y hora de venta</label>
                        <input id="sold_at" name="sold_at" type="datetime-local" value="{{ old('sold_at') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                        <p class="mt-2 text-xs text-slate-500">Vacío registra el momento actual.</p>
                    </div>
                    <div>
                        <label for="notes" class="text-sm font-semibold text-slate-200">Notas internas</label>
                        <textarea id="notes" name="notes" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs leading-5 text-amber-100">
                    La confirmación es inmutable. Si falla el saldo, la identidad, la moneda o el pago exacto, toda la operación se revierte.
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                    <a href="{{ route('commerce-sales.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                    <button type="submit" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">Confirmar venta y cobro</button>
                </div>
            </section>
        </form>
    </div>
</x-app-layout>
