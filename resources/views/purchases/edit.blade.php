<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Compras · Borrador</p><h1 class="mt-2 text-2xl font-bold text-white">Revisar orden {{ strtoupper(substr($order->public_id, 0, 8)) }}</h1><p class="mt-2 text-sm text-slate-400">La revisión conserva la misma identidad e idempotencia. Una vez emitida, la orden queda inmutable.</p></div>
        @include('purchases._order-form', ['action' => route('purchase-orders.update', $order), 'method' => 'PUT', 'submitLabel' => 'Guardar revisión'])
    </div>
</x-app-layout>
