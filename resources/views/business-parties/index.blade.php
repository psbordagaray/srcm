<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Núcleo
                </p>
                <h2 class="mt-1 text-2xl font-bold text-white">
                    Personas e identidades
                </h2>
                <p class="mt-1 text-sm text-slate-400">
                    Directorio único de personas y organizaciones vinculadas a la operación.
                </p>
            </div>

            @can('manage-business-parties')
                <a
                    href="{{ route('business-parties.create') }}"
                    class="rounded-xl bg-cyan-400 px-5 py-2.5 font-semibold text-slate-950 hover:bg-cyan-300"
                >
                    Nueva identidad
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <form
            method="GET"
            action="{{ route('business-parties.index') }}"
            class="grid gap-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-5 md:grid-cols-4"
        >
            <div class="md:col-span-2">
                <label for="search" class="block text-sm font-medium text-slate-300">
                    Buscar
                </label>
                <input
                    id="search"
                    name="search"
                    value="{{ $search }}"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
                    placeholder="Nombre, DNI/CUIT, correo o teléfono"
                >
            </div>

            <div>
                <label for="type" class="block text-sm font-medium text-slate-300">
                    Tipo
                </label>
                <select
                    id="type"
                    name="type"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
                >
                    <option value="">Todos</option>
                    <option value="person" @selected($type === 'person')>Persona</option>
                    <option value="organization" @selected($type === 'organization')>Organización</option>
                </select>
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-slate-300">
                    Rol
                </label>
                <select
                    id="role"
                    name="role"
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-900 text-slate-100"
                >
                    <option value="">Todos</option>
                    <option value="customer" @selected($role === 'customer')>Cliente</option>
                    <option value="supplier" @selected($role === 'supplier')>Proveedor</option>
                    <option value="both" @selected($role === 'both')>Cliente + Proveedor</option>
                    <option value="unassigned" @selected($role === 'unassigned')>Sin rol</option>
                </select>
            </div>

            <div class="md:col-span-4 flex gap-3">
                <button
                    type="submit"
                    class="rounded-xl bg-slate-100 px-4 py-2 font-semibold text-slate-950"
                >
                    Filtrar
                </button>
                <a
                    href="{{ route('business-parties.index') }}"
                    class="rounded-xl border border-slate-700 px-4 py-2 font-semibold text-slate-300"
                >
                    Limpiar
                </a>
            </div>
        </form>

        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-800 bg-slate-950/70">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-900/80">
                        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="px-5 py-4">Identidad</th>
                            <th class="px-5 py-4">Documento</th>
                            <th class="px-5 py-4">Contacto</th>
                            <th class="px-5 py-4">Roles</th>
                            <th class="px-5 py-4 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($parties as $party)
                            <tr class="text-sm text-slate-300">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-white">{{ $party->name }}</div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ $party->party_type === 'person' ? 'Persona' : 'Organización' }}
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    {{ $party->tax_id ?: '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <div>{{ $party->email ?: '—' }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $party->phone ?: '' }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($party->customer)
                                            <span class="rounded-full border border-cyan-500/40 px-2.5 py-1 text-xs text-cyan-300">
                                                Cliente{{ $party->customer->active ? '' : ' · inactivo' }}
                                            </span>
                                        @endif
                                        @if ($party->supplier)
                                            <span class="rounded-full border border-amber-500/40 px-2.5 py-1 text-xs text-amber-300">
                                                Proveedor{{ $party->supplier->active ? '' : ' · inactivo' }}
                                            </span>
                                        @endif
                                        @if (! $party->customer && ! $party->supplier)
                                            <span class="text-xs text-slate-500">Sin rol comercial</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a
                                        href="{{ route('business-parties.show', $party) }}"
                                        class="font-semibold text-cyan-300 hover:text-cyan-200"
                                    >
                                        Ver expediente
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">
                                    No hay identidades que coincidan con el filtro.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">
            {{ $parties->links() }}
        </div>
    </div>
</x-app-layout>
