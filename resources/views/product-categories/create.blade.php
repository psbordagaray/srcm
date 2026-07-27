<x-app-layout>

    <div class="max-w-4xl mx-auto space-y-8">

        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-cyan-400">
                Catálogo
            </p>

            <h1 class="mt-2 text-3xl font-bold text-white">
                Nueva categoría
            </h1>

            <p class="mt-2 text-slate-400">
                Creá una nueva categoría para organizar correctamente el catálogo de SRCM.
            </p>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-8">

            <form
                action="{{ route('product-categories.store') }}"
                method="POST"
                class="space-y-6"
            >

                @csrf

                <div>
                    <label
                        for="name"
                        class="block text-sm font-medium text-slate-300"
                    >
                        Nombre
                    </label>

                    <input
                        id="name"
                        name="name"
                        type="text"
                        value="{{ old('name') }}"
                        required
                        autofocus
                        maxlength="100"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
                    >

                    @error('name')
                        <p class="mt-2 text-sm text-red-400">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="icon"
                        class="block text-sm font-medium text-slate-300"
                    >
                        Icono
                    </label>

                    <input
                        id="icon"
                        name="icon"
                        type="text"
                        value="{{ old('icon') }}"
                        maxlength="60"
                        placeholder="tv, mobile, laptop..."
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
                    >

                    @error('icon')
                        <p class="mt-2 text-sm text-red-400">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="description"
                        class="block text-sm font-medium text-slate-300"
                    >
                        Descripción
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        rows="4"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
                    >{{ old('description') }}</textarea>

                    @error('description')
                        <p class="mt-2 text-sm text-red-400">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <label class="flex items-center gap-3">

                    <input
                        type="checkbox"
                        name="active"
                        value="1"
                        @checked(old('active', true))
                    >

                    <span class="text-slate-300">
                        Categoría activa
                    </span>

                </label>

                <div class="flex gap-4">

                    <button
                        type="submit"
                        class="rounded-xl bg-cyan-400 px-6 py-3 font-semibold text-slate-950 hover:bg-cyan-300"
                    >
                        Guardar categoría
                    </button>

                    <a
                        href="{{ route('product-categories.index') }}"
                        class="rounded-xl border border-slate-700 px-6 py-3 text-white"
                    >
                        Cancelar
                    </a>

                </div>

            </form>

        </div>

    </div>

</x-app-layout>