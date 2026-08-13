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
                            Los retiros requieren autorización separada.
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            Solicitar y autorizar no mueven dinero. Sólo la ejecución autorizada reduce el esperado.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-5 rounded-2xl border border-slate-700 bg-slate-950/35 p-4"
                    data-turn-operations
                    x-data="{
                        operation: '{{ old('operation', request('operation')) }}',
                        counted: '{{ old('counted_amount') }}',
                        expected: {{ (int) ($currentExpectedMinor ?? 0) }},
                        currency: '{{ $currentSession->currency_code }}',
                        countedMinor() {
                            const raw = String(this.counted).trim().replace(',', '.');
                            if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) return null;
                            return Math.round(Number(raw) * 100);
                        },
                        differenceMinor() {
                            const value = this.countedMinor();
                            return value === null ? null : value - this.expected;
                        },
                        differenceLabel() {
                            const value = this.differenceMinor();
                            if (value === null) return '—';

                            const sign = value < 0
                                ? '− '
                                : (value > 0 ? '+ ' : '');

                            const amount = new Intl.NumberFormat(
                                'es-AR',
                                {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }
                            ).format(Math.abs(value) / 100);

                            return `${sign}${this.currency} ${amount}`;
                        }
                    }"
                >
                    <div>
                        <p class="text-sm font-black text-white">
                            Operaciones del turno
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Elegí qué necesitás hacer. SRCM habilita un solo flujo sensible por vez.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <button
                            type="button"
                            @click="operation = 'security_drop'"
                            :class="operation === 'security_drop'
                                ? 'border-amber-300 bg-amber-400/15 text-amber-100'
                                : 'border-slate-700 bg-slate-950/40 text-slate-300 hover:border-slate-500'"
                            class="rounded-xl border px-4 py-4 text-left transition"
                            data-select-security-drop
                        >
                            <strong class="block text-sm">
                                Retiro de seguridad
                            </strong>
                            <span class="mt-1 block text-xs opacity-80">
                                Solicitar autorización y, cuando corresponda, ejecutar la extracción.
                            </span>
                        </button>

                        <button
                            type="button"
                            @click="operation = 'close'"
                            :class="operation === 'close'
                                ? 'border-fuchsia-300 bg-fuchsia-400/15 text-fuchsia-100'
                                : 'border-slate-700 bg-slate-950/40 text-slate-300 hover:border-slate-500'"
                            class="rounded-xl border px-4 py-4 text-left transition"
                            data-select-cash-close
                        >
                            <strong class="block text-sm">
                                Arqueo y cierre
                            </strong>
                            <span class="mt-1 block text-xs opacity-80">
                                Contar efectivo, registrar diferencia y cerrar el turno.
                            </span>
                        </button>
                    </div>

                    <div
                        x-cloak
                        x-show="operation === 'security_drop'"
                        class="mt-4 rounded-2xl border border-amber-400/20 bg-amber-400/5 p-4"
                        data-security-drop-operation
                    >
                        <p class="text-sm font-black text-amber-100">
                            Retiro de seguridad · flujo autorizado
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Solicitar o autorizar no mueve dinero. El efectivo esperado cambia sólo cuando el cajero ejecuta una autorización vigente.
                        </p>

                        @if($hasBlockingDropRequests)
                            <div class="mt-4 space-y-3" data-own-security-drop-active>
                                @foreach($ownDropRequests as $dropRequest)
                                    @if($dropRequest->status->blocksClosing())
                                        <article
                                            id="own-security-drop-{{ $dropRequest->public_id }}"
                                            class="rounded-xl border border-amber-300/20 bg-slate-950/50 p-4"
                                        >
                                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                                <div>
                                                    <p class="text-xs font-black uppercase tracking-wider text-amber-300">
                                                        {{ $dropRequest->status->label() }}
                                                    </p>
                                                    <p class="mt-2 font-mono text-lg font-black text-white">
                                                        {{ $dropRequest->currency_code }}
                                                        {{ number_format($dropRequest->amount_minor / 100, 2, ',', '.') }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-slate-400">
                                                        destino: {{ $dropRequest->destinationFinancialAccount?->name ?? 'Tesorería' }}
                                                        · {{ $dropRequest->reason_code->label() }}
                                                    </p>
                                                    @if($dropRequest->note)
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            {{ $dropRequest->note }}
                                                        </p>
                                                    @endif
                                                    @if($dropRequest->approvedBy)
                                                        <p class="mt-2 text-xs text-emerald-300">
                                                            Autorizado por {{ $dropRequest->approvedBy->name }}
                                                            {{ $dropRequest->approved_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                                        </p>
                                                    @endif
                                                </div>

                                                @if($dropRequest->status === \App\Enums\CashSecurityDropRequestStatus::Approved)
                                                    <form
                                                        method="POST"
                                                        action="{{ route('cash-registers.security-drop-requests.execute', $dropRequest) }}"
                                                        class="w-full max-w-sm rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-3"
                                                    >
                                                        @csrf
                                                        <input type="hidden" name="operation" value="security_drop">
                                                        <input
                                                            type="hidden"
                                                            name="idempotency_key"
                                                            value="cash-ui:security-drop-execute:{{ \Illuminate\Support\Str::uuid() }}"
                                                        >
                                                        <label class="flex items-start gap-2 text-xs text-slate-300">
                                                            <input
                                                                type="checkbox"
                                                                name="confirm_execute"
                                                                value="1"
                                                                required
                                                                class="mt-0.5 rounded border-slate-600 bg-slate-950 text-emerald-400 focus:ring-emerald-400"
                                                            >
                                                            <span>
                                                                Confirmo que voy a extraer físicamente este importe y entregarlo al destino autorizado.
                                                            </span>
                                                        </label>
                                                        <button
                                                            type="submit"
                                                            class="mt-3 w-full rounded-lg bg-emerald-300 px-4 py-2 text-xs font-black text-slate-950 hover:bg-emerald-200"
                                                        >
                                                            Ejecutar retiro autorizado
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>

                                            <form
                                                method="POST"
                                                action="{{ route('cash-registers.security-drop-requests.cancel', $dropRequest) }}"
                                                class="mt-4 flex flex-col gap-2 sm:flex-row"
                                            >
                                                @csrf
                                                <input type="hidden" name="operation" value="security_drop">
                                                <input
                                                    type="hidden"
                                                    name="idempotency_key"
                                                    value="cash-ui:security-drop-resolution:{{ \Illuminate\Support\Str::uuid() }}"
                                                >
                                                <input
                                                    type="text"
                                                    name="resolution_note"
                                                    maxlength="1000"
                                                    required
                                                    placeholder="Motivo de cancelación"
                                                    class="min-w-0 flex-1 rounded-lg border-slate-700 bg-slate-950 text-sm text-white focus:border-slate-500 focus:ring-slate-500"
                                                >
                                                <button
                                                    type="submit"
                                                    class="rounded-lg border border-slate-600 px-4 py-2 text-xs font-black text-slate-300 hover:border-slate-400 hover:text-white"
                                                >
                                                    Cancelar solicitud
                                                </button>
                                            </form>
                                        </article>
                                    @endif
                                @endforeach
                            </div>
                        @elseif($treasuryAccounts->isEmpty())
                            <div class="mt-4 rounded-xl border border-slate-700 bg-slate-950/50 px-4 py-3 text-sm text-slate-400">
                                No hay una cuenta activa de tipo
                                <strong class="text-white">Reserva / Tesorería de efectivo</strong>
                                para {{ $currentSession->currency_code }}.
                                Un administrador debe crearla en Cuentas financieras.
                            </div>
                        @else
                            <form
                                method="POST"
                                action="{{ route('cash-registers.security-drop-requests.store') }}"
                                class="mt-4 grid gap-3 lg:grid-cols-2"
                                data-security-drop-request-form
                            >
                                @csrf
                                <input type="hidden" name="operation" value="security_drop">
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value="cash-ui:security-drop-request:{{ \Illuminate\Support\Str::uuid() }}"
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
                                        Importe solicitado
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

                                <div class="lg:col-span-2 rounded-xl border border-amber-400/20 bg-slate-950/40 p-3 text-xs text-slate-400">
                                    La solicitud queda ligada a esta caja, turno, origen, destino, importe, moneda, motivo y nota. Si esos datos cambian, se necesita una nueva autorización.
                                </div>

                                <div class="lg:col-span-2 flex justify-end">
                                    <button
                                        type="submit"
                                        class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-black text-slate-950 hover:bg-amber-300"
                                    >
                                        Solicitar autorización
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>

                    <div
                        x-cloak
                        x-show="operation === 'close'"
                        class="mt-4 rounded-2xl border border-fuchsia-400/20 bg-fuchsia-400/5 p-4"
                        data-cash-close-form
                    >
                        <p class="text-sm font-black text-fuchsia-100">
                            Arqueo y cierre
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Contá físicamente el efectivo. SRCM congela el esperado al cerrar y conserva cualquier diferencia como evidencia.
                        </p>

                        @if($hasBlockingDropRequests)
                            <div class="mt-4 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
                                Hay una solicitud de retiro pendiente o autorizada. Resolvela o cancelala antes de cerrar el turno.
                            </div>
                        @endif

                        <form
                            method="POST"
                            action="{{ route('cash-registers.close') }}"
                            class="mt-4 grid gap-3 lg:grid-cols-2"
                        >
                            @csrf
                            <input type="hidden" name="operation" value="close">
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value="cash-ui:close:{{ \Illuminate\Support\Str::uuid() }}"
                            >

                            <div class="rounded-xl border border-slate-700 bg-slate-950/50 p-3">
                                <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">
                                    Esperado por SRCM
                                </p>
                                <p class="mt-2 font-mono text-lg font-black text-white">
                                    {{ $currentSession->currency_code }}
                                    {{ number_format(($currentExpectedMinor ?? 0) / 100, 2, ',', '.') }}
                                </p>
                            </div>

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Efectivo contado
                                </span>
                                <input
                                    type="text"
                                    name="counted_amount"
                                    x-model="counted"
                                    value="{{ old('counted_amount') }}"
                                    inputmode="decimal"
                                    placeholder="0,00"
                                    required
                                    @disabled($hasBlockingDropRequests)
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-white focus:border-fuchsia-400 focus:ring-fuchsia-400 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                            </label>

                            <div class="rounded-xl border border-slate-700 bg-slate-950/50 p-3 lg:col-span-2">
                                <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">
                                    Diferencia estimada
                                </p>
                                <p
                                    class="mt-2 font-mono text-lg font-black text-white"
                                    x-text="differenceLabel()"
                                >—</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Diferencia = contado − esperado. SRCM nunca la corrige con un movimiento automático.
                                </p>
                            </div>

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Motivo de diferencia
                                </span>
                                <select
                                    name="difference_reason"
                                    @disabled($hasBlockingDropRequests)
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-fuchsia-400 focus:ring-fuchsia-400 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <option value="">Sin diferencia / no aplica</option>
                                    @foreach($differenceReasons as $reason)
                                        <option
                                            value="{{ $reason->value }}"
                                            @selected(old('difference_reason') === $reason->value)
                                        >
                                            {{ $reason->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    Obligatorio si contado y esperado no coinciden.
                                </p>
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">
                                    Nota de cierre
                                </span>
                                <input
                                    type="text"
                                    name="closing_note"
                                    value="{{ old('closing_note') }}"
                                    maxlength="1000"
                                    placeholder="Obligatoria si existe diferencia"
                                    @disabled($hasBlockingDropRequests)
                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-fuchsia-400 focus:ring-fuchsia-400 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                            </label>

                            <label class="lg:col-span-2 flex items-start gap-3 rounded-xl border border-fuchsia-400/20 bg-slate-950/40 p-4">
                                <input
                                    type="checkbox"
                                    name="confirm_close"
                                    value="1"
                                    required
                                    @disabled($hasBlockingDropRequests)
                                    class="mt-0.5 rounded border-slate-600 bg-slate-950 text-fuchsia-400 focus:ring-fuchsia-400 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                <span>
                                    <strong class="block text-sm text-white">
                                        Confirmo que conté físicamente el efectivo y quiero cerrar este turno.
                                    </strong>
                                    <span class="mt-1 block text-xs text-slate-500">
                                        La hora la fija el servidor. El cierre es inmutable y libera la caja para un turno posterior.
                                    </span>
                                </span>
                            </label>

                            <div class="lg:col-span-2 flex justify-end">
                                <button
                                    type="submit"
                                    @disabled($hasBlockingDropRequests)
                                    class="rounded-xl bg-fuchsia-300 px-5 py-3 text-sm font-black text-slate-950 hover:bg-fuchsia-200 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Cerrar turno
                                </button>
                            </div>
                        </form>
                    </div>
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

        @if($pendingDropApprovals->isNotEmpty())
            <section class="sulu-card overflow-hidden" data-security-drop-approval-queue>
                <div class="border-b border-white/10 px-5 py-4">
                    <p class="text-sm font-bold text-white">
                        Autorizaciones de retiros pendientes
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        Supervisión autoriza la solicitud; el cajero responsable ejecuta luego la extracción física.
                    </p>
                </div>

                <div class="divide-y divide-white/5">
                    @foreach($pendingDropApprovals as $dropRequest)
                        <article
                            id="approval-security-drop-{{ $dropRequest->public_id }}"
                            class="scroll-mt-28 px-5 py-4"
                        >
                            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                                <div>
                                    <p class="font-bold text-white">
                                        {{ $dropRequest->register?->name ?? 'Caja' }}
                                        · {{ $dropRequest->requestedBy?->name ?? 'Usuario' }}
                                    </p>
                                    <p class="mt-1 font-mono text-lg font-black text-amber-200">
                                        {{ $dropRequest->currency_code }}
                                        {{ number_format($dropRequest->amount_minor / 100, 2, ',', '.') }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        destino: {{ $dropRequest->destinationFinancialAccount?->name ?? 'Tesorería' }}
                                        · {{ $dropRequest->reason_code->label() }}
                                        · solicitado {{ $dropRequest->requested_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                    </p>
                                    @if($dropRequest->note)
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $dropRequest->note }}
                                        </p>
                                    @endif
                                </div>

                                @if((int) $dropRequest->requested_by_user_id === (int) auth()->id())
                                    <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3 text-xs text-amber-100">
                                        Requiere otro administrador: el solicitante no puede autoautorizarse.
                                    </div>
                                @else
                                    <div class="grid gap-2 sm:grid-cols-2 lg:min-w-[34rem]">
                                        <form
                                            method="POST"
                                            action="{{ route('cash-registers.security-drop-requests.approve', $dropRequest) }}"
                                            class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-3"
                                        >
                                            @csrf
                                            <input
                                                type="hidden"
                                                name="idempotency_key"
                                                value="cash-ui:security-drop-approve:{{ \Illuminate\Support\Str::uuid() }}"
                                            >
                                            <input
                                                type="text"
                                                name="approval_note"
                                                maxlength="1000"
                                                placeholder="Nota de autorización (opcional)"
                                                class="w-full rounded-lg border-slate-700 bg-slate-950 text-xs text-white focus:border-emerald-400 focus:ring-emerald-400"
                                            >
                                            <button
                                                type="submit"
                                                class="mt-2 w-full rounded-lg bg-emerald-300 px-4 py-2 text-xs font-black text-slate-950 hover:bg-emerald-200"
                                            >
                                                Autorizar
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('cash-registers.security-drop-requests.reject', $dropRequest) }}"
                                            class="rounded-xl border border-red-400/20 bg-red-400/5 p-3"
                                        >
                                            @csrf
                                            <input
                                                type="hidden"
                                                name="idempotency_key"
                                                value="cash-ui:security-drop-resolution:{{ \Illuminate\Support\Str::uuid() }}"
                                            >
                                            <input
                                                type="text"
                                                name="resolution_note"
                                                maxlength="1000"
                                                required
                                                placeholder="Motivo del rechazo"
                                                class="w-full rounded-lg border-slate-700 bg-slate-950 text-xs text-white focus:border-red-400 focus:ring-red-400"
                                            >
                                            <button
                                                type="submit"
                                                class="mt-2 w-full rounded-lg border border-red-400/50 px-4 py-2 text-xs font-black text-red-200 hover:bg-red-400/10"
                                            >
                                                Rechazar
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if($recentClosures->isNotEmpty())
            <section class="sulu-card overflow-hidden" data-cash-closure-history>
                <div class="border-b border-white/10 px-5 py-4">
                    <p class="text-sm font-bold text-white">
                        Últimos arqueos y cierres
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        Historial inmutable. Supervisión ve la organización; cada operador ve sus propios cierres.
                    </p>
                </div>

                <div class="divide-y divide-white/5">
                    @foreach($recentClosures as $closure)
                        <article class="grid gap-3 px-5 py-4 lg:grid-cols-[minmax(0,1fr)_repeat(3,minmax(8rem,0.35fr))] lg:items-center">
                            <div>
                                <p class="font-bold text-white">
                                    {{ $closure->register?->name ?? 'Caja' }}
                                    · {{ $closure->openedBy?->name ?? 'Usuario' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    cerrado {{ $closure->closed_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                                    · por {{ $closure->closedBy?->name ?? 'Usuario' }}
                                    @if($closure->difference_reason)
                                        · {{ $closure->difference_reason->label() }}
                                    @endif
                                </p>
                                @if($closure->note)
                                    <p class="mt-1 text-xs text-slate-400">
                                        {{ $closure->note }}
                                    </p>
                                @endif
                            </div>

                            <div>
                                <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Esperado</p>
                                <p class="mt-1 font-mono font-bold text-white">
                                    {{ $closure->currency_code }}
                                    {{ number_format($closure->expected_amount_minor / 100, 2, ',', '.') }}
                                </p>
                            </div>

                            <div>
                                <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Contado</p>
                                <p class="mt-1 font-mono font-bold text-white">
                                    {{ $closure->currency_code }}
                                    {{ number_format($closure->counted_amount_minor / 100, 2, ',', '.') }}
                                </p>
                            </div>

                            <div>
                                <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Diferencia</p>
                                <p class="mt-1 font-mono font-black {{ $closure->difference_minor === 0 ? 'text-emerald-300' : 'text-amber-300' }}">
                                    {{ $closure->currency_code }}
                                    {{ number_format($closure->difference_minor / 100, 2, ',', '.') }}
                                </p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

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
