<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Carga única APB
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Nuevo modelo técnico
            </h1>

            <p class="mt-2 text-sm leading-6 text-slate-400">
                Registrá marca, categoría y código una sola vez.
                SRCM creará automáticamente la ficha de conocimiento
                y te llevará directamente a compatibilidades.
            </p>
        </div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
            <form method="POST" action="{{ route('technical-models.store') }}">
                @csrf

                @include('technical-models._form')
            </form>
        </section>
    </div>
</x-app-layout>
