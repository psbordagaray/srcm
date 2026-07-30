<x-app-layout>
    @php
        $formatAuditValue = static function (mixed $value): string {
            return match (true) {
                is_bool($value) => $value ? 'Sí' : 'No',
                $value === null => '—',
                is_array($value) => json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                default => (string) $value,
            };
        };

        $oldValues = $auditLog->old_values ?? [];
        $newValues = $auditLog->new_values ?? [];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">
                    Auditoría inmutable
                </p>

                <h1 class="mt-1 text-2xl font-bold text-white">
                    Movimiento #{{ $auditLog->id }}
                </h1>

                <p class="mt-2 text-sm text-slate-400">
                    {{ $eventLabels[$auditLog->event] ?? $auditLog->event }}
                    de
                    {{ $entityLabels[$auditLog->auditable_type] ?? class_basename($auditLog->auditable_type) }}
                    ID {{ $auditLog->auditable_id }}.
                </p>
            </div>

            <a
                href="{{ route('audit-logs.index') }}"
                class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
            >
                Volver a auditoría
            </a>
        </div>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fecha</p>
                <p class="mt-2 text-sm font-semibold text-white">
                    {{ $auditLog->created_at->copy()->timezone($displayTimezone)->format('d/m/Y H:i:s') }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Actor</p>
                <p class="mt-2 text-sm font-semibold text-white">
                    {{ $auditLog->actor_name ?? 'Sistema' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ $roleLabels[$auditLog->actor_role] ?? 'Proceso interno' }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Ruta</p>
                <p class="mt-2 break-all text-sm font-semibold text-white">
                    {{ $auditLog->route_name ?? 'Sin ruta HTTP' }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ $auditLog->http_method ?? '—' }} {{ $auditLog->url_path ?? '' }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Origen</p>
                <p class="mt-2 text-sm font-semibold text-white">
                    {{ $auditLog->ip_address ?? 'Proceso interno' }}
                </p>
                <p class="mt-1 truncate text-xs text-slate-500" title="{{ $auditLog->user_agent }}">
                    {{ $auditLog->user_agent ?? 'Sin navegador' }}
                </p>
            </div>
        </section>

        <section class="rounded-2xl border border-cyan-400/20 bg-cyan-400/5 p-5">
            <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">
                Request ID
            </p>
            <code class="mt-2 block break-all text-sm text-cyan-100">
                {{ $auditLog->request_id ?? 'Sin solicitud HTTP' }}
            </code>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80">
                <div class="border-b border-slate-800 px-5 py-4">
                    <h2 class="font-semibold text-white">Valores anteriores</h2>
                </div>

                @if ($oldValues === [])
                    <div class="px-5 py-10 text-center text-sm text-slate-500">
                        No existen valores anteriores para este evento.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-800">
                            <tbody class="divide-y divide-slate-800">
                                @foreach ($oldValues as $field => $value)
                                    <tr>
                                        <th class="w-1/3 px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            {{ $field }}
                                        </th>
                                        <td class="break-all px-5 py-3 text-sm text-slate-300">
                                            {{ $formatAuditValue($value) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80">
                <div class="border-b border-slate-800 px-5 py-4">
                    <h2 class="font-semibold text-white">Valores posteriores</h2>
                </div>

                @if ($newValues === [])
                    <div class="px-5 py-10 text-center text-sm text-slate-500">
                        No existen valores posteriores para este evento.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-800">
                            <tbody class="divide-y divide-slate-800">
                                @foreach ($newValues as $field => $value)
                                    <tr>
                                        <th class="w-1/3 px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            {{ $field }}
                                        </th>
                                        <td class="break-all px-5 py-3 text-sm text-slate-300">
                                            {{ $formatAuditValue($value) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
            <dl class="grid gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Correo histórico</dt>
                    <dd class="mt-1 break-all text-sm text-slate-300">
                        {{ $auditLog->actor_email ?? '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tipo técnico</dt>
                    <dd class="mt-1 break-all text-sm text-slate-300">
                        {{ $auditLog->auditable_type }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</x-app-layout>
