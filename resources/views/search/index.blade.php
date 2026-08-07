<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-400">
                Acceso transversal
            </p>
            <h1 class="mt-2 text-2xl font-bold text-white sm:text-3xl">
                Búsqueda global
            </h1>
            <p class="mt-2 text-sm text-slate-500">
                Encontrá rápidamente una ficha operativa sin perder su fuente de verdad.
            </p>
        </div>
    </x-slot>

    <div class="space-y-6">
        <section class="sulu-card p-5 sm:p-6">
            <form method="GET" action="{{ route('global-search.index') }}" class="flex flex-col gap-3 sm:flex-row">
                <div class="flex-1">
                    <label for="global-search-query" class="sr-only">Buscar en SRCM</label>
                    <input
                        id="global-search-query"
                        name="q"
                        value="{{ $query }}"
                        class="sulu-input w-full"
                        placeholder="SKU, producto, CUIT/DNI, cliente, IMEI, reparación, compra o venta"
                        maxlength="100"
                        autocomplete="off"
                        autofocus
                    >
                </div>
                <button type="submit" class="sulu-button-primary">
                    Buscar
                </button>
            </form>

            <div class="mt-4 flex flex-wrap gap-2 text-xs text-slate-500">
                <span class="rounded-lg border border-white/5 px-2.5 py-1.5">SKU / producto</span>
                <span class="rounded-lg border border-white/5 px-2.5 py-1.5">CUIT / DNI / contacto</span>
                <span class="rounded-lg border border-white/5 px-2.5 py-1.5">IMEI / serie</span>
                <span class="rounded-lg border border-white/5 px-2.5 py-1.5">N.º reparación / venta</span>
                <span class="rounded-lg border border-white/5 px-2.5 py-1.5">Proveedor / compra</span>
            </div>
        </section>

        @if ($query === '')
            <section class="sulu-card p-8 text-center">
                <h2 class="text-lg font-bold text-white">Buscador operativo listo</h2>
                <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                    Escribí al menos dos caracteres. Los resultados privados pertenecen exclusivamente a la organización activa; el catálogo técnico compartido mantiene su alcance global.
                </p>
            </section>
        @elseif (! $ready)
            <section class="sulu-card p-8 text-center">
                <h2 class="text-lg font-bold text-white">Consulta demasiado corta</h2>
                <p class="mt-2 text-sm text-slate-500">
                    Ingresá al menos dos caracteres útiles. Los comodines “%” y “_” no cuentan como búsqueda.
                </p>
            </section>
        @elseif ($total === 0)
            <section class="sulu-card p-8 text-center">
                <h2 class="text-lg font-bold text-white">Sin resultados</h2>
                <p class="mt-2 text-sm text-slate-500">
                    No encontramos coincidencias para “{{ $query }}” en las fuentes operativas habilitadas.
                </p>
            </section>
        @else
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">
                    {{ $total }} {{ $total === 1 ? 'resultado' : 'resultados' }} para
                    <span class="font-semibold text-slate-300">“{{ $query }}”</span>
                </p>
                <p class="text-xs text-slate-600">Máximo 8 por tipo</p>
            </div>

            <div class="space-y-6">
                @foreach ($groups as $group)
                    @if ($group['items']->isNotEmpty())
                        <section class="sulu-card overflow-hidden">
                            <div class="flex items-center justify-between border-b border-white/5 px-5 py-4 sm:px-6">
                                <h2 class="font-bold text-white">{{ $group['label'] }}</h2>
                                <span class="rounded-full border border-cyan-400/10 bg-cyan-400/5 px-2.5 py-1 text-xs font-bold text-cyan-300">
                                    {{ $group['items']->count() }}
                                </span>
                            </div>

                            <div class="divide-y divide-white/5">
                                @foreach ($group['items'] as $item)
                                    <a
                                        href="{{ $item['url'] }}"
                                        class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-white/[0.025] sm:px-6"
                                    >
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-md border border-white/10 bg-white/[0.03] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                                    {{ $item['type'] }}
                                                </span>
                                                <p class="truncate text-sm font-semibold text-white">
                                                    {{ $item['title'] }}
                                                </p>
                                            </div>

                                            <p class="mt-2 truncate text-xs text-slate-500">
                                                {{ $item['subtitle'] }}
                                            </p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-3">
                                            <span class="hidden text-xs text-slate-500 sm:inline">
                                                {{ $item['meta'] }}
                                            </span>
                                            <span aria-hidden="true" class="text-cyan-300">→</span>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
