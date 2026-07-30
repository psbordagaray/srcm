<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Catálogo maestro
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Nuevo fabricante
            </h1>

            <p class="mt-2 text-sm leading-6 text-slate-400">
                Registrá quién produce física o técnicamente los artículos.
                La marca y el proveedor se administran por separado.
            </p>
        </div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
            <form
                method="POST"
                action="{{ route('manufacturers.store') }}"
            >
                @csrf

                @include('manufacturers._form')
            </form>
        </section>
    </div>
</x-app-layout>
