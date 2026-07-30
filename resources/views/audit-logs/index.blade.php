<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">
                    Seguridad APB
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Auditoría del sistema
                </h1>

                <p class="mt-2 max-w-3xl text-sm text-slate-400">
                    Registro inmutable de altas, ediciones y cambios de estado del catálogo.
                    Esta pantalla es exclusivamente de consulta administrativa.
                </p>

                <p class="mt-2 text-xs text-slate-500">
                    Horarios mostrados en {{ $displayTimezone }}.
                    La base conserva UTC para mantener integridad técnica.
                </p>
            </div>

            <div class="rounded-xl border border-cyan-400/20 bg-cyan-400/5 px-4 py-3 text-sm text-cyan-200">
                {{ $auditLogs->total() }} movimientos encontrados
            </div>
        </div>

        @if ($legacyCompatibilityCount > 0)
            <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                Hay {{ $legacyCompatibilityCount }}
                {{ $legacyCompatibilityCount === 1 ? 'compatibilidad histórica' : 'compatibilidades históricas' }}
                creada antes de activar la auditoría.
                La relación existe, pero SRCM no inventará un evento retroactivo.
            </div>
        @endif

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-black/10">
            <form
                method="GET"
                action="{{ route('audit-logs.index') }}"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <div>
                    <label for="request_id" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Request ID
                    </label>
                    <input
                        id="request_id"
                        name="request_id"
                        type="search"
                        value="{{ $filters['request_id'] ?? '' }}"
                        placeholder="UUID completo o parcial"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >
                </div>

                <div>
                    <label for="event" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Evento
                    </label>
                    <select
                        id="event"
                        name="event"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos</option>
                        @foreach ($eventLabels as $value => $label)
                            <option
                                value="{{ $value }}"
                                @selected(($filters['event'] ?? '') === $value)
                            >
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="entity" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Entidad
                    </label>
                    <select
                        id="entity"
                        name="entity"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todas</option>
                        <option value="brand" @selected(($filters['entity'] ?? '') === 'brand')>Marca</option>
                        <option value="compatibility" @selected(($filters['entity'] ?? '') === 'compatibility')>Compatibilidad</option>
                        <option value="entity" @selected(($filters['entity'] ?? '') === 'entity')>Entidad de conocimiento</option>
                        <option value="identifier" @selected(($filters['entity'] ?? '') === 'identifier')>Identificador</option>
                                <option value="manufacturer" @selected(($filters['entity'] ?? '') === 'manufacturer')>Fabricante</option>
                        <option value="product_category" @selected(($filters['entity'] ?? '') === 'product_category')>Categoría</option>
                        <option value="technical_model" @selected(($filters['entity'] ?? '') === 'technical_model')>Modelo técnico</option>
                    </select>
                </div>

                <div>
                    <label for="user_id" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Usuario
                    </label>
                    <select
                        id="user_id"
                        name="user_id"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                        <option value="">Todos</option>
                        @foreach ($users as $user)
                            <option
                                value="{{ $user->id }}"
                                @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)
                            >
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="date_from" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Desde
                    </label>
                    <input
                        id="date_from"
                        name="date_from"
                        type="date"
                        value="{{ $filters['date_from'] ?? '' }}"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                </div>

                <div>
                    <label for="date_to" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Hasta
                    </label>
                    <input
                        id="date_to"
                        name="date_to"
                        type="date"
                        value="{{ $filters['date_to'] ?? '' }}"
                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                    >
                </div>

                <div class="flex flex-wrap gap-3 md:col-span-2 xl:col-span-3">
                    <button
                        type="submit"
                        class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Aplicar filtros
                    </button>

                    <a
                        href="{{ route('audit-logs.index') }}"
                        class="rounded-xl border border-slate-700 px-5 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                    >
                        Limpiar
                    </a>
                </div>
            </form>

            @if ($errors->any())
                <div class="mt-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    Revisá los filtros ingresados.
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            @if ($auditLogs->isEmpty())
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        No se encontraron movimientos.
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        Ajustá los filtros o realizá una operación auditada en el catálogo.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead class="bg-slate-950/60">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Fecha
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Evento
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Entidad
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Actor
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Request ID
                                </th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Detalle
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            @foreach ($auditLogs as $auditLog)
                                <tr class="transition hover:bg-slate-800/40">
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-300">
                                        {{ $auditLog->created_at->copy()->timezone($displayTimezone)->format('d/m/Y H:i:s') }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-200">
                                            {{ $eventLabels[$auditLog->event] ?? $auditLog->event }}
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 text-sm text-slate-300">
                                        <div class="font-semibold text-white">
                                            {{ $entityLabels[$auditLog->auditable_type] ?? class_basename($auditLog->auditable_type) }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            ID {{ $auditLog->auditable_id }}
                                        </div>
                                    </td>

                                    <td class="px-5 py-4 text-sm text-slate-300">
                                        <div class="font-semibold text-white">
                                            {{ $auditLog->actor_name ?? 'Sistema' }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $roleLabels[$auditLog->actor_role] ?? 'Proceso interno' }}
                                        </div>
                                    </td>

                                    <td class="max-w-xs px-5 py-4">
                                        <code class="block truncate text-xs text-cyan-300">
                                            {{ $auditLog->request_id ?? 'Sin solicitud HTTP' }}
                                        </code>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <a
                                            href="{{ route('audit-logs.show', $auditLog) }}"
                                            class="text-sm font-semibold text-cyan-400 transition hover:text-cyan-300"
                                        >
                                            Ver
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($auditLogs->hasPages())
                    <div class="border-t border-slate-800 px-6 py-4">
                        {{ $auditLogs->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
