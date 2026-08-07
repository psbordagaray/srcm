<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Personas
            </p>
            <h2 class="mt-1 text-2xl font-bold text-white">
                Nueva identidad comercial
            </h2>
        </div>
    </x-slot>

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-6">
            <p class="mb-6 text-sm text-slate-400">
                La identidad puede existir sin rol comercial. Cliente y Proveedor se asignan desde sus módulos y reutilizan este mismo registro.
            </p>

            <form method="POST" action="{{ route('business-parties.store') }}">
                @csrf
                @include('business-parties._form')
            </form>
        </div>
    </div>
</x-app-layout>
