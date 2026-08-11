<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Finanzas · Configuración</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Editar cuenta financiera</h1>
            <p class="mt-2 text-sm text-slate-400">{{ $account->name }} · {{ $account->currency_code }} · {{ $account->active ? 'Activa' : 'Inactiva' }}</p>
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
            action="{{ route('financial-accounts.update', $account) }}"
            class="sulu-card p-6"
        >
            @csrf
            @method('PUT')
            @include('financial-accounts._form', ['account' => $account])
        </form>
    </div>
</x-app-layout>
