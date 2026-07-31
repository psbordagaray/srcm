<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Configuración privada
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Editar organización
            </h1>

            <p class="mt-2 text-sm leading-6 text-slate-400">
                Estos datos identifican al negocio que utiliza SRCM.
                No representan a un proveedor ni a una marca.
            </p>
        </div>

        <form
            method="POST"
            action="{{ route('organization.update') }}"
            class="space-y-6 rounded-2xl border border-slate-800 bg-slate-900/80 p-6"
        >
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="mb-2 block text-sm font-semibold text-slate-200">
                    Nombre
                </label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    required
                    maxlength="255"
                    value="{{ old('name', $organization->name) }}"
                    class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                >
                @error('name')
                    <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <label for="tax_id" class="mb-2 block text-sm font-semibold text-slate-200">
                        CUIT / identificación fiscal
                    </label>
                    <input
                        id="tax_id"
                        name="tax_id"
                        type="text"
                        maxlength="64"
                        value="{{ old('tax_id', $organization->tax_id) }}"
                        class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                    @error('tax_id')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="mb-2 block text-sm font-semibold text-slate-200">
                        Correo
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="255"
                        value="{{ old('email', $organization->email) }}"
                        class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                    @error('email')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <label for="phone" class="mb-2 block text-sm font-semibold text-slate-200">
                        Teléfono
                    </label>
                    <input
                        id="phone"
                        name="phone"
                        type="text"
                        maxlength="80"
                        value="{{ old('phone', $organization->phone) }}"
                        class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                    @error('phone')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="website" class="mb-2 block text-sm font-semibold text-slate-200">
                        Sitio web
                    </label>
                    <input
                        id="website"
                        name="website"
                        type="text"
                        maxlength="2048"
                        value="{{ old('website', $organization->website) }}"
                        placeholder="sulutv.com.ar"
                        class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >
                    @error('website')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-4 text-xs leading-5 text-amber-100">
                La organización no puede eliminarse ni inactivarse desde esta pantalla.
                Las membresías y nuevas organizaciones se administrarán en un módulo posterior.
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
                <a
                    href="{{ route('organization.show') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                >
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Guardar cambios
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
