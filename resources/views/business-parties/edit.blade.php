<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Personas
            </p>
            <h2 class="mt-1 text-2xl font-bold text-white">
                Editar {{ $party->name }}
            </h2>
        </div>
    </x-slot>

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-6">
            <p class="mb-6 text-sm text-amber-300">
                Estos datos pertenecen a la identidad compartida. Los cambios serán visibles también en sus roles Cliente y Proveedor.
            </p>

            <form method="POST" action="{{ route('business-parties.update', $party) }}">
                @csrf
                @method('PUT')
                @include('business-parties._form', ['party' => $party])
            </form>
        </div>
    </div>
</x-app-layout>
