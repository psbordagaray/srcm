<x-app-layout>

    <div class="max-w-4xl mx-auto space-y-8">

        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-cyan-400">
                Catálogo
            </p>

            <h1 class="mt-2 text-3xl font-bold text-white">
                Editar categoría
            </h1>

            <p class="mt-2 text-slate-400">
                Actualizá la información de la categoría.
            </p>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-8">

            <form
                action="{{ route('product-categories.update', $category) }}"
                method="POST"
                class="space-y-6"
            >

                @csrf
                @method('PUT')

                @include('product-categories._form')

                <div class="flex gap-4">

                    <button
                        type="submit"
                        class="rounded-xl bg-cyan-400 px-6 py-3 font-semibold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Guardar cambios
                    </button>

                    <a
                        href="{{ route('product-categories.index') }}"
                        class="rounded-xl border border-slate-700 px-6 py-3 text-white transition hover:border-slate-500"
                    >
                        Cancelar
                    </a>

                </div>

            </form>

        </div>

    </div>

</x-app-layout>