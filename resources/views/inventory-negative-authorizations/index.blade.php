<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">
                    Inventario · Autorización excepcional
                </p>
                <h1 class="mt-1 text-2xl font-bold text-white">Overrides</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Solicitudes, decisiones administrativas y consumo controlado dentro de la organización activa.
                </p>
            </div>

            <a href="{{ route('inventory-movements.index', ['status' => 'draft']) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">
                Ver borradores
            </a>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <p class="font-semibold">Revisá la operación:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pendientes</p>
                <p class="mt-2 text-3xl font-bold {{ $summary['pending'] > 0 ? 'text-amber-300' : 'text-slate-300' }}">{{ $summary['pending'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Overrides activos</p>
                <p class="mt-2 text-3xl font-bold {{ $summary['approved'] > 0 ? 'text-cyan-300' : 'text-slate-300' }}">{{ $summary['approved'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Consumidos</p>
                <p class="mt-2 text-3xl font-bold text-emerald-300">{{ $summary['fulfilled'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cerrados sin consumo</p>
                <p class="mt-2 text-3xl font-bold text-slate-300">{{ $summary['closed'] }}</p>
            </article>
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form method="GET" action="{{ route('inventory-negative-authorizations.index') }}" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_15rem_auto]">
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Solicitud, movimiento, producto o SKU..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-amber-400 focus:ring-amber-400"
                    >
                    <select name="status" class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-amber-400 focus:ring-amber-400">
                        <option value="">Todos los estados</option>
                        @foreach($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}" @selected($status === $statusOption->value)>{{ $statusOption->label() }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-xl bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200">Filtrar</button>
                        @if($search !== '' || $status !== '')
                            <a href="{{ route('inventory-negative-authorizations.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Limpiar</a>
                        @endif
                    </div>
                </form>
            </div>

            @if($authorizations->total() === 0)
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        {{ $search === '' && $status === '' ? 'Todavía no existen solicitudes de Override' : 'Ninguna solicitud coincide con los filtros' }}
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        {{ $canOverride ? 'Las solicitudes aparecerán cuando un usuario operativo justifique una salida con saldo insuficiente.' : 'Iniciá la solicitud desde un movimiento borrador que no pueda confirmarse por saldo insuficiente.' }}
                    </p>
                </div>
            @else
                <div class="divide-y divide-slate-800">
                    @foreach($authorizations as $row)
                        @php($authorization = $row['authorization'])
                        @php($movement = $authorization->movement)
                        @php($override = $authorization->override)

                        <article class="p-5 transition hover:bg-slate-800/20">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-bold text-amber-300">#{{ $row['shortId'] }}</span>
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $row['statusClass'] }}">{{ $authorization->status->label() }}</span>
                                        @if($movement)
                                            <span class="inline-flex rounded-full bg-violet-500/10 px-2.5 py-1 text-xs font-semibold text-violet-300">{{ $movement->type->label() }}</span>
                                            <span class="font-mono text-xs text-cyan-300">Movimiento #{{ $row['movementShortId'] }}</span>
                                        @endif
                                    </div>

                                    <p class="mt-3 text-sm leading-6 text-slate-200">{{ $authorization->reason }}</p>
                                    <p class="mt-2 text-xs text-slate-500">
                                        Solicitó {{ $authorization->requestedBy?->name ?? 'Usuario ausente' }} · {{ $authorization->requested_at->format('d/m/Y H:i') }}
                                    </p>

                                    @if($authorization->rejection_reason)
                                        <p class="mt-3 rounded-lg border border-red-500/20 bg-red-500/5 px-3 py-2 text-xs text-red-200">
                                            Rechazo: {{ $authorization->rejection_reason }}
                                        </p>
                                    @elseif($authorization->invalidation_reason)
                                        <p class="mt-3 rounded-lg border border-slate-500/20 bg-slate-500/5 px-3 py-2 text-xs text-slate-300">
                                            Invalidación: {{ $authorization->invalidation_reason }}
                                        </p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-col gap-3 xl:w-[28rem] xl:items-end">
                                    @if($override)
                                        <div class="w-full rounded-xl border border-slate-700 bg-slate-950/40 px-4 py-3 text-xs text-slate-400">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <span>Override #{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($override->public_id, 0, 8)) }}</span>
                                                <span class="font-semibold {{ $override->status->value === 'active' ? 'text-cyan-300' : 'text-slate-300' }}">{{ $override->status->label() }}</span>
                                            </div>
                                            @if($override->issued_at)
                                                <p class="mt-2">Emitido {{ $override->issued_at->format('d/m/Y H:i') }} por {{ $authorization->approvedBy?->name ?? 'Administrador ausente' }}</p>
                                            @endif
                                        </div>
                                    @endif

                                    @if($row['canApprove'])
                                        <form method="POST" action="{{ route('inventory-negative-authorizations.approve', $authorization) }}" onsubmit="return confirm('¿Emitir este Override? Quedará limitado al solicitante, movimiento y snapshot actuales.');">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="rounded-xl bg-cyan-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">Aprobar y emitir Override</button>
                                        </form>
                                    @endif

                                    @if($row['canConsume'])
                                        <form method="POST" action="{{ route('inventory-negative-authorizations.confirm', [$movement, $override]) }}" onsubmit="return confirm('¿Consumir el Override? Se confirmará el movimiento y se abrirá la incidencia negativa.');">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="rounded-xl bg-red-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-red-200">Consumir Override y confirmar</button>
                                        </form>
                                    @endif

                                    @if($row['canReject'])
                                        <details class="w-full rounded-xl border border-red-500/20 bg-red-500/5 p-3">
                                            <summary class="cursor-pointer text-sm font-semibold text-red-200">Rechazar solicitud</summary>
                                            <form method="POST" action="{{ route('inventory-negative-authorizations.reject', $authorization) }}" class="mt-3 space-y-3">
                                                @csrf
                                                @method('PATCH')
                                                <textarea name="reason" rows="2" required minlength="10" maxlength="2000" placeholder="Motivo administrativo verificable..." class="block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-red-400 focus:ring-red-400"></textarea>
                                                <button type="submit" class="rounded-xl border border-red-400/40 px-4 py-2 text-sm font-bold text-red-100 transition hover:bg-red-400/10">Confirmar rechazo</button>
                                            </form>
                                        </details>
                                    @endif

                                    @if($row['canRevoke'])
                                        <details class="w-full rounded-xl border border-red-500/20 bg-red-500/5 p-3">
                                            <summary class="cursor-pointer text-sm font-semibold text-red-200">Revocar Override activo</summary>
                                            <form method="POST" action="{{ route('inventory-negative-authorizations.revoke', $override) }}" class="mt-3 space-y-3">
                                                @csrf
                                                @method('PATCH')
                                                <textarea name="reason" rows="2" required minlength="10" maxlength="2000" placeholder="Motivo de revocación verificable..." class="block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-red-400 focus:ring-red-400"></textarea>
                                                <button type="submit" class="rounded-xl border border-red-400/40 px-4 py-2 text-sm font-bold text-red-100 transition hover:bg-red-400/10">Confirmar revocación</button>
                                            </form>
                                        </details>
                                    @endif
                                </div>
                            </div>

                            <details class="mt-5 rounded-xl border border-slate-800 bg-slate-950/30">
                                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-300">Ver snapshot autorizado · {{ $row['lines']->count() }} {{ $row['lines']->count() === 1 ? 'posición' : 'posiciones' }}</summary>
                                <div class="overflow-x-auto border-t border-slate-800">
                                    <table class="min-w-full divide-y divide-slate-800">
                                        <thead class="bg-slate-950/60">
                                            <tr>
                                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Producto y ubicación</th>
                                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Condición</th>
                                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Saldo actual</th>
                                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Salida</th>
                                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Proyección</th>
                                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Déficit nuevo</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-800">
                                            @foreach($row['lines'] as $line)
                                                <tr class="{{ $line['createsNegative'] ? 'bg-red-500/[0.03]' : '' }}">
                                                    <td class="px-4 py-3"><div class="text-sm font-semibold text-white">{{ $line['product'] }}</div><div class="mt-1 font-mono text-xs text-cyan-300">{{ $line['sku'] }}</div><div class="mt-1 text-xs text-slate-500">{{ $line['location'] }}</div></td>
                                                    <td class="px-4 py-3 text-sm text-slate-300">{{ $line['condition'] }}</td>
                                                    <td class="px-4 py-3 text-right font-mono text-sm text-slate-300">{{ $line['current'] }} {{ $line['unit'] }}</td>
                                                    <td class="px-4 py-3 text-right font-mono text-sm text-slate-300">{{ $line['requested'] }} {{ $line['unit'] }}</td>
                                                    <td class="px-4 py-3 text-right font-mono text-sm font-bold {{ $line['createsNegative'] ? 'text-red-300' : 'text-slate-300' }}">{{ $line['projected'] }} {{ $line['unit'] }}</td>
                                                    <td class="px-4 py-3 text-right font-mono text-sm font-bold {{ $line['createsNegative'] ? 'text-red-300' : 'text-slate-500' }}">{{ $line['deficit'] }} {{ $line['unit'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>

                @if($authorizations->hasPages())
                    <div class="border-t border-slate-800 px-5 py-4">{{ $authorizations->links() }}</div>
                @endif
            @endif
        </section>

        <div class="rounded-2xl border border-amber-400/10 bg-amber-400/[0.03] px-5 py-4 text-sm text-slate-400">
            Un Override no transfiere la cuenta del Administrador: autoriza una sola confirmación, al usuario solicitante, sobre el movimiento y los saldos exactos fotografiados. Cualquier cambio invalida la autorización.
        </div>
    </div>
</x-app-layout>
