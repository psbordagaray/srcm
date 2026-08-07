<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Compras · Preparación</p><h1 class="mt-2 text-2xl font-bold text-white">Nueva orden de compra</h1><p class="mt-2 text-sm text-slate-400">El borrador es editable y todavía no mueve stock.</p></div>
        @include('purchases._order-form', ['action' => route('purchase-orders.store'), 'method' => 'POST', 'submitLabel' => 'Guardar borrador'])
    </div>
</x-app-layout>
