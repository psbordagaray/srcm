<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Clientes · Identidad única</p><h1 class="mt-2 text-2xl font-bold text-white">Nuevo cliente</h1><p class="mt-2 text-sm text-slate-400">SRCM reutiliza una identidad comercial existente cuando la coincidencia es inequívoca.</p></div>
        <form method="POST" action="{{ route('customers.store') }}" class="space-y-6">@csrf @include('customers._form',['customer'=>null,'submitLabel'=>'Registrar cliente'])</form>
    </div>
</x-app-layout>
