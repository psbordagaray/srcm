<section class="space-y-5">
    <header>
        <h2 class="text-lg font-semibold text-red-200">
            Eliminar cuenta
        </h2>

        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-400">
            Esta acción elimina permanentemente tu cuenta y no puede deshacerse.
            Utilizala solamente cuando estés completamente seguro.
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
        class="!rounded-xl !px-5 !py-2.5 !text-sm !normal-case !tracking-normal"
    >
        Eliminar mi cuenta
    </x-danger-button>

    <x-modal
        name="confirm-user-deletion"
        :show="$errors->userDeletion->isNotEmpty()"
        content-classes="border border-slate-700 bg-slate-900"
        focusable
    >
        <form
            method="post"
            action="{{ route('profile.destroy') }}"
            class="p-6"
        >
            @csrf
            @method('delete')

            <h2 class="text-lg font-semibold text-white">
                ¿Eliminar definitivamente tu cuenta?
            </h2>

            <p class="mt-2 text-sm leading-6 text-slate-400">
                Ingresá tu contraseña para confirmar. Todos los datos asociados
                a esta cuenta serán eliminados permanentemente.
            </p>

            <div class="mt-6">
                <x-input-label
                    for="delete_account_password"
                    value="Contraseña"
                    variant="dark"
                />

                <x-text-input
                    id="delete_account_password"
                    name="password"
                    type="password"
                    variant="dark"
                    class="mt-2 block w-full"
                    placeholder="Ingresá tu contraseña"
                    autocomplete="current-password"
                />

                <x-input-error
                    :messages="$errors->userDeletion->get('password')"
                    class="mt-2 !text-red-300"
                />
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    x-on:click="$dispatch('close')"
                    class="rounded-xl border border-slate-700 px-5 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500/40"
                >
                    Sí, eliminar cuenta
                </button>
            </div>
        </form>
    </x-modal>
</section>
