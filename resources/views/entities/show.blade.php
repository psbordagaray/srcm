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
            $type = \App\Enums\CompatibilityType::tryFrom(
                $compatibility->relationship_type
            );

            $relations->push([
                'compatibility' => $compatibility,
                'entity' => $compatibility->rightEntity,
                'incoming' => false,
                'label' => $type?->label(false)
                    ?? $compatibility->relationship_type,
            ]);
        }

        foreach ($entity->incomingCompatibilities as $compatibility) {
            $type = \App\Enums\CompatibilityType::tryFrom(
                $compatibility->relationship_type
            );

            $relations->push([
                'compatibility' => $compatibility,
                'entity' => $compatibility->leftEntity,
                'incoming' => true,
                'label' => $type?->label(true)
                    ?? $compatibility->relationship_type,
            ]);
        }

        $relations = $relations
            ->sort(function ($left, $right): int {
                $activeComparison =
                    (int) $right['compatibility']->active
                    <=> (int) $left['compatibility']->active;

                if ($activeComparison !== 0) {
                    return $activeComparison;
                }

                return $left['compatibility']->id
                    <=> $right['compatibility']->id;
            })
            ->values();

        $activeRelations = $relations->filter(
            fn ($relation) =>
                $relation['compatibility']->active
        );
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
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

        @error('compatibility_action')
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
                    {{ $activeRelations->count() }}
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
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 px-6 py-5">
                <h2 class="text-xl font-bold text-white">
                    Compatibilidades y relaciones
                </h2>

                <p class="mt-1 text-sm text-slate-400">
                    Vínculos comprobados con controles, modelos, productos, equipos y repuestos.
                </p>
            </div>

            <div class="grid gap-0 xl:grid-cols-[minmax(0,1.4fr)_minmax(360px,0.8fr)]">
                <div class="border-b border-slate-800 xl:border-b-0 xl:border-r">
                    @if ($relations->isEmpty())
                        <div class="px-6 py-16 text-center">
                            <p class="text-sm font-semibold text-white">
                                Sin relaciones registradas.
                            </p>

                            <p class="mt-2 text-sm text-slate-500">
                                Buscá otra entidad y vinculala desde esta ficha.
                            </p>
                        </div>
                    @else
                        <div class="divide-y divide-slate-800">
                            @foreach ($relations as $relation)
                                @php
                                    $compatibility =
                                        $relation['compatibility'];
                                    $relatedEntity =
                                        $relation['entity'];
                                @endphp

                                <article class="p-5 sm:p-6">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">
                                                    {{ $relation['label'] }}
                                                </span>

                                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                    Confianza {{ $compatibility->confidence }}%
                                                </span>

                                                @if ($compatibility->active)
                                                    <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                        Activa
                                                    </span>
                                                @else
                                                    <span class="rounded-full bg-slate-700 px-2.5 py-1 text-xs font-semibold text-slate-300">
                                                        Inactiva
                                                    </span>
                                                @endif
                                            </div>

                                            <h3 class="mt-3 text-lg font-bold text-white">
                                                {{ $relatedEntity?->name ?: 'Entidad relacionada' }}
                                            </h3>

                                            <p class="mt-1 text-sm text-slate-400">
                                                {{ $relatedEntity?->entityType?->name ?: 'Sin tipo' }}
                                            </p>

                                            @if ($compatibility->source)
                                                <p class="mt-3 text-sm text-slate-400">
                                                    <span class="font-semibold text-slate-300">
                                                        Fuente:
                                                    </span>
                                                    {{ $compatibility->source }}
                                                </p>
                                            @endif

                                            @if ($compatibility->evidence)
                                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-500">
                                                    {{ $compatibility->evidence }}
                                                </p>
                                            @endif

                                            @unless ($compatibility->active)
                                                <p class="mt-3 text-xs text-slate-500">
                                                    Conservada como historial; no aparece en el Explorador.
                                                </p>
                                            @endunless
                                        </div>

                                        <div class="flex shrink-0 flex-wrap gap-2">
                                            @if ($relatedEntity)
                                                <a
                                                    href="{{ route('entities.show', ['entity' => $relatedEntity->uuid]) }}"
                                                    class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300 transition hover:border-cyan-400 hover:text-cyan-300"
                                                >
                                                    Abrir ficha
                                                </a>
                                            @endif

                                            @can('manage-catalog')
                                                <form
                                                    method="POST"
                                                    action="{{ route('entities.compatibilities.toggle-active', [
                                                        'entity' => $entity->uuid,
                                                        'compatibility' => $compatibility->id,
                                                    ]) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg px-3 py-2 text-xs font-semibold transition {{ $compatibility->active
                                                            ? 'border border-amber-500/30 bg-amber-500/10 text-amber-300 hover:bg-amber-500/20'
                                                            : 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20' }}"
                                                    >
                                                        {{ $compatibility->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>

                @can('manage-catalog')
                    <div class="p-5 sm:p-6">
                        <h3 class="text-lg font-bold text-white">
                            Agregar relación
                        </h3>

                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            Buscá la otra entidad por código, nombre o UUID y confirmá el vínculo.
                        </p>

                        <div class="mt-5 space-y-3">
                            <label
                                for="compatibility-query"
                                class="block text-sm font-medium text-slate-300"
                            >
                                Buscar entidad relacionada
                            </label>

                            <div class="flex gap-2">
                                <input
                                    id="compatibility-query"
                                    type="search"
                                    autocomplete="off"
                                    placeholder="Ejemplo: 43LM6300"
                                    class="min-w-0 flex-1 rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                                >

                                <button
                                    id="compatibility-search-button"
                                    type="button"
                                    class="rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-4 py-2.5 text-sm font-semibold text-cyan-300 transition hover:bg-cyan-500/20"
                                >
                                    Buscar
                                </button>
                            </div>

                            <div
                                id="compatibility-search-result"
                                class="hidden"
                            ></div>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('entities.compatibilities.store', ['entity' => $entity->uuid]) }}"
                            class="mt-6 space-y-5"
                        >
                            @csrf

                            <input
                                id="related_entity_uuid"
                                name="related_entity_uuid"
                                type="hidden"
                                value="{{ old('related_entity_uuid') }}"
                            >

                            <div
                                id="selected-related-entity"
                                class="{{ old('related_entity_uuid') ? '' : 'hidden' }} rounded-xl border border-cyan-500/30 bg-cyan-500/10 p-4"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">
                                    Entidad seleccionada
                                </p>

                                <p
                                    id="selected-related-entity-name"
                                    class="mt-2 font-semibold text-white"
                                >
                                    {{ old('related_entity_uuid') ?: 'Sin seleccionar' }}
                                </p>

                                <p
                                    id="selected-related-entity-type"
                                    class="mt-1 text-xs text-slate-400"
                                ></p>
                            </div>

                            @error('related_entity_uuid')
                                <p class="text-sm text-red-400">
                                    {{ $message }}
                                </p>
                            @enderror

                            <div>
                                <label
                                    for="relationship_type"
                                    class="block text-sm font-medium text-slate-300"
                                >
                                    Tipo de relación
                                </label>

                                <select
                                    id="relationship_type"
                                    name="relationship_type"
                                    required
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                                >
                                    <option value="">Seleccionar...</option>

                                    @foreach ($compatibilityTypes as $compatibilityType)
                                        <option
                                            value="{{ $compatibilityType->value }}"
                                            @selected(
                                                old('relationship_type', 'compatible_with')
                                                === $compatibilityType->value
                                            )
                                        >
                                            {{ $compatibilityType->outgoingLabel() }}
                                        </option>
                                    @endforeach
                                </select>

                                @error('relationship_type')
                                    <p class="mt-2 text-sm text-red-400">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div>
                                <label
                                    for="confidence"
                                    class="block text-sm font-medium text-slate-300"
                                >
                                    Confianza
                                </label>

                                <div class="mt-2 flex items-center gap-3">
                                    <input
                                        id="confidence"
                                        name="confidence"
                                        type="range"
                                        min="1"
                                        max="100"
                                        value="{{ old('confidence', 80) }}"
                                        class="min-w-0 flex-1 accent-cyan-400"
                                    >

                                    <output
                                        id="confidence-output"
                                        for="confidence"
                                        class="w-14 rounded-lg border border-slate-700 bg-slate-950 px-2 py-2 text-center text-sm font-bold text-cyan-300"
                                    >
                                        {{ old('confidence', 80) }}%
                                    </output>
                                </div>

                                @error('confidence')
                                    <p class="mt-2 text-sm text-red-400">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div>
                                <label
                                    for="source"
                                    class="block text-sm font-medium text-slate-300"
                                >
                                    Fuente
                                </label>

                                <input
                                    id="source"
                                    name="source"
                                    type="text"
                                    value="{{ old('source') }}"
                                    maxlength="255"
                                    placeholder="Ejemplo: prueba en mostrador"
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                                >

                                @error('source')
                                    <p class="mt-2 text-sm text-red-400">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div>
                                <label
                                    for="evidence"
                                    class="block text-sm font-medium text-slate-300"
                                >
                                    Evidencia
                                </label>

                                <textarea
                                    id="evidence"
                                    name="evidence"
                                    rows="4"
                                    maxlength="2000"
                                    placeholder="Funciones verificadas, observaciones o límites conocidos."
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                                >{{ old('evidence') }}</textarea>

                                @error('evidence')
                                    <p class="mt-2 text-sm text-red-400">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <button
                                id="compatibility-submit"
                                type="submit"
                                @disabled(! old('related_entity_uuid'))
                                class="inline-flex w-full items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                Guardar compatibilidad
                            </button>
                        </form>
                    </div>
                @endcan
            </div>
        </section>
    </div>

    @can('manage-catalog')
        <script>
            const currentEntityUuid = @json($entity->uuid);
            const knowledgeUrlTemplate = @json(
                route('knowledge.show', ['query' => '__QUERY__'])
            );

            const compatibilityQuery = document.getElementById(
                'compatibility-query'
            );
            const compatibilitySearchButton = document.getElementById(
                'compatibility-search-button'
            );
            const compatibilitySearchResult = document.getElementById(
                'compatibility-search-result'
            );
            const relatedEntityUuid = document.getElementById(
                'related_entity_uuid'
            );
            const selectedRelatedEntity = document.getElementById(
                'selected-related-entity'
            );
            const selectedRelatedEntityName = document.getElementById(
                'selected-related-entity-name'
            );
            const selectedRelatedEntityType = document.getElementById(
                'selected-related-entity-type'
            );
            const compatibilitySubmit = document.getElementById(
                'compatibility-submit'
            );
            const confidence = document.getElementById('confidence');
            const confidenceOutput = document.getElementById(
                'confidence-output'
            );

            confidence.addEventListener('input', () => {
                confidenceOutput.value = `${confidence.value}%`;
                confidenceOutput.textContent = `${confidence.value}%`;
            });

            compatibilitySearchButton.addEventListener(
                'click',
                searchRelatedEntity
            );

            compatibilityQuery.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                searchRelatedEntity();
            });

            compatibilitySearchResult.addEventListener(
                'click',
                (event) => {
                    const button = event.target.closest(
                        '[data-related-entity-uuid]'
                    );

                    if (! button) {
                        return;
                    }

                    selectRelatedEntity({
                        uuid: button.dataset.relatedEntityUuid,
                        name: button.dataset.relatedEntityName,
                        type: button.dataset.relatedEntityType,
                    });
                }
            );

            async function searchRelatedEntity() {
                const query = compatibilityQuery.value.trim();

                if (! query) {
                    return;
                }

                compatibilitySearchResult.classList.remove('hidden');
                compatibilitySearchResult.innerHTML =
                    '<p class="text-sm text-slate-400">Buscando...</p>';

                try {
                    const url = knowledgeUrlTemplate.replace(
                        '__QUERY__',
                        encodeURIComponent(query)
                    );

                    const response = await fetch(url, {
                        headers: {
                            Accept: 'application/json',
                        },
                    });

                    const data = await response.json();

                    if (data.status === 'resolved') {
                        renderRelatedCandidates([
                            {
                                uuid: data.entity.uuid,
                                name: data.entity.name,
                                type: data.entity.entity_type?.name,
                                matched_value: data.query,
                            },
                        ]);

                        return;
                    }

                    if (data.status === 'candidates') {
                        renderRelatedCandidates(
                            data.candidates ?? []
                        );

                        return;
                    }

                    compatibilitySearchResult.innerHTML = `
                        <p class="text-sm text-slate-500">
                            No se encontraron entidades para
                            “${escapeCompatibilityHtml(query)}”.
                        </p>
                    `;
                } catch (error) {
                    compatibilitySearchResult.innerHTML = `
                        <p class="text-sm text-red-300">
                            No se pudo completar la búsqueda.
                        </p>
                    `;
                }
            }

            function renderRelatedCandidates(candidates) {
                const available = candidates.filter(
                    (candidate) =>
                        candidate.uuid !== currentEntityUuid
                );

                if (! available.length) {
                    compatibilitySearchResult.innerHTML = `
                        <p class="text-sm text-amber-300">
                            No hay otra entidad seleccionable en este resultado.
                        </p>
                    `;

                    return;
                }

                compatibilitySearchResult.innerHTML = `
                    <div class="space-y-2">
                        ${available.map((candidate) => `
                            <button
                                type="button"
                                data-related-entity-uuid="${escapeCompatibilityHtml(candidate.uuid)}"
                                data-related-entity-name="${escapeCompatibilityHtml(candidate.name ?? candidate.uuid)}"
                                data-related-entity-type="${escapeCompatibilityHtml(candidate.type ?? 'Sin tipo')}"
                                class="block w-full rounded-xl border border-slate-700 bg-slate-950/70 p-3 text-left transition hover:border-cyan-400"
                            >
                                <span class="block font-semibold text-white">
                                    ${escapeCompatibilityHtml(candidate.name ?? candidate.uuid)}
                                </span>

                                <span class="mt-1 block text-xs text-slate-400">
                                    ${escapeCompatibilityHtml(candidate.type ?? 'Sin tipo')}
                                </span>

                                <span class="mt-2 block text-xs text-slate-500">
                                    Coincidió con:
                                    ${escapeCompatibilityHtml(candidate.matched_value ?? '')}
                                </span>
                            </button>
                        `).join('')}
                    </div>
                `;
            }

            function selectRelatedEntity(entity) {
                relatedEntityUuid.value = entity.uuid;
                selectedRelatedEntityName.textContent =
                    entity.name ?? entity.uuid;
                selectedRelatedEntityType.textContent =
                    entity.type ?? 'Sin tipo';

                selectedRelatedEntity.classList.remove('hidden');
                compatibilitySubmit.disabled = false;
                compatibilitySearchResult.classList.add('hidden');
            }

            function escapeCompatibilityHtml(value) {
                return String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }
        </script>
    @endcan
</x-app-layout>
