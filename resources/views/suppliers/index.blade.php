<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Operación privada
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Proveedores
                </h1>

                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    Cada proveedor se vincula a una identidad comercial única.
                    No se duplica por producto, lista de precios ni catálogo recibido.
                </p>
            </div>

            @can('manage-commerce')
                <a
                    href="{{ route('suppliers.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nuevo proveedor
                </a>
            @else
                <span class="inline-flex rounded-full border border-slate-700 bg-slate-800/60 px-3 py-1.5 text-xs font-semibold text-slate-300">
                    Consulta
                </span>
            @endcan
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form
                    method="GET"
                    action="{{ route('suppliers.index') }}"
                    class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_13rem_13rem_auto]"
                >
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Nombre, CUIT, correo, teléfono o sitio..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <select
                        name="type"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos los tipos</option>
                        <option value="organization" @selected($type === 'organization')>Empresas</option>
                        <option value="person" @selected($type === 'person')>Personas</option>
                    </select>

                    <select
                        name="status"
                        class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos los estados</option>
                        <option value="active" @selected($status === 'active')>Activos</option>
                        <option value="inactive" @selected($status === 'inactive')>Inactivos</option>
                    </select>

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Buscar
                        </button>

                        @if ($search !== '' || $type !== '' || $status !== '')
                            <a
                                href="{{ route('suppliers.index') }}"
                                class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                            >
                                Limpiar
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            @if ($suppliers->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron proveedores.
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        {{ $search !== '' || $type !== '' || $status !== ''
                            ? 'Probá con otros filtros.'
                            : 'Todavía no se registraron proveedores.' }}
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Proveedor
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Contacto
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Identificación
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Estado
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Acciones
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            @foreach ($suppliers as $supplier)
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="px-6 py-4">
                                        <a
                                            href="{{ route('suppliers.show', $supplier) }}"
                                            class="font-semibold text-cyan-300 transition hover:text-cyan-200"
                                        >
                                            {{ $supplier->party->name }}
                                        </a>

                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $supplier->party->party_type === 'organization'
                                                ? 'Empresa u organización'
                                                : 'Persona' }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        <div>{{ $supplier->party->email ?? 'Sin correo' }}</div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $supplier->party->phone ?? 'Sin teléfono' }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-sm text-slate-300">
                                        <div class="font-mono">
                                            {{ $supplier->party->tax_id ?? 'No informada' }}
                                        </div>

                                        @if ($supplier->party->website)
                                            <div class="mt-1 max-w-xs truncate text-xs text-slate-500">
                                                {{ $supplier->party->website }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $supplier->active
                                            ? 'bg-emerald-400/10 text-emerald-300'
                                            : 'bg-slate-700/70 text-slate-300' }}">
                                            {{ $supplier->active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-3">
                                            <a
                                                href="{{ route('suppliers.show', $supplier) }}"
                                                class="text-sm font-semibold text-cyan-400 transition hover:text-cyan-300"
                                            >
                                                Abrir
                                            </a>

                                            @can('manage-commerce')
                                                <a
                                                    href="{{ route('suppliers.edit', $supplier) }}"
                                                    class="text-sm font-semibold text-amber-300 transition hover:text-amber-200"
                                                >
                                                    Editar
                                                </a>

                                                <form
                                                    method="POST"
                                                    action="{{ route('suppliers.toggle-active', $supplier) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        class="text-sm font-semibold {{ $supplier->active
                                                            ? 'text-red-300 hover:text-red-200'
                                                            : 'text-emerald-300 hover:text-emerald-200' }}"
                                                    >
                                                        {{ $supplier->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($suppliers->hasPages())
                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $suppliers->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
