<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-400">Panel general</p>
                <h1 class="mt-2 text-2xl font-bold text-white sm:text-3xl">Bienvenido a SRCM</h1>
                <p class="mt-2 text-sm text-slate-500">Control central de productos, compatibilidades e inventario de SULU TV.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-xl border border-emerald-400/15 bg-emerald-400/5 px-3 py-2 text-xs font-semibold text-emerald-300">
                    <span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,.8)]"></span>
                    Sistema operativo
                </span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $stats = [
                    ['label' => 'Productos', 'value' => '0', 'detail' => 'Catálogo pendiente', 'tone' => 'cyan', 'icon' => 'box'],
                    ['label' => 'Categorías', 'value' => '0', 'detail' => 'Primer módulo', 'tone' => 'gold', 'icon' => 'categories'],
                    ['label' => 'Stock total', 'value' => '0', 'detail' => 'Unidades registradas', 'tone' => 'violet', 'icon' => 'inventory'],
                    ['label' => 'Stock bajo', 'value' => '0', 'detail' => 'Sin alertas', 'tone' => 'rose', 'icon' => 'alert'],
                ];
            @endphp

            @foreach ($stats as $stat)
                <article class="sulu-card group p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500">{{ $stat['label'] }}</p>
                            <p class="mt-3 text-3xl font-bold tracking-tight text-white">{{ $stat['value'] }}</p>
                        </div>
                        <span class="sulu-stat-icon sulu-stat-{{ $stat['tone'] }}">
                            @if ($stat['icon'] === 'alert')
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 2.5 20h19L12 3Z" /><path stroke-linecap="round" d="M12 9v4m0 3h.01" />
                                </svg>
                            @else
                                @include('components.sidebar-icon', ['name' => $stat['icon']])
                            @endif
                        </span>
                    </div>
                    <div class="mt-5 border-t border-white/5 pt-4 text-xs text-slate-600">{{ $stat['detail'] }}</div>
                </article>
            @endforeach
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.45fr_.8fr]">
            <article class="sulu-card overflow-hidden">
                <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="font-bold text-white">Búsqueda inteligente</h2>
                            <p class="mt-1 text-sm text-slate-500">El futuro corazón de SRCM.</p>
                        </div>
                        <span class="rounded-lg bg-cyan-400/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-cyan-300">Próximamente</span>
                    </div>
                </div>
                <div class="p-5 sm:p-6">
                    <div class="rounded-2xl border border-cyan-400/10 bg-cyan-400/[0.03] p-5 sm:p-7">
                        <div class="mx-auto max-w-2xl text-center">
                            <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-cyan-400/10 text-cyan-300 ring-1 ring-cyan-400/20">
                                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-3.5-3.5" />
                                </svg>
                            </span>
                            <h3 class="mt-4 text-lg font-bold text-white">Encontrá cualquier control en segundos</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">Buscá por código original, código alternativo o modelo de televisor para conocer compatibilidad, stock y ubicación.</p>
                            <div class="mt-5 flex flex-wrap justify-center gap-2">
                                <span class="sulu-chip">AKB75095308</span>
                                <span class="sulu-chip">43LM6300</span>
                                <span class="sulu-chip">RM-L1130</span>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="sulu-card">
                <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                    <h2 class="font-bold text-white">Próximos pasos</h2>
                    <p class="mt-1 text-sm text-slate-500">Hoja de ruta inmediata.</p>
                </div>
                <div class="p-5 sm:p-6">
                    <ol class="space-y-5">
                        @foreach ([
                            ['title' => 'Completar categorías', 'text' => 'CRUD, validaciones y seeder.', 'status' => 'En curso'],
                            ['title' => 'Agregar marcas', 'text' => 'Clasificación comercial.', 'status' => 'Pendiente'],
                            ['title' => 'Agregar fabricantes', 'text' => 'Origen real del producto.', 'status' => 'Pendiente'],
                            ['title' => 'Crear productos', 'text' => 'Códigos, imágenes y datos técnicos.', 'status' => 'Pendiente'],
                        ] as $index => $step)
                            <li class="flex gap-4">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl {{ $index === 0 ? 'bg-amber-400 text-sulu-950' : 'bg-white/5 text-slate-500 ring-1 ring-white/10' }} text-xs font-bold">{{ $index + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-semibold text-white">{{ $step['title'] }}</p>
                                        <span class="text-[10px] font-bold uppercase tracking-wider {{ $index === 0 ? 'text-amber-300' : 'text-slate-600' }}">{{ $step['status'] }}</span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ $step['text'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </article>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="sulu-card p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-bold text-white">Actividad reciente</h2>
                        <p class="mt-1 text-sm text-slate-500">Los movimientos aparecerán aquí.</p>
                    </div>
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-white/5 text-slate-500 ring-1 ring-white/10">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l3-8 4 16 3-8h4" /></svg>
                    </span>
                </div>
                <div class="mt-8 flex min-h-32 flex-col items-center justify-center rounded-2xl border border-dashed border-white/10 bg-white/[0.015] px-6 text-center">
                    <p class="text-sm font-medium text-slate-400">Todavía no hay actividad registrada</p>
                    <p class="mt-1 text-xs text-slate-600">Las altas, ediciones y movimientos de stock quedarán documentados.</p>
                </div>
            </article>

            <article class="sulu-card p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-bold text-white">Distribución de inventario</h2>
                        <p class="mt-1 text-sm text-slate-500">Stock futuro por ubicación.</p>
                    </div>
                    <span class="rounded-lg border border-amber-400/10 bg-amber-400/5 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-300">Opción B</span>
                </div>
                <div class="mt-6 space-y-4">
                    @foreach ([['Depósito principal', 0], ['Mostrador', 0], ['Vehículos técnicos', 0]] as [$location, $value])
                        <div>
                            <div class="mb-2 flex items-center justify-between text-xs">
                                <span class="font-medium text-slate-400">{{ $location }}</span>
                                <span class="font-bold text-slate-600">{{ $value }} unidades</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5"><div class="h-full w-0 rounded-full bg-cyan-400"></div></div>
                        </div>
                    @endforeach
                </div>
            </article>
        </section>
    </div>
</x-app-layout>
