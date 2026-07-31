<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Frontera privada
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    {{ $organization->name }}
                </h1>

                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    Esta organización es propietaria de sus proveedores, ofertas,
                    inventario futuro, costos, precios, clientes, compras y ventas.
                    El catálogo maestro y el conocimiento permanecen separados.
                </p>
            </div>

            @can('manage-organization')
                <a
                    href="{{ route('organization.edit') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Editar organización
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Nombre', $organization->name],
                ['CUIT / identificación fiscal', $organization->tax_id ?: 'No informado'],
                ['Correo', $organization->email ?: 'No informado'],
                ['Teléfono', $organization->phone ?: 'No informado'],
            ] as [$label, $value])
                <article class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        {{ $label }}
                    </p>
                    <p class="mt-2 break-words text-sm font-semibold text-white">
                        {{ $value }}
                    </p>
                </article>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80">
            <div class="border-b border-slate-800 px-6 py-4">
                <h2 class="font-bold text-white">Miembros</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Los permisos comerciales y de auditoría se resuelven dentro de la organización activa.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/60">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                Usuario
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                Rol
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                Estado
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-800">
                        @foreach ($organization->memberships as $membership)
                            <tr>
                                <td class="px-6 py-4">
                                    <p class="font-semibold text-white">
                                        {{ $membership->user->name }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $membership->user->email }}
                                    </p>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">
                                    {{ $membership->role->label() }}
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $membership->active ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-slate-700 bg-slate-800 text-slate-400' }}">
                                        {{ $membership->active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($memberships->count() > 1)
            <section class="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-5">
                <h2 class="font-bold text-amber-200">Cambiar organización activa</h2>

                <div class="mt-4 flex flex-wrap gap-3">
                    @foreach ($memberships as $membership)
                        <form
                            method="POST"
                            action="{{ route('organizations.activate', $membership->organization) }}"
                        >
                            @csrf

                            <button
                                type="submit"
                                @disabled($membership->organization_id === $organization->id)
                                class="rounded-xl border px-4 py-2 text-sm font-semibold transition {{ $membership->organization_id === $organization->id ? 'cursor-not-allowed border-cyan-400/20 bg-cyan-400/10 text-cyan-200' : 'border-slate-700 text-slate-300 hover:border-slate-500 hover:text-white' }}"
                            >
                                {{ $membership->organization->name }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
