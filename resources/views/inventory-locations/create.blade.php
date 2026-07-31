<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-cyan-400">
                Inventario
            </p>

            <h1 class="mt-2 text-3xl font-bold text-white">
                Nueva ubicación
            </h1>

            <p class="mt-2 text-slate-400">
                Representá el lugar físico real sin cargar todavía cantidades de mercadería.
            </p>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-8">
            <form
                action="{{ route('inventory-locations.store') }}"
                method="POST"
                class="space-y-6"
            >
                @include('inventory-locations._form')

                <div class="flex flex-wrap gap-4">
                    <button
                        type="submit"
                        class="rounded-xl bg-cyan-400 px-6 py-3 font-semibold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Guardar ubicación
                    </button>

                    <a
                        href="{{ route('inventory-locations.index') }}"
                        class="rounded-xl border border-slate-700 px-6 py-3 text-white transition hover:border-slate-500"
                    >
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
