<section>
    <header>
        <h2 class="text-lg font-semibold text-white">
            Datos personales
        </h2>

        <p class="mt-1 text-sm leading-6 text-slate-400">
            Actualizá tu nombre y el correo utilizado para ingresar a SRCM.
        </p>
    </header>

    <form
        id="send-verification"
        method="post"
        action="{{ route('verification.send') }}"
    >
        @csrf
    </form>

    <form
        method="post"
        action="{{ route('profile.update') }}"
        class="mt-6 space-y-5"
    >
        @csrf
        @method('patch')

        <div>
            <x-input-label
                for="name"
                value="Nombre"
                variant="dark"
            />

            <x-text-input
                id="name"
                name="name"
                type="text"
                variant="dark"
                class="mt-2 block w-full"
                :value="old('name', $user->name)"
                required
                autofocus
                autocomplete="name"
            />

            <x-input-error
                class="mt-2 !text-red-300"
                :messages="$errors->get('name')"
            />
        </div>

        <div>
            <x-input-label
                for="email"
                value="Correo electrónico"
                variant="dark"
            />

            <x-text-input
                id="email"
                name="email"
                type="email"
                variant="dark"
                class="mt-2 block w-full"
                :value="old('email', $user->email)"
                required
                autocomplete="username"
            />

            <x-input-error
                class="mt-2 !text-red-300"
                :messages="$errors->get('email')"
            />

            @if (
                $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail
                && ! $user->hasVerifiedEmail()
            )
                <div class="mt-4 rounded-xl border border-amber-400/20 bg-amber-400/10 p-4">
                    <p class="text-sm text-amber-100">
                        Tu correo todavía no está verificado.

                        <button
                            form="send-verification"
                            class="font-semibold text-amber-300 underline decoration-amber-300/40 underline-offset-4 transition hover:text-amber-200"
                        >
                            Reenviar verificación
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 text-sm font-medium text-emerald-300">
                            Enviamos un nuevo enlace de verificación.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <button
                type="submit"
                class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-400/40"
            >
                Guardar datos
            </button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm font-medium text-emerald-300"
                >
                    Datos guardados.
                </p>
            @endif
        </div>
    </form>
</section>
