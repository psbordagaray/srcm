<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Motor de conocimiento
            </p>

            <h1 class="mt-2 text-3xl font-bold text-white">
                Nueva entidad
            </h1>

            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
                Registrá el objeto y su primer identificador en una sola operación.
                SRCM lo dejará activo, marcará el código como principal y lo abrirá
                inmediatamente en el Explorador.
            </p>
        </div>

        @php
            $canSubmit = $entityTypes->isNotEmpty()
                && $identifierTypes->isNotEmpty();
        @endphp

        @unless ($canSubmit)
            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-5 py-4 text-sm text-amber-200">
                No hay suficientes tipos activos para crear entidades.
                Ejecutá la carga de fundación de conocimiento antes de continuar.
            </div>
        @endunless

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10 sm:p-8">
            <form
                method="POST"
                action="{{ route('entities.store') }}"
                class="space-y-7"
            >
                @csrf

                <div>
                    <label
                        for="name"
                        class="block text-sm font-medium text-slate-300"
                    >
                        Nombre descriptivo
                    </label>

                    <input
                        id="name"
                        name="name"
                        type="text"
                        value="{{ old('name') }}"
                        required
                        autofocus
                        maxlength="255"
                        placeholder="Ejemplo: Control remoto Samsung Smart"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        Usá un nombre que una persona pueda reconocer rápidamente.
                    </p>

                    @error('name')
                        <p class="mt-2 text-sm text-red-400">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <label
                            for="entity_type_id"
                            class="block text-sm font-medium text-slate-300"
                        >
                            Tipo de entidad
                        </label>

                        <select
                            id="entity_type_id"
                            name="entity_type_id"
                            required
                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                        >
                            <option value="">Seleccionar...</option>

                            @foreach ($entityTypes as $entityType)
                                <option
                                    value="{{ $entityType->id }}"
                                    @selected(
                                        (string) old('entity_type_id')
                                        === (string) $entityType->id
                                    )
                                >
                                    {{ $entityType->name }}
                                </option>
                            @endforeach
                        </select>

                        @error('entity_type_id')
                            <p class="mt-2 text-sm text-red-400">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="identifier_type_id"
                            class="block text-sm font-medium text-slate-300"
                        >
                            Tipo de identificador
                        </label>

                        <select
                            id="identifier_type_id"
                            name="identifier_type_id"
                            required
                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                        >
                            <option value="">Seleccionar...</option>

                            @foreach ($identifierTypes as $identifierType)
                                <option
                                    value="{{ $identifierType->id }}"
                                    @selected(
                                        (string) old('identifier_type_id')
                                        === (string) $identifierType->id
                                    )
                                >
                                    {{ $identifierType->name }}
                                    @if ($identifierType->is_unique)
                                        — único
                                    @endif
                                </option>
                            @endforeach
                        </select>

                        @error('identifier_type_id')
                            <p class="mt-2 text-sm text-red-400">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label
                        for="identifier_value"
                        class="block text-sm font-medium text-slate-300"
                    >
                        Identificador inicial
                    </label>

                    <input
                        id="identifier_value"
                        name="identifier_value"
                        type="text"
                        value="{{ old('identifier_value') }}"
                        required
                        maxlength="255"
                        autocapitalize="none"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Ejemplo: AKB75095308"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white placeholder:font-sans placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >

                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        Podés escribirlo con espacios alrededor o con cualquier
                        combinación de mayúsculas y minúsculas. SRCM lo normaliza.
                    </p>

                    @error('identifier_value')
                        <p class="mt-2 text-sm text-red-400">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">
                            Activa
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            La entidad nace disponible para búsquedas.
                        </p>
                    </div>

                    <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/10 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">
                            Principal
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            El primer identificador queda marcado como principal.
                        </p>
                    </div>

                    <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-amber-300">
                            Atómica
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Si el código falla, no queda una entidad incompleta.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
                    <a
                        href="{{ route('knowledge.explorer') }}"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-5 py-2.5 font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        @disabled(! $canSubmit)
                        class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-5 py-2.5 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Crear y abrir en el Explorador
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>
