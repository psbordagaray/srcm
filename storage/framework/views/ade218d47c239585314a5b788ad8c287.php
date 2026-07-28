<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

        <title><?php echo e(config('app.name', 'SRCM')); ?></title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    </head>
    <body class="font-sans antialiased bg-sulu-950 text-slate-100">
        <div x-data="{ sidebarOpen: false }" class="min-h-screen">
            <div
                x-show="sidebarOpen"
                x-transition.opacity
                class="fixed inset-0 z-40 bg-black/70 lg:hidden"
                @click="sidebarOpen = false"
                aria-hidden="true"
            ></div>

            <?php echo $__env->make('layouts.navigation', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

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

                        <form action="#" method="GET" class="relative flex-1" role="search">
                            <label for="global-search" class="sr-only">Buscar productos, códigos o modelos</label>
                            <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="7" />
                                <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                            </svg>
                            <input
                                id="global-search"
                                name="q"
                                type="search"
                                placeholder="Buscar por código, producto o modelo de TV..."
                                class="sulu-search-input"
                            >
                            <kbd class="pointer-events-none absolute right-4 top-1/2 hidden -translate-y-1/2 rounded border border-white/10 bg-white/5 px-2 py-1 text-[11px] font-semibold text-slate-500 sm:block">Ctrl K</kbd>
                        </form>

                        <div class="hidden items-center gap-3 sm:flex">
                            <button type="button" class="sulu-icon-button" aria-label="Notificaciones">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                                    <path stroke-linecap="round" d="M10 21h4" />
                                </svg>
                            </button>

                            <div class="h-8 w-px bg-white/10"></div>

                            <a href="<?php echo e(route('profile.edit')); ?>" class="flex items-center gap-3 rounded-xl px-2 py-1.5 transition hover:bg-white/5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-400/10 text-sm font-bold text-cyan-300 ring-1 ring-cyan-400/20">
                                    <?php echo e(strtoupper(substr(Auth::user()->name, 0, 1))); ?>

                                </span>
                                <span class="hidden xl:block">
                                    <span class="block text-sm font-semibold text-white"><?php echo e(Auth::user()->name); ?></span>
                                    <span class="block text-xs text-slate-500">Administrador</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </header>

                <?php if(isset($header)): ?>
                    <div class="border-b border-white/5 px-4 py-6 sm:px-6 lg:px-8">
                        <?php echo e($header); ?>

                    </div>
                <?php endif; ?>

                <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <?php echo e($slot); ?>

                </main>
            </div>
        </div>
    </body>
</html>
<?php /**PATH C:\laragon\www\srcm\resources\views/layouts/app.blade.php ENDPATH**/ ?>