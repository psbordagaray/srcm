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
                <p class="mt-2 text-xs text-slate-500">
                    P4B todavía no cierra turnos: el cierre, arqueo y diferencias llegan en el siguiente bloque.
                </p>
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
