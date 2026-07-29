<section>
    <header>
        <h2 class="text-lg font-semibold text-white">
            Contraseña
        </h2>

        <p class="mt-1 text-sm leading-6 text-slate-400">
            Usá una contraseña larga y exclusiva para proteger tu cuenta.
        </p>
    </header>

    <form
        method="post"
        action="{{ route('password.update') }}"
        class="mt-6 space-y-5"
    >
        @csrf
        @method('put')

        <div>
            <x-input-label
                for="update_password_current_password"
                value="Contraseña actual"
                variant="dark"
            />

            <x-text-input
                id="update_password_current_password"
                name="current_password"
                type="password"
                variant="dark"
                class="mt-2 block w-full"
                autocomplete="current-password"
            />

            <x-input-error
                :messages="$errors->updatePassword->get('current_password')"
                class="mt-2 !text-red-300"
            />
        </div>

        <div>
            <x-input-label
                for="update_password_password"
                value="Nueva contraseña"
                variant="dark"
            />

            <x-text-input
                id="update_password_password"
                name="password"
                type="password"
                variant="dark"
                class="mt-2 block w-full"
                autocomplete="new-password"
            />

            <x-input-error
                :messages="$errors->updatePassword->get('password')"
                class="mt-2 !text-red-300"
            />
        </div>

        <div>
            <x-input-label
                for="update_password_password_confirmation"
                value="Confirmar contraseña"
                variant="dark"
            />

            <x-text-input
                id="update_password_password_confirmation"
                name="password_confirmation"
                type="password"
                variant="dark"
                class="mt-2 block w-full"
                autocomplete="new-password"
            />

            <x-input-error
                :messages="$errors->updatePassword->get('password_confirmation')"
                class="mt-2 !text-red-300"
            />
        </div>

        <div class="flex items-center gap-4">
            <button
                type="submit"
                class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-400/40"
            >
                Actualizar contraseña
            </button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm font-medium text-emerald-300"
                >
                    Contraseña actualizada.
                </p>
            @endif
        </div>
    </form>
</section>
