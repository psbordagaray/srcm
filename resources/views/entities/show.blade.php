<x-app-layout>
    @php
        $primaryIdentifier = $entity->identifiers
            ->first(
                fn ($identifier) =>
                    $identifier->active
                    && $identifier->is_primary
            );

        $activeIdentifiers = $entity->identifiers
            ->where('active', true);

        $relations = collect();

        foreach ($entity->outgoingCompatibilities as $compatibility) {
            $relations->push([
                'entity' => $compatibility->rightEntity,
                'relationship_type' => $compatibility->relationship_type,
                'confidence' => $compatibility->confidence,
                'source' => $compatibility->source,
            ]);
        }

        foreach ($entity->incomingCompatibilities as $compatibility) {
            $relations->push([
                'entity' => $compatibility->leftEntity,
                'relationship_type' => $compatibility->relationship_type,
                'confidence' => $compatibility->confidence,
                'source' => $compatibility->source,
            ]);
        }
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    Ficha de conocimiento
                </p>

                <h1 class="mt-2 text-3xl font-bold text-white">
                    {{ $entity->name }}
                </h1>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-cyan-500/10 px-3 py-1 text-xs font-semibold text-cyan-300">
                        {{ $entity->entityType?->name ?: 'Sin tipo' }}
                    </span>

                    @if ($entity->active)
                        <span class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-300">
                            Entidad activa
                        </span>
                    @else
                        <span class="rounded-full bg-slate-700 px-3 py-1 text-xs font-semibold text-slate-300">
                            Entidad inactiva
                        </span>
                    @endif
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <a
                    href="{{ route('knowledge.explorer') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                >
                    Volver al Explorador
                </a>

                @if ($primaryIdentifier)
                    <a
                        href="{{ route('knowledge.explorer', ['query' => $primaryIdentifier->value]) }}"
                        class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Buscar código principal
                    </a>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        @error('identifier_action')
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $message }}
            </div>
        @enderror

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    UUID
                </p>
                <p class="mt-2 break-all font-mono text-sm text-slate-200">
                    {{ $entity->uuid }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Identificadores activos
                </p>
                <p class="mt-2 text-2xl font-bold text-white">
                    {{ $activeIdentifiers->count() }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Identificador principal
                </p>
                <p class="mt-2 break-words font-mono text-sm font-semibold text-cyan-300">
                    {{ $primaryIdentifier?->value ?: 'Sin definir' }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Relaciones activas
                </p>
                <p class="mt-2 text-2xl font-bold text-white">
                    {{ $relations->count() }}
                </p>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.8fr)]">
            <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
                <div class="border-b border-slate-800 px-6 py-5">
                    <h2 class="text-xl font-bold text-white">
                        Identificadores
                    </h2>

                    <p class="mt-1 text-sm text-slate-400">
                        Códigos principales, alternativos, modelos, series y referencias históricas.
                    </p>
                </div>

                @if ($entity->identifiers->isEmpty())
                    <div class="px-6 py-14 text-center text-sm text-slate-500">
                        Esta entidad todavía no posee identificadores.
                    </div>
                @else
                    <div class="divide-y divide-slate-800">
                        @foreach ($entity->identifiers as $identifier)
                            <article class="p-5 sm:p-6">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="break-all font-mono text-base font-bold text-white">
                                                {{ $identifier->value }}
                                            </span>

                                            @if ($identifier->is_primary)
                                                <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">
                                                    Principal
                                                </span>
                                            @endif

                                            @if ($identifier->active)
                                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                    Activo
                                                </span>
                                            @else
                                                <span class="rounded-full bg-slate-700 px-2.5 py-1 text-xs font-semibold text-slate-300">
                                                    Inactivo
                                                </span>
                                            @endif

                                            @if ($identifier->identifierType?->is_unique)
                                                <span class="rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-300">
                                                    Único
                                                </span>
                                            @endif
                                        </div>

                                        <p class="mt-2 text-sm text-slate-400">
                                            {{ $identifier->identifierType?->name ?: 'Sin tipo' }}
                                        </p>

                                        @unless ($identifier->active)
                                            <p class="mt-2 text-xs text-slate-500">
                                                Conservado como historial; no participa de las búsquedas.
                                            </p>
                                        @endunless
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @if ($identifier->active)
                                            <a
                                                href="{{ route('knowledge.explorer', ['query' => $identifier->value]) }}"
                                                class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300 transition hover:border-cyan-400 hover:text-cyan-300"
                                            >
                                                Buscar
                                            </a>
                                        @endif

                                        @can('manage-catalog')
                                            @if ($identifier->active && ! $identifier->is_primary)
                                                <form
                                                    method="POST"
                                                    action="{{ route('entities.identifiers.make-primary', [
                                                        'entity' => $entity->uuid,
                                                        'identifier' => $identifier->id,
                                                    ]) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-2 text-xs font-semibold text-cyan-300 transition hover:bg-cyan-500/20"
                                                    >
                                                        Hacer principal
                                                    </button>
                                                </form>
                                            @endif

                                            @unless ($identifier->is_primary)
                                                <form
                                                    method="POST"
                                                    action="{{ route('entities.identifiers.toggle-active', [
                                                        'entity' => $entity->uuid,
                                                        'identifier' => $identifier->id,
                                                    ]) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg px-3 py-2 text-xs font-semibold transition {{ $identifier->active
                                                            ? 'border border-amber-500/30 bg-amber-500/10 text-amber-300 hover:bg-amber-500/20'
                                                            : 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20' }}"
                                                    >
                                                        {{ $identifier->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            @endunless
                                        @endcan
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <div class="space-y-6">
                @can('manage-catalog')
                    <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
                        <h2 class="text-lg font-bold text-white">
                            Agregar identificador
                        </h2>

                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            Incorporá un código alternativo, modelo, número de parte, serie, IMEI, código de barras o QR.
                        </p>

                        @if ($identifierTypes->isEmpty())
                            <div class="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                                No hay tipos de identificador activos.
                            </div>
                        @else
                            <form
                                method="POST"
                                action="{{ route('entities.identifiers.store', ['entity' => $entity->uuid]) }}"
                                class="mt-5 space-y-5"
                            >
                                @csrf

                                <div>
                                    <label
                                        for="identifier_type_id"
                                        class="block text-sm font-medium text-slate-300"
                                    >
                                        Tipo
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

                                <div>
                                    <label
                                        for="identifier_value"
                                        class="block text-sm font-medium text-slate-300"
                                    >
                                        Código o valor
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
                                        SRCM elimina espacios exteriores, normaliza la búsqueda y controla duplicados.
                                    </p>

                                    @error('identifier_value')
                                        <p class="mt-2 text-sm text-red-400">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>

                                <button
                                    type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                                >
                                    Agregar identificador
                                </button>
                            </form>
                        @endif
                    </section>
                @endcan

                <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
                    <h2 class="text-lg font-bold text-white">
                        Relaciones
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        Compatibilidades y vínculos activos de esta entidad.
                    </p>

                    @if ($relations->isEmpty())
                        <div class="mt-5 rounded-xl border border-dashed border-slate-700 px-4 py-6 text-center">
                            <p class="text-sm text-slate-500">
                                Sin relaciones registradas.
                            </p>

                            <p class="mt-2 text-xs text-slate-600">
                                La administración visual de compatibilidades será el próximo núcleo operativo.
                            </p>
                        </div>
                    @else
                        <div class="mt-5 space-y-3">
                            @foreach ($relations as $relation)
                                <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                                    <p class="font-semibold text-white">
                                        {{ $relation['entity']?->name ?: 'Entidad relacionada' }}
                                    </p>

                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $relation['entity']?->entityType?->name ?: 'Sin tipo' }}
                                    </p>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs text-cyan-300">
                                            {{ $relation['relationship_type'] }}
                                        </span>

                                        <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs text-emerald-300">
                                            Confianza {{ $relation['confidence'] }}%
                                        </span>
                                    </div>

                                    @if ($relation['source'])
                                        <p class="mt-3 text-xs text-slate-500">
                                            Fuente: {{ $relation['source'] }}
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
