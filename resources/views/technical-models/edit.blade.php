<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Catálogo conectado
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Editar modelo técnico
                </h1>

                <p class="mt-2 font-mono text-sm text-slate-400">
                    {{ $technicalModel->code }}
                </p>
            </div>

            @if ($technicalModel->knowledgeEntity)
                <a
                    href="{{ route('technical-models.show', $technicalModel) }}"
                    class="inline-flex items-center justify-center rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-4 py-2.5 text-sm font-semibold text-cyan-300 transition hover:bg-cyan-500/20"
                >
                    Abrir ficha vinculada
                </a>
            @endif
        </div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
            <form method="POST" action="{{ route('technical-models.update', $technicalModel) }}">
                @csrf
                @method('PUT')

                @include('technical-models._form')
            </form>
        </section>
    </div>
</x-app-layout>
