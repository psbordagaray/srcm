<x-app-layout>
    <div class="mx-auto max-w-3xl">
        <div class="mb-8">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Catálogo
            </p>

            <h1 class="mt-2 text-3xl font-bold text-white">
                Editar marca
            </h1>

            <p class="mt-2 text-slate-400">
                Actualizá la información de {{ $brand->name }}.
            </p>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-8 shadow-xl">
            <form
                action="{{ route('brands.update', $brand) }}"
                method="POST"
                class="space-y-6"
            >
                @method('PUT')

                @include('brands._form')

                <div class="flex items-center justify-end gap-3">
                    <a
                        href="{{ route('brands.index') }}"
                        class="rounded-xl border border-slate-700 px-5 py-2.5 font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="rounded-xl bg-cyan-400 px-5 py-2.5 font-bold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>