<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Clientes · Identidad compartida</p><h1 class="mt-2 text-2xl font-bold text-white">Editar {{ $customer->party->name }}</h1><p class="mt-2 text-sm text-slate-400">Los cambios de identidad también serán visibles en otros roles que compartan la misma BusinessParty.</p></div>
        <form method="POST" action="{{ route('customers.update',$customer) }}" class="space-y-6">@csrf @method('PUT') @include('customers._form',['customer'=>$customer,'submitLabel'=>'Guardar cambios'])</form>
    </div>
</x-app-layout>
