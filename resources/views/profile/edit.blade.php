<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Cuenta
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Mi perfil
            </h1>

            <p class="mt-2 max-w-3xl text-sm text-slate-400">
                Administrá tus datos personales, contraseña y seguridad de acceso.
            </p>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
                @include('profile.partials.update-profile-information-form')
            </section>

            <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
                @include('profile.partials.update-password-form')
            </section>
        </div>

        <section class="rounded-2xl border border-red-500/20 bg-red-500/5 p-6 shadow-xl shadow-black/10">
            @include('profile.partials.delete-user-form')
        </section>
    </div>
</x-app-layout>
