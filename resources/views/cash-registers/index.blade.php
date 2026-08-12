<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6" data-cash-registers-index>
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">
                    Finanzas · Operación de caja
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">Cajas operativas y turnos</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    La caja operativa identifica el puesto físico/lógico. El turno identifica quién la opera ahora.
                    Cada caja apunta a una cuenta financiera de efectivo.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ route('financial-accounts.index') }}"
                    class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-bold text-slate-300 hover:border-slate-500 hover:text-white"
                >
                    Cuentas financieras
                </a>
                @can('manage-cash-registers')
                    <a
                        href="{{ route('cash-registers.create') }}"
                        class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-black text-slate-950 hover:bg-amber-300"
                    >
                        Nueva caja
                    </a>
                @endcan
            </div>
        </div>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-2xl border border-cyan-400/20 bg-cyan-400/5 p-5" data-current-cash-session>
            @if($currentSession)
                <p class="text-xs font-black uppercase tracking-[0.2em] text-cyan-300">Tu turno activo</p>
                <div class="mt-2 flex flex-wrap items-baseline gap-2">
                    <strong class="text-xl text-white">{{ $currentSession->register->name }}</strong>
                    <span class="text-slate-500">→</span>
                    <span class="font-bold text-cyan-100">{{ $currentSession->register->financialAccount->name }}</span>
                    <span class="font-mono text-xs text-slate-400">{{ $currentSession->currency_code }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-400">
                    Fondo inicial:
                    <strong class="font-mono text-white">
                        {{ number_format($currentSession->opening_amount_minor / 100, 2, ',', '.') }}
                    </strong>
                    · abierto {{ $currentSession->opened_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                </p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-4">
                        <p class="text-xs font-black uppercase tracking-wider text-emerald-300">
                            Efectivo esperado
                        </p>
                        <p class="mt-2 font-mono text-2xl font-black text-white">
                            {{ $currentSession->currency_code }}
                            {{ number_format(($currentExpectedMinor ?? 0) / 100, 2, ',', '.') }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            Fondo inicial + cobros en efectivo − retiros registrados.
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-700 bg-slate-950/40 p-4">
                        <p class="text-xs font-black uppercase tracking-wider text-slate-400">
                            P4C
                        </p>
                        <p class="mt-2 text-sm font-bold text-white">
                            Los retiros son transferencias internas a tesorería.
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            No son gastos y no modifican ventas ni cobros históricos. No cierra el turno.
                        </p>
                    </div>
                </div>

                <div class="mt-5 rounded-2xl border border-amber-400/20 bg-amber-400/5 p-4" data-security-drop-form>
                    <p class="text-sm font-black text-amber-100">
                        Retiro de seguridad
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Sacá efectivo de la caja operativa y registrá su destino en una reserva / tesorería.
                    </p>

                    @if($treasuryAccounts->isEmpty())
                        <div class="mt-4 rounded-xl border border-slate-700 bg-slate-950/50 px-4 py-3 text-sm text-slate-400">
                            No hay una cuenta activa de tipo
                            <strong class="text-white">Reserva / Tesorería de efectivo</strong>
                            para {{ $currentSession->currency_code }}.
                            Un administrador debe crearla en Cuentas financieras.
                        </div>
                    @else
                        <form
                            method="POST"
                            action="{{ route('cash-registers.security-drops') }}"
                            class="mt-4 grid gap-3 lg:grid-cols-2"
                        >
                            @csrf
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value="cash-ui:security-drop:{{ \Illuminate\Support\Str::uuid() }}"
                            >

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Destino de tesorería
                                </span>
                                <select
                                    name="destination_financial_account_id"
                                    required
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-amber-400 focus:ring-amber-400"
                                >
                                    <option value="">Seleccionar destino…</option>
                                    @foreach($treasuryAccounts as $account)
                                        <option
                                            value="{{ $account->id }}"
                                            @selected((string) old('destination_financial_account_id') === (string) $account->id)
                                        >
                                            {{ $account->name }} · {{ $account->currency_code }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Importe
                                </span>
                                <input
                                    type="text"
                                    name="amount"
                                    value="{{ old('amount') }}"
                                    inputmode="decimal"
                                    placeholder="0,00"
                                    required
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white focus:border-amber-400 focus:ring-amber-400"
                                >
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Motivo
                                </span>
                                <select
                                    name="reason_code"
                                    required
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-amber-400 focus:ring-amber-400"
                                >
                                    @foreach($dropReasons as $reason)
                                        <option
                                            value="{{ $reason->value }}"
                                            @selected(old('reason_code', \App\Enums\CashSecurityDropReason::ExcessCash->value) === $reason->value)
                                        >
                                            {{ $reason->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Nota / sobre / referencia
                                </span>
                                <input
                                    type="text"
                                    name="note"
                                    value="{{ old('note') }}"
                                    maxlength="1000"
                                    placeholder="Opcional"
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-amber-400 focus:ring-amber-400"
                                >
                            </label>

                            <div class="lg:col-span-2 flex justify-end">
                                <button
                                    type="submit"
                                    class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-black text-slate-950 hover:bg-amber-300"
                                >
                                    Registrar retiro de seguridad
                                </button>
                            </div>
                        </form>
                    @endif
                </div>

                @if($recentMovements->isNotEmpty())
                    <div class="mt-5" data-current-cash-ledger>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-400">
                            Últimos movimientos del turno
                        </p>
                        <div class="mt-3 divide-y divide-white/5 overflow-hidden rounded-xl border border-slate-800">
                            @foreach($recentMovements as $movement)
                                <div class="flex flex-col gap-2 bg-slate-950/35 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-white">
                                            {{ $movement->type->label() }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $movement->occurred_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                            · {{ $movement->recordedBy?->name ?? 'Usuario' }}
                                            @if($movement->destinationFinancialAccount)
                                                · destino: {{ $movement->destinationFinancialAccount->name }}
                                            @endif
                                            @if($movement->reason_code)
                                                · {{ $movement->reason_code->label() }}
                                            @endif
                                        </p>
                                    </div>
                                    <strong class="font-mono {{ $movement->direction === \App\Enums\CashMovementDirection::In ? 'text-emerald-300' : 'text-amber-300' }}">
                                        {{ $movement->direction === \App\Enums\CashMovementDirection::In ? '+' : '−' }}
                                        {{ $movement->currency_code }}
                                        {{ number_format($movement->amount_minor / 100, 2, ',', '.') }}
                                    </strong>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                <p class="font-black text-white">No tenés un turno de caja abierto.</p>
                <p class="mt-2 text-sm text-slate-400">
                    Podés vender por medios electrónicos. Para cobrar efectivo, abrí una de las cajas disponibles.
                </p>
            @endif
        </section>

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-white/10 px-5 py-4">
                <p class="text-sm font-bold text-white">
                    {{ $registers->count() }} caja{{ $registers->count() === 1 ? '' : 's' }}
                </p>
            </div>

            @if($registers->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="font-bold text-slate-200">Todavía no hay cajas operativas.</p>
                    <p class="mt-2 text-sm text-slate-500">
                        Un administrador debe vincular una cuenta financiera de tipo Caja de efectivo.
                    </p>
                </div>
            @else
                <div class="divide-y divide-white/5">
                    @foreach($registers as $register)
                        @php
                            $openSession = $register->sessions->first();
                        @endphp
                        <article class="grid gap-4 px-5 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,0.55fr)_auto] lg:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-lg font-black text-white">{{ $register->name }}</h2>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-black {{ $register->active ? 'bg-emerald-500/10 text-emerald-200' : 'bg-slate-700/50 text-slate-400' }}">
                                        {{ $register->active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                    @if($openSession)
                                        <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-[11px] font-black text-cyan-200">
                                            TURNO ABIERTO
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm text-slate-300">
                                    {{ $register->financialAccount->name }}
                                    · <span class="font-mono">{{ $register->financialAccount->currency_code }}</span>
                                    · {{ $register->financialAccount->type->label() }}
                                </p>
                                @if($openSession)
                                    <p class="mt-1 text-xs text-slate-500">
                                        Operador: {{ $openSession->openedBy?->name ?? 'Usuario' }}
                                        · desde {{ $openSession->opened_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                    </p>
                                    @can('supervise-cash-registers')
                                        @if(array_key_exists($openSession->id, $expectedBySession))
                                            <p class="mt-2 text-xs font-bold text-emerald-300">
                                                Efectivo esperado sistema:
                                                <span class="font-mono">
                                                    {{ $openSession->currency_code }}
                                                    {{ number_format($expectedBySession[$openSession->id] / 100, 2, ',', '.') }}
                                                </span>
                                            </p>
                                        @endif
                                    @endcan
                                @endif
                            </div>

                            <div>
                                @if(
                                    $register->active
                                    && ! $openSession
                                    && ! $currentSession
                                )
                                    <form
                                        method="POST"
                                        action="{{ route('cash-registers.open', $register) }}"
                                        class="flex items-end gap-2"
                                    >
                                        @csrf
                                        <input
                                            type="hidden"
                                            name="idempotency_key"
                                            value="cash-ui:open:{{ \Illuminate\Support\Str::uuid() }}"
                                        >
                                        <label class="block flex-1">
                                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                                Fondo inicial
                                            </span>
                                            <input
                                                type="text"
                                                name="opening_amount"
                                                value="0,00"
                                                inputmode="decimal"
                                                class="mt-1 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white focus:border-cyan-400 focus:ring-cyan-400"
                                                required
                                            >
                                        </label>
                                        <button
                                            type="submit"
                                            class="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-black text-slate-950 hover:bg-emerald-300"
                                        >
                                            Abrir turno
                                        </button>
                                    </form>
                                @elseif($openSession)
                                    <p class="text-sm font-bold text-cyan-200">Caja en operación.</p>
                                @elseif($currentSession)
                                    <p class="text-sm text-slate-500">Ya estás operando otra caja.</p>
                                @else
                                    <p class="text-sm text-slate-500">Caja no disponible para apertura.</p>
                                @endif
                            </div>

                            @can('manage-cash-registers')
                                <div class="flex justify-end gap-2">
                                    <a
                                        href="{{ route('cash-registers.edit', $register) }}"
                                        class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-bold text-slate-300 hover:border-slate-500 hover:text-white"
                                    >
                                        Editar
                                    </a>
                                    <form
                                        method="POST"
                                        action="{{ route('cash-registers.toggle-active', $register) }}"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <button
                                            type="submit"
                                            class="rounded-lg border px-3 py-2 text-xs font-bold {{ $register->active ? 'border-red-400/20 text-red-300' : 'border-emerald-400/20 text-emerald-300' }}"
                                        >
                                            {{ $register->active ? 'Inactivar' : 'Activar' }}
                                        </button>
                                    </form>
                                </div>
                            @endcan
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
