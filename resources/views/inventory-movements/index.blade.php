<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Inventario · Libro operativo
                </p>
                <h1 class="mt-1 text-2xl font-bold text-white">Movimientos</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Borradores y movimientos confirmados de la organización activa.
                </p>
            </div>

            @can('draft-inventory-movements')
                <a
                    href="{{ route('inventory-movements.create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Nuevo movimiento
                </a>
            @endcan
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

        <div class="grid gap-4 sm:grid-cols-3">
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Borradores</p>
                <p class="mt-2 text-3xl font-bold {{ $summary['drafts'] > 0 ? 'text-amber-300' : 'text-slate-300' }}">{{ $summary['drafts'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Confirmados</p>
                <p class="mt-2 text-3xl font-bold text-emerald-300">{{ $summary['confirmed'] }}</p>
            </article>
            <article class="sulu-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fecha efectiva de hoy</p>
                <p class="mt-2 text-3xl font-bold text-cyan-300">{{ $summary['today'] }}</p>
            </article>
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-xl shadow-black/10">
            <div class="border-b border-slate-800 p-4">
                <form method="GET" action="{{ route('inventory-movements.index') }}" class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_14rem_12rem_auto]">
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Movimiento, producto, SKU o referencia..."
                        class="min-w-0 rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
                    >
                    <select name="type" class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Todos los tipos</option>
                        @foreach($types as $movementType)
                            <option value="{{ $movementType->value }}" @selected($type === $movementType->value)>{{ $movementType->label() }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Todos los estados</option>
                        @foreach($statuses as $movementStatus)
                            <option value="{{ $movementStatus->value }}" @selected($status === $movementStatus->value)>{{ $movementStatus->label() }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Filtrar</button>
                        @if($search !== '' || $type !== '' || $status !== '')
                            <a href="{{ route('inventory-movements.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Limpiar</a>
                        @endif
                    </div>
                </form>
            </div>

            @if($movements->total() === 0)
                <div class="px-6 py-16 text-center">
                    <h2 class="text-lg font-semibold text-white">
                        {{ $search === '' && $type === '' && $status === '' ? 'Todavía no existen movimientos' : 'Ningún movimiento coincide con los filtros' }}
                    </h2>
                    <p class="mt-2 text-sm text-slate-400">
                        {{ $search === '' && $type === '' && $status === '' ? 'El libro comenzará cuando se cree el primer borrador.' : 'Limpiá o ajustá los criterios de búsqueda.' }}
                    </p>
                </div>
            @else
                <div class="divide-y divide-slate-800">
                    @foreach($movements as $row)
                        @php($movement = $row['movement'])
                        <article class="p-5 transition hover:bg-slate-800/20">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-bold text-cyan-300">#{{ $row['shortId'] }}</span>
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $row['statusClass'] }}">{{ $movement->status->label() }}</span>
                                        <span class="inline-flex rounded-full bg-violet-500/10 px-2.5 py-1 text-xs font-semibold text-violet-300">{{ $movement->type->label() }}</span>
                                        <span class="text-xs text-slate-500">{{ $movement->lines->count() }} {{ $movement->lines->count() === 1 ? 'línea' : 'líneas' }}</span>
                                    </div>
                                    <p class="mt-3 text-sm leading-6 text-slate-300">{{ $movement->reason }}</p>
                                    @if($movement->source_reference)
                                        <p class="mt-1 text-xs text-slate-500">Referencia: {{ $movement->source_reference }}</p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-col gap-3 xl:items-end">
                                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-xs xl:text-right">
                                        <div><dt class="text-slate-500">Fecha efectiva</dt><dd class="mt-1 font-semibold text-slate-300">{{ $movement->effective_at->format('d/m/Y H:i') }}</dd></div>
                                        <div><dt class="text-slate-500">Creó</dt><dd class="mt-1 font-semibold text-slate-300">{{ $movement->createdBy?->name ?? 'Usuario ausente' }}</dd></div>
                                        @if($movement->confirmed_at)
                                            <div><dt class="text-slate-500">Confirmación</dt><dd class="mt-1 font-semibold text-emerald-300">{{ $movement->confirmed_at->format('d/m/Y H:i') }}</dd></div>
                                            <div><dt class="text-slate-500">Confirmó</dt><dd class="mt-1 font-semibold text-slate-300">{{ $movement->confirmedBy?->name ?? 'Usuario ausente' }}</dd></div>
                                        @endif
                                    </dl>

                                    <div class="flex max-w-md flex-col gap-2 xl:items-end">
                                        @if($row['negativeAuthorization'])
                                            <a href="{{ route('inventory-negative-authorizations.index', ['search' => $row['negativeAuthorization']->public_id]) }}" class="inline-flex items-center gap-2 rounded-xl border border-amber-400/30 bg-amber-400/5 px-3 py-2 text-xs font-semibold text-amber-200 transition hover:bg-amber-400/10">
                                                Solicitud #{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($row['negativeAuthorization']->public_id, 0, 8)) }}
                                                · {{ $row['negativeAuthorization']->status->label() }}
                                            </a>
                                        @endif

                                        @if($row['canConfirm'])
                                            <form method="POST" action="{{ route('inventory-movements.confirm', $movement) }}" onsubmit="return confirm('¿Confirmar este movimiento? El libro y los saldos se actualizarán de forma atómica.');">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="rounded-xl bg-emerald-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-emerald-200">Confirmar y proyectar</button>
                                            </form>
                                        @endif

                                        @if($row['canConfirmWithOverride'])
                                            <form method="POST" action="{{ route('inventory-negative-authorizations.confirm', [$movement, $row['negativeAuthorization']->override]) }}" onsubmit="return confirm('¿Consumir el Override? El movimiento se confirmará y la incidencia negativa quedará registrada.');">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="rounded-xl bg-red-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-red-200">Confirmar con Override</button>
                                            </form>
                                        @endif

                                        @if($row['canRequestNegative'])
                                            <details class="w-full rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 xl:w-96">
                                                <summary class="cursor-pointer text-sm font-semibold text-amber-200">Solicitar Override por stock insuficiente</summary>
                                                <form method="POST" action="{{ route('inventory-negative-authorizations.store', $movement) }}" class="mt-3 space-y-3">
                                                    @csrf
                                                    <textarea name="reason" rows="3" required minlength="10" maxlength="2000" placeholder="Motivo excepcional verificable..." class="block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-amber-400 focus:ring-amber-400"></textarea>
                                                    <button type="submit" class="rounded-xl border border-amber-400/40 px-4 py-2 text-sm font-bold text-amber-100 transition hover:bg-amber-400/10">Enviar a Administración</button>
                                                </form>
                                            </details>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <details class="mt-5 rounded-xl border border-slate-800 bg-slate-950/30">
                                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-300">Ver detalle de líneas</summary>
                                <div class="overflow-x-auto border-t border-slate-800">
                                    <table class="min-w-full divide-y divide-slate-800">
                                        <thead class="bg-slate-950/60"><tr>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Producto</th>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Condición</th>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Origen</th>
                                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Destino</th>
                                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Cantidad base</th>
                                        </tr></thead>
                                        <tbody class="divide-y divide-slate-800">
                                            @foreach($row['lines'] as $line)
                                                <tr>
                                                    <td class="px-4 py-3"><div class="text-sm font-semibold text-white">{{ $line['productName'] }}</div><div class="mt-1 font-mono text-xs text-cyan-300">{{ $line['productSku'] }}</div>@if($line['notes'])<div class="mt-1 text-xs text-slate-500">{{ $line['notes'] }}</div>@endif</td>
                                                    <td class="px-4 py-3 text-sm text-slate-300">{{ $line['condition'] }}</td>
                                                    <td class="px-4 py-3 text-sm text-slate-300">{{ $line['source'] }}</td>
                                                    <td class="px-4 py-3 text-sm text-slate-300">{{ $line['destination'] }}</td>
                                                    <td class="px-4 py-3 text-right font-mono text-sm font-bold text-white">{{ $line['quantity'] }} <span class="text-xs font-normal text-slate-500">{{ $line['unit'] }}</span></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>

                @if($movements->hasPages())
                    <div class="border-t border-slate-800 px-5 py-4">{{ $movements->links() }}</div>
                @endif
            @endif
        </section>

        <div class="rounded-2xl border border-cyan-400/10 bg-cyan-400/[0.03] px-5 py-4 text-sm text-slate-400">
            Un borrador todavía no afecta el stock. La confirmación aplica el movimiento completo en una transacción; si una salida supera el saldo, permanece intacto para iniciar el flujo de Override.
        </div>
    </div>
</x-app-layout>
