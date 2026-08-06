<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Consumo</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Registrar consumo de repuesto</h1>
            <p class="mt-2 text-sm text-slate-400">Orden #{{ $order->order_number }} · {{ $requirement->product->name }}</p>
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

        <section class="sulu-card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-orange-300">{{ $requirement->source->label() }}</p>
                    <h2 class="mt-2 text-lg font-bold text-white">{{ $requirement->product->name }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $requirement->condition->label() }} · Trabajo {{ $requirement->workItem->sequence }}</p>
                </div>
                <div class="text-right">
                    <p class="font-mono text-lg font-bold text-cyan-200">{{ $remainingQuantity }} {{ $requirement->base_unit_code }}</p>
                    <p class="mt-1 text-[10px] uppercase tracking-wider text-slate-500">Pendiente</p>
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('service-orders.part-requirements.consume.store', [$order, $requirement]) }}" class="sulu-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <div>
                <label for="quantity" class="text-sm font-semibold text-slate-200">Cantidad consumida</label>
                <input id="quantity" name="quantity" type="text" required value="{{ old('quantity', $remainingQuantity) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
            </div>

            @if($requirement->source === \App\Enums\ServicePartSource::Stock)
                <div>
                    <label for="source_location_id" class="text-sm font-semibold text-slate-200">Ubicación de origen</label>
                    <select id="source_location_id" name="source_location_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Seleccionar</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}" @selected((string) old('source_location_id') === (string) $location->id)>
                                {{ $location->name }} · {{ $location->type->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">Al confirmar se crea y confirma una salida de inventario vinculada a la orden.</p>
                </div>
            @else
                <div>
                    <label for="service_part_purchase_line_id" class="text-sm font-semibold text-slate-200">Compra que entrega el repuesto</label>
                    <select id="service_part_purchase_line_id" name="service_part_purchase_line_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Seleccionar</option>
                        @foreach($purchaseLines as $line)
                            <option value="{{ $line->id }}" @selected((string) old('service_part_purchase_line_id') === (string) $line->id)>
                                {{ $line->purchase->supplier->party->name }} · {{ $line->purchase->purchased_at->timezone(config('app.timezone'))->format('d/m/Y') }} · disponible {{ $line->available_quantity }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">El consumo se imputa a la compra afectada y no toca el stock general.</p>
                </div>
            @endif

            <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-xs text-cyan-100">
                El consumo confirmado es inmutable. Una corrección posterior debe registrarse mediante un nuevo hecho operativo.
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                <button type="submit" class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Confirmar consumo</button>
            </div>
        </form>
    </div>
</x-app-layout>
