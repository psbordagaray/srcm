<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'SRCM') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-sulu-950 text-slate-100">
        @php
            $operationalAttention = app(
                \App\Domain\Attention\OperationalAttentionReader::class
            )->read(Auth::user());
        @endphp

        <div
            x-data="{ sidebarOpen: false, attentionOpen: false }"
            class="min-h-screen"
        >
            <div
                x-show="sidebarOpen"
                x-transition.opacity
                class="fixed inset-0 z-40 bg-black/70 lg:hidden"
                @click="sidebarOpen = false"
                aria-hidden="true"
            ></div>

            @include('layouts.navigation')

            <div class="lg:pl-72">
                <header class="sticky top-0 z-30 border-b border-white/10 bg-sulu-950/90 backdrop-blur-xl">
                    <div class="flex min-h-20 items-center gap-4 px-4 sm:px-6 lg:px-8">
                        <button
                            type="button"
                            class="sulu-icon-button lg:hidden"
                            @click="sidebarOpen = true"
                            aria-label="Abrir menú"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>

                        <form action="{{ route('global-search.index') }}" method="GET" class="relative flex-1" role="search">
                            <label for="global-search" class="sr-only">Buscar en SRCM</label>
                            <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="7" />
                                <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                            </svg>
                            <input
                                id="global-search"
                                x-ref="globalSearch"
                                name="q"
                                type="search"
                                value="{{ request()->routeIs('global-search.*') ? request('q') : '' }}"
                                placeholder="Buscar en SRCM: producto, cliente, IMEI, reparación, compra o venta..."
                                autocomplete="off"
                                @keydown.window.ctrl.k.prevent="$refs.globalSearch.focus(); $refs.globalSearch.select()"
                                class="sulu-search-input"
                            >
                            <kbd class="pointer-events-none absolute right-4 top-1/2 hidden -translate-y-1/2 rounded border border-white/10 bg-white/5 px-2 py-1 text-[11px] font-semibold text-slate-500 sm:block">Ctrl K</kbd>
                        </form>

                        <div
                            class="relative"
                            data-operational-attention-bell
                            @click.outside="attentionOpen = false"
                        >
                            <button
                                type="button"
                                class="sulu-icon-button relative"
                                aria-label="Atenciones operativas: {{ $operationalAttention['count'] }}"
                                :aria-expanded="attentionOpen"
                                @click="attentionOpen = ! attentionOpen"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                                    <path stroke-linecap="round" d="M10 21h4" />
                                </svg>

                                @if($operationalAttention['count'] > 0)
                                    <span
                                        class="absolute -right-1 -top-1 grid min-h-5 min-w-5 place-items-center rounded-full bg-amber-300 px-1 text-[10px] font-black text-slate-950 ring-2 ring-sulu-950"
                                        data-operational-attention-count
                                    >
                                        {{ $operationalAttention['count'] > 99 ? '99+' : $operationalAttention['count'] }}
                                    </span>
                                @endif
                            </button>

                            <div
                                x-cloak
                                x-show="attentionOpen"
                                x-transition.origin.top.right
                                class="absolute right-0 top-12 z-50 w-[min(26rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-white/10 bg-sulu-900 shadow-2xl shadow-black/40"
                                data-operational-attention-panel
                            >
                                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                                    <div>
                                        <p class="text-sm font-black text-white">
                                            Atención operativa
                                        </p>
                                        <p class="mt-0.5 text-[11px] text-slate-500">
                                            Acciones y resultados relevantes para vos.
                                        </p>
                                    </div>
                                    @if($operationalAttention['count'] > 0)
                                        <span class="rounded-full bg-amber-300/10 px-2 py-1 text-xs font-black text-amber-300">
                                            {{ $operationalAttention['count'] }}
                                        </span>
                                    @endif
                                </div>

                                <div class="max-h-[28rem] overflow-y-auto divide-y divide-white/5">
                                    @forelse($operationalAttention['items'] as $attentionItem)
                                        <div class="p-4">
                                            <a href="{{ $attentionItem['url'] }}" class="block">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div class="min-w-0">
                                                        <p class="text-xs font-black uppercase tracking-wider {{ $attentionItem['kind'] === 'action' ? 'text-amber-300' : 'text-cyan-300' }}">
                                                            {{ $attentionItem['kind'] === 'action' ? 'Acción requerida' : 'Resultado' }}
                                                        </p>
                                                        <p class="mt-1 text-sm font-bold text-white">
                                                            {{ $attentionItem['title'] }}
                                                        </p>
                                                        <p class="mt-1 text-xs leading-5 text-slate-400">
                                                            {{ $attentionItem['detail'] }}
                                                        </p>
                                                        <p class="mt-1 text-[11px] text-slate-600">
                                                            {{ $attentionItem['occurred_at']?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                                        </p>
                                                    </div>
                                                    <span class="shrink-0 text-xs font-black text-cyan-300">
                                                        Abrir
                                                    </span>
                                                </div>
                                            </a>

                                            @if($attentionItem['acknowledgeable'])
                                                <form
                                                    method="POST"
                                                    action="{{ route('operational-attention.acknowledge') }}"
                                                    class="mt-3"
                                                >
                                                    @csrf
                                                    <input
                                                        type="hidden"
                                                        name="attention_key"
                                                        value="{{ $attentionItem['key'] }}"
                                                    >
                                                    <button
                                                        type="submit"
                                                        class="text-xs font-bold text-slate-500 hover:text-white"
                                                    >
                                                        Marcar resultado como visto
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="px-5 py-8 text-center">
                                            <p class="text-sm font-bold text-slate-300">
                                                No tenés pendientes.
                                            </p>
                                            <p class="mt-1 text-xs text-slate-600">
                                                SRCM mostrará acá las decisiones y resultados que requieran tu atención.
                                            </p>
                                        </div>
                                    @endforelse
                                </div>

                                <div class="border-t border-white/10 px-4 py-3">
                                    <a href="{{ route('dashboard') }}" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">
                                        Ver en Dashboard
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="hidden items-center gap-3 sm:flex">
                            <div class="h-8 w-px bg-white/10"></div>

                            <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-xl px-2 py-1.5 transition hover:bg-white/5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-400/10 text-sm font-bold text-cyan-300 ring-1 ring-cyan-400/20">
                                    {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                                </span>
                                <span class="hidden xl:block">
                                    <span class="block text-sm font-semibold text-white">{{ Auth::user()->name }}</span>
                                    <span class="block text-xs text-slate-500">{{ app(\App\Domain\Tenancy\CurrentOrganization::class)
                                        ->roleFor(Auth::user())
                                        ?->label()
                                        ?? Auth::user()->role->label() }}</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </header>

                @isset($header)
                    <div class="border-b border-white/5 px-4 py-6 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                @endisset

                <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @can('record-commerce-sales')
            <script data-srcm-global-f1-shortcut>
                document.addEventListener('keydown', function (event) {
                    if (
                        event.key !== 'F1'
                        || event.altKey
                        || event.ctrlKey
                        || event.metaKey
                    ) {
                        return;
                    }

                    event.preventDefault();

                    const saleForm = document.querySelector(
                        '[data-sale-explicit-submit-only]'
                    );

                    if (saleForm) {
                        const hasProducts = Boolean(
                            saleForm.querySelector(
                                '[name^="product_lines["]'
                            )
                        );
                        const hasPayments = Boolean(
                            saleForm.querySelector(
                                '[name^="payments["]'
                            )
                        );
                        const hasService = Boolean(
                            saleForm.querySelector(
                                '[name="service_order_id"]'
                            )?.value
                        );

                        if (
                            (hasProducts || hasPayments || hasService)
                            && ! window.confirm(
                                'Hay una venta en curso. ¿Iniciar una nueva venta y descartar el borrador actual?'
                            )
                        ) {
                            return;
                        }
                    }

                    window.location.assign(
                        @json(route('commerce-sales.create'))
                    );
                });
            </script>
        @endcan
    </body>
</html>
