<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-cyan-400">
                Organización · seguridad
            </p>
            <h1 class="mt-2 text-2xl font-bold text-white sm:text-3xl">
                Usuarios y permisos
            </h1>
            <p class="mt-2 text-sm text-slate-500">
                {{ $organization->name }} · el rol efectivo pertenece a esta organización.
            </p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-4 text-sm text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-2xl border border-rose-400/20 bg-rose-400/10 p-4 text-sm text-rose-200">
                {{ session('error') }}
            </div>
        @endif

        @can('manage-organization-members')
            <section class="sulu-card p-5 sm:p-6">
                <div class="max-w-3xl">
                    <h2 class="text-lg font-bold text-white">
                        Agregar acceso
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Si el correo ya pertenece a un usuario de SRCM, se agrega únicamente su membresía. Para una cuenta nueva, completá también nombre y contraseña inicial.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('organization-members.store') }}"
                    class="mt-6 grid gap-4 md:grid-cols-2"
                >
                    @csrf

                    <div>
                        <label for="member-name" class="block text-sm font-semibold text-white">
                            Nombre
                        </label>
                        <input
                            id="member-name"
                            name="name"
                            value="{{ old('name') }}"
                            class="sulu-input mt-2 w-full"
                            autocomplete="name"
                            placeholder="Obligatorio sólo para cuenta nueva"
                        >
                        @error('name')
                            <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="member-email" class="block text-sm font-semibold text-white">
                            Email
                        </label>
                        <input
                            id="member-email"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            class="sulu-input mt-2 w-full"
                            autocomplete="off"
                            required
                        >
                        @error('email')
                            <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="member-password" class="block text-sm font-semibold text-white">
                            Contraseña inicial
                        </label>
                        <input
                            id="member-password"
                            type="password"
                            name="password"
                            class="sulu-input mt-2 w-full"
                            autocomplete="new-password"
                            placeholder="Mínimo 8 caracteres"
                        >
                        @error('password')
                            <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="member-password-confirmation" class="block text-sm font-semibold text-white">
                            Confirmar contraseña
                        </label>
                        <input
                            id="member-password-confirmation"
                            type="password"
                            name="password_confirmation"
                            class="sulu-input mt-2 w-full"
                            autocomplete="new-password"
                        >
                    </div>

                    <div>
                        <label for="member-role" class="block text-sm font-semibold text-white">
                            Rol
                        </label>
                        <select
                            id="member-role"
                            name="role"
                            class="sulu-input mt-2 w-full"
                            required
                        >
                            @foreach ($roles as $role)
                                <option
                                    value="{{ $role->value }}"
                                    @selected(old('role', \App\Enums\UserRole::Viewer->value) === $role->value)
                                >
                                    {{ $role->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('role')
                            <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="sulu-button-primary">
                            Configurar acceso
                        </button>
                    </div>
                </form>
            </section>
        @endcan

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-white/5 px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-bold text-white">
                            Miembros de la organización
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ $memberships->where('active', true)->count() }} activos ·
                            {{ $memberships->where('active', false)->count() }} inactivos
                        </p>
                    </div>

                    <p class="text-xs text-slate-600">
                        No existe eliminación física desde esta superficie.
                    </p>
                </div>
            </div>

            <div class="divide-y divide-white/5">
                @forelse ($memberships as $membership)
                    @php
                        $memberUser = $membership->user;
                        $isSelf = $memberUser
                            && (int) $memberUser->id === (int) request()->user()->id;
                        $isDeleted = $memberUser?->trashed() ?? false;
                    @endphp

                    <article class="px-5 py-5 sm:px-6">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate font-semibold text-white">
                                        {{ $memberUser?->name ?? 'Cuenta no disponible' }}
                                    </h3>

                                    @if ($isSelf)
                                        <span class="rounded-full border border-cyan-400/20 bg-cyan-400/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-cyan-300">
                                            Vos
                                        </span>
                                    @endif

                                    <span class="rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider {{ $membership->active ? 'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' : 'border-slate-500/20 bg-slate-500/10 text-slate-500' }}">
                                        {{ $membership->active ? 'Activo' : 'Inactivo' }}
                                    </span>

                                    @if ($isDeleted)
                                        <span class="rounded-full border border-rose-400/20 bg-rose-400/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-rose-300">
                                            Cuenta eliminada
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-2 truncate text-sm text-slate-500">
                                    {{ $memberUser?->email ?? 'Sin email disponible' }}
                                </p>

                                <p class="mt-2 text-xs text-slate-600">
                                    Rol actual: <span class="font-semibold text-slate-400">{{ $membership->role->label() }}</span>
                                </p>
                            </div>

                            @can('manage-organization-members')
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <form
                                        method="POST"
                                        action="{{ route('organization-members.update-role', $membership->id) }}"
                                        class="flex items-center gap-2"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        <select
                                            name="role"
                                            class="sulu-input min-w-40"
                                            @disabled($isSelf || $isDeleted)
                                        >
                                            @foreach ($roles as $role)
                                                <option
                                                    value="{{ $role->value }}"
                                                    @selected($membership->role === $role)
                                                >
                                                    {{ $role->label() }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <button
                                            type="submit"
                                            class="sulu-button-secondary"
                                            @disabled($isSelf || $isDeleted)
                                        >
                                            Guardar rol
                                        </button>
                                    </form>

                                    <form
                                        method="POST"
                                        action="{{ route('organization-members.toggle-active', $membership->id) }}"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        <button
                                            type="submit"
                                            class="sulu-button-secondary"
                                            @disabled($isSelf || ($isDeleted && ! $membership->active))
                                        >
                                            {{ $membership->active ? 'Desactivar' : 'Reactivar' }}
                                        </button>
                                    </form>
                                </div>
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-slate-500">
                        No hay membresías registradas.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <article class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-amber-300">
                    Administrador
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Configuración sensible, usuarios, auditoría, ajustes y autorizaciones críticas.
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">
                    Operador
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Operación diaria: catálogo, ventas, compras, reparaciones e inventario dentro de sus reglas.
                </p>
            </article>

            <article class="sulu-card p-5">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">
                    Consulta
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Lectura operativa sin acciones de mutación.
                </p>
            </article>
        </section>
    </div>
</x-app-layout>
