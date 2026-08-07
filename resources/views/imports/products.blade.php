<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-400">
                    Ingreso masivo controlado
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white sm:text-3xl">
                    Importar productos
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    CSV o Excel .xlsx · previsualización obligatoria · confirmación atómica.
                </p>
            </div>

            <a href="{{ route('product-imports.template') }}" class="sulu-button-secondary">
                Descargar plantilla CSV
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('error'))
            <div class="rounded-2xl border border-rose-400/20 bg-rose-400/10 p-4 text-sm text-rose-200">
                {{ session('error') }}
            </div>
        @endif

        <section class="sulu-card p-5 sm:p-6">
            <form
                method="POST"
                action="{{ route('product-imports.preview') }}"
                enctype="multipart/form-data"
                class="space-y-5"
            >
                @csrf

                <div>
                    <label for="product-import-file" class="block text-sm font-semibold text-white">
                        Archivo
                    </label>
                    <input
                        id="product-import-file"
                        type="file"
                        name="file"
                        accept=".csv,.txt,.xlsx"
                        required
                        class="mt-2 block w-full rounded-xl border border-white/10 bg-white/[0.03] p-3 text-sm text-slate-300 file:mr-4 file:rounded-lg file:border-0 file:bg-cyan-400/10 file:px-3 file:py-2 file:font-semibold file:text-cyan-300"
                    >
                    <p class="mt-2 text-xs text-slate-500">
                        Máximo 5 MB y 500 filas. Se usa únicamente la primera hoja del .xlsx.
                    </p>

                    @error('file')
                        <p class="mt-2 text-sm text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="sulu-button-primary">
                    Previsualizar y validar
                </button>
            </form>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <article class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Obligatorias</p>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    <strong>sku</strong>, <strong>nombre</strong> y <strong>categoria</strong>.
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Opcionales</p>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    marca, fabricante, descripción, unidad base, decimales y activo.
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Regla de seguridad</p>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Categorías, marcas y fabricantes deben existir y estar activos. La importación no crea maestros.
                </p>
            </article>
        </section>

        @if ($preview)
            <section class="sulu-card overflow-hidden">
                <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="font-bold text-white">
                                Previsualización
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $preview['file_name'] }} · {{ $preview['count'] }} filas de datos
                            </p>
                        </div>

                        @if ($preview['ready'])
                            <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1.5 text-xs font-bold text-emerald-300">
                                Listo para importar
                            </span>
                        @else
                            <span class="rounded-full border border-rose-400/20 bg-rose-400/10 px-3 py-1.5 text-xs font-bold text-rose-300">
                                Requiere correcciones
                            </span>
                        @endif
                    </div>

                    <p class="mt-3 break-all font-mono text-[11px] text-slate-600">
                        SHA-256 {{ $preview['sha256'] }}
                    </p>
                </div>

                @if ($preview['ignored_headers'])
                    <div class="border-b border-amber-400/10 bg-amber-400/[0.04] px-5 py-4 text-sm text-amber-200 sm:px-6">
                        Columnas ignoradas:
                        {{ implode(', ', $preview['ignored_headers']) }}
                    </div>
                @endif

                @if ($preview['errors'])
                    <div class="border-b border-rose-400/10 bg-rose-400/[0.04] px-5 py-5 sm:px-6">
                        <h3 class="font-semibold text-rose-200">
                            Errores detectados
                        </h3>
                        <ul class="mt-3 space-y-2 text-sm text-rose-200/90">
                            @foreach (array_slice($preview['errors'], 0, 30) as $error)
                                <li>
                                    <strong>Fila {{ $error['row'] }}:</strong>
                                    {{ $error['message'] }}
                                </li>
                            @endforeach
                        </ul>

                        @if (count($preview['errors']) > 30)
                            <p class="mt-3 text-xs text-rose-300">
                                Hay {{ count($preview['errors']) - 30 }} errores adicionales.
                            </p>
                        @endif
                    </div>
                @endif

                @if ($preview['rows'])
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-white/5 text-left text-sm">
                            <thead class="bg-white/[0.02] text-xs uppercase tracking-wider text-slate-600">
                                <tr>
                                    <th class="px-4 py-3">Fila</th>
                                    <th class="px-4 py-3">SKU</th>
                                    <th class="px-4 py-3">Nombre</th>
                                    <th class="px-4 py-3">Categoría</th>
                                    <th class="px-4 py-3">Marca</th>
                                    <th class="px-4 py-3">Unidad</th>
                                    <th class="px-4 py-3">Dec.</th>
                                    <th class="px-4 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                @foreach ($preview['rows'] as $row)
                                    <tr class="text-slate-300">
                                        <td class="px-4 py-3 text-slate-600">{{ $row['row_number'] }}</td>
                                        <td class="px-4 py-3 font-mono text-xs text-cyan-300">{{ $row['source']['sku'] }}</td>
                                        <td class="px-4 py-3 font-semibold text-white">{{ $row['source']['name'] }}</td>
                                        <td class="px-4 py-3">{{ $row['source']['category'] }}</td>
                                        <td class="px-4 py-3">{{ $row['source']['brand'] ?: '—' }}</td>
                                        <td class="px-4 py-3">{{ $row['source']['base_unit_code'] }}</td>
                                        <td class="px-4 py-3">{{ $row['source']['quantity_scale'] }}</td>
                                        <td class="px-4 py-3">{{ $row['source']['active'] ? 'Activo' : 'Inactivo' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($preview['count'] > 50)
                        <p class="border-t border-white/5 px-5 py-3 text-xs text-slate-600 sm:px-6">
                            Se muestran las primeras 50 filas de {{ $preview['count'] }}.
                        </p>
                    @endif
                @endif

                @if ($preview['ready'])
                    <div class="border-t border-white/5 px-5 py-5 sm:px-6">
                        <form
                            method="POST"
                            action="{{ route('product-imports.store') }}"
                            onsubmit="return confirm('¿Confirmar la importación de {{ $preview['count'] }} productos?');"
                        >
                            @csrf
                            <input type="hidden" name="token" value="{{ $preview['token'] }}">

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="max-w-2xl text-xs leading-5 text-slate-500">
                                    Antes de insertar, SRCM volverá a validar referencias y duplicados. Si una sola fila entra en conflicto, toda la operación se revierte.
                                </p>

                                <button type="submit" class="sulu-button-primary">
                                    Confirmar importación
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </section>
        @endif

        <section class="sulu-card p-5 sm:p-6">
            <h2 class="font-bold text-white">Valores admitidos</h2>
            <div class="mt-4 grid gap-4 text-sm text-slate-400 md:grid-cols-2">
                <div>
                    <p class="font-semibold text-slate-300">unidad_base</p>
                    <p class="mt-1">unidad / unit, litro / l, metro / m, kilogramo / kg.</p>
                </div>
                <div>
                    <p class="font-semibold text-slate-300">activo</p>
                    <p class="mt-1">sí/no, 1/0, true/false, activo/inactivo. Vacío = activo.</p>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>
