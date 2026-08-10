<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Knowledge Engine
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Conocimiento técnico
            </h1>

            <p class="mt-2 text-sm text-slate-400">
                Identidad técnica, códigos, modelos, relaciones y compatibilidades.
            </p>
        </div>

            @can('manage-catalog')
                <a
                    href="{{ route('entities.create') }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nueva entidad
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
            <form
                id="knowledge-search-form"
                class="flex flex-col gap-3 sm:flex-row"
            >
                <input
                    id="knowledge-query"
                    name="query"
                    value="{{ $initialQuery ?? '' }}"
                    data-auto-search="{{ filled($initialQuery ?? null) ? 'true' : 'false' }}"
                    type="search"
                    required
                    autofocus
                    placeholder="Ej.: EN2BC27, EN, 27, 43..."
                    class="min-w-0 flex-1 rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                >

                <button
                    type="submit"
                    class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Buscar
                </button>
            </form>
        </section>

        <section
            id="knowledge-result"
            class="hidden rounded-2xl border border-slate-800 bg-slate-900/80 p-6"
        ></section>
    </div>

    <script>
        const form = document.getElementById('knowledge-search-form');
        const input = document.getElementById('knowledge-query');
        const result = document.getElementById('knowledge-result');
        const entityDetailUrlTemplate = @json(
            route('entities.show', ['entity' => '__ENTITY_UUID__'])
        );

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            await searchKnowledge(input.value);
        });

        result.addEventListener('click', async (event) => {
            const candidateButton = event.target.closest(
                '[data-candidate-uuid]'
            );

            if (!candidateButton) {
                return;
            }

            const uuid = candidateButton.dataset.candidateUuid;

            input.value = candidateButton.dataset.candidateName ?? uuid;

            await searchKnowledge(uuid);
        });

        async function searchKnowledge(rawQuery) {
            const query = rawQuery.trim();

            if (!query) {
                return;
            }

            result.classList.remove('hidden');
            result.innerHTML = '<p class="text-slate-400">Buscando...</p>';

            try {
                const response = await fetch(
                    `/knowledge/${encodeURIComponent(query)}`,
                    {
                        headers: {
                            Accept: 'application/json',
                        },
                    }
                );

                const data = await response.json();

                if (data.status === 'resolved') {
                    renderResolvedEntity(data);
                    return;
                }

                if (data.status === 'candidates') {
                    renderCandidates(data);
                    return;
                }

                renderNotFound(data.query ?? query);
            } catch (error) {
                result.innerHTML = `
                    <h2 class="text-lg font-semibold text-red-300">
                        No se pudo completar la búsqueda
                    </h2>

                    <p class="mt-2 text-sm text-slate-400">
                        Revisá la conexión y volvé a intentarlo.
                    </p>
                `;
            }
        }

        function renderCandidates(data) {
            const candidates = data.candidates ?? [];

            result.innerHTML = `
                <div class="space-y-5">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">
                            Confirmación necesaria
                        </p>

                        <h2 class="mt-1 text-2xl font-bold text-white">
                            Posibles resultados
                        </h2>

                        <p class="mt-2 text-sm text-slate-400">
                            Encontramos ${candidates.length}
                            ${candidates.length === 1 ? 'candidato' : 'candidatos'}
                            para “${escapeHtml(data.query)}”.
                            Elegí el objeto correcto.
                        </p>
                    </div>

                    <div class="space-y-3">
                        ${candidates.map((candidate, index) => `
                            <button
                                type="button"
                                data-candidate-uuid="${escapeHtml(candidate.uuid)}"
                                data-candidate-name="${escapeHtml(candidate.name ?? candidate.uuid)}"
                                class="block w-full rounded-xl border border-slate-800 bg-slate-950/60 p-4 text-left transition hover:border-cyan-400 hover:bg-slate-950"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="font-semibold text-white">
                                            ${index + 1}. ${escapeHtml(candidate.name ?? candidate.uuid)}
                                        </div>

                                        <div class="mt-1 text-sm text-slate-400">
                                            ${escapeHtml(candidate.type ?? 'Sin tipo')}
                                        </div>
                                    </div>

                                    <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">
                                        Coincidencia ${escapeHtml(String(candidate.score))}%
                                    </span>
                                </div>

                                <div class="mt-3 text-sm text-slate-400">
                                    Coincidió con:
                                    <strong class="text-slate-200">
                                        ${escapeHtml(candidate.matched_value ?? '')}
                                    </strong>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    ${(candidate.identifiers ?? []).map((identifier) => `
                                        <span class="rounded-lg border border-slate-800 px-2.5 py-1 text-xs text-slate-400">
                                            ${escapeHtml(identifier.value)}
                                        </span>
                                    `).join('')}
                                </div>
                            </button>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        function renderResolvedEntity(data) {
            const entity = data.entity;
            const identifiers = entity.identifiers ?? [];
            const outgoing = data.compatibilities?.outgoing ?? [];
            const incoming = data.compatibilities?.incoming ?? [];

            result.innerHTML = `
                <div class="space-y-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                                Identidad confirmada
                            </p>

                            <h2 class="mt-1 text-2xl font-bold text-white">
                                ${escapeHtml(entity.name ?? entity.uuid)}
                            </h2>

                            <p class="mt-2 text-sm text-slate-400">
                                ${escapeHtml(entity.entity_type?.name ?? 'Sin tipo')}
                            </p>
                        </div>

                        <a
                            href="${escapeHtml(entityDetailUrl(entity.uuid))}"
                            class="inline-flex shrink-0 items-center justify-center rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-4 py-2.5 text-sm font-semibold text-cyan-300 transition hover:bg-cyan-500/20"
                        >
                            Abrir ficha
                        </a>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400">
                            Identificadores
                        </h3>

                        <div class="mt-3 space-y-2">
                            ${
                                identifiers.length
                                    ? identifiers.map((identifier) => `
                                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3">
                                            <div class="font-semibold text-white">
                                                ${escapeHtml(identifier.value)}
                                            </div>

                                            <div class="mt-1 text-xs text-slate-500">
                                                ${escapeHtml(identifier.identifier_type?.name ?? 'Sin tipo')}
                                            </div>
                                        </div>
                                    `).join('')
                                    : '<p class="text-sm text-slate-500">Sin identificadores.</p>'
                            }
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-400">
                            Relaciones
                        </h3>

                        <div class="mt-3 space-y-3">
                            ${renderRelations(outgoing, 'right_entity')}
                            ${renderRelations(incoming, 'left_entity')}

                            ${
                                !outgoing.length && !incoming.length
                                    ? '<p class="text-sm text-slate-500">Sin relaciones registradas.</p>'
                                    : ''
                            }
                        </div>
                    </div>
                </div>
            `;
        }

        function renderRelations(relations, entityKey) {
            return relations.map((relation) => {
                const relatedEntity = relation[entityKey];

                return `
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3">
                        <div class="font-semibold text-white">
                            ${escapeHtml(relatedEntity?.name ?? 'Entidad relacionada')}
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-cyan-300">
                                ${escapeHtml(relation.relationship_type)}
                            </span>

                            <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-emerald-300">
                                Confianza ${escapeHtml(String(relation.confidence))}%
                            </span>
                        </div>

                        ${
                            relation.source
                                ? `<p class="mt-3 text-sm text-slate-400">Fuente: ${escapeHtml(relation.source)}</p>`
                                : ''
                        }

                        ${
                            relation.evidence
                                ? `<p class="mt-1 text-sm text-slate-500">${escapeHtml(relation.evidence)}</p>`
                                : ''
                        }
                    </div>
                `;
            }).join('');
        }

        function renderNotFound(query) {
            result.innerHTML = `
                <h2 class="text-lg font-semibold text-white">
                    No se encontraron resultados
                </h2>

                <p class="mt-2 text-sm text-slate-400">
                    Consulta: ${escapeHtml(query)}
                </p>
            `;
        }

        function entityDetailUrl(uuid) {
            return entityDetailUrlTemplate.replace(
                '__ENTITY_UUID__',
                encodeURIComponent(uuid)
            );
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }
        if (input.dataset.autoSearch === 'true') {
            searchKnowledge(input.value);
        }
    </script>
</x-app-layout>
