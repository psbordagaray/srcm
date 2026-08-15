<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">
                    Finanzas · P7.5
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">
                    Registrar movimiento externo manual
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    Fallback explícito para instituciones sin una alternativa razonable de API, CSV o XLSX.
                    Registra un hecho externo posted; no modifica la venta, Caja ni concilia automáticamente.
                </p>
            </div>

            <a
                href="{{ route('financial-reconciliation.index') }}"
                class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-black text-slate-300 hover:border-slate-500 hover:text-white"
            >
                Volver al centro
            </a>
        </div>

        @if(session('error'))
            <div class="rounded-xl border border-red-400/20 bg-red-400/5 px-4 py-3 text-sm text-red-100">
                {{ session('error') }}
            </div>
        @endif

        <section class="sulu-card p-5">
            <div class="mb-5 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4 text-xs leading-5 text-amber-100">
                Usá este flujo sólo como fallback. La razón es obligatoria y queda auditada.
                No ingreses contraseñas, tokens, CVV, números completos de tarjeta ni otros secretos.
            </div>

            <form
                method="POST"
                action="{{ route('financial-manual-external-movements.store') }}"
                class="space-y-5"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ old('idempotency_key', $idempotencyKey) }}"
                >

                <div>
                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-300">
                        Cuenta financiera *
                    </label>
                    <select
                        name="financial_account"
                        required
                        class="sulu-input w-full"
                    >
                        <option value="">Seleccionar cuenta</option>
                        @foreach($accounts as $account)
                            <option
                                value="{{ $account->public_id }}"
                                @selected(old('financial_account') === $account->public_id)
                            >
                                {{ $account->name }} · {{ $account->currency_code }} · {{ $account->type->value }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-300">
                            Dirección *
                        </label>
                        <select
                            name="direction"
                            required
                            class="sulu-input w-full"
                        >
                            <option value="credit" @selected(old('direction', 'credit') === 'credit')>
                                Crédito / ingreso
                            </option>
                            <option value="debit" @selected(old('direction') === 'debit')>
                                Débito / egreso
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-300">
                            Momento externo *
                        </label>
                        <input
                            type="datetime-local"
                            name="occurred_at"
                            value="{{ old('occurred_at', $defaultOccurredAt) }}"
                            required
                            class="sulu-input w-full"
                        >
                        <p class="mt-1 text-[10px] text-slate-500">
                            Zona mostrada: {{ $displayTimezone }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <label class="text-xs text-slate-400">
                        Bruto *
                        <input
                            name="gross_amount"
                            value="{{ old('gross_amount') }}"
                            required
                            inputmode="decimal"
                            placeholder="1000,00"
                            class="sulu-input mt-1 w-full"
                        >
                    </label>

                    <label class="text-xs text-slate-400">
                        Comisión
                        <input
                            name="fee_amount"
                            value="{{ old('fee_amount', '0') }}"
                            inputmode="decimal"
                            placeholder="0,00"
                            class="sulu-input mt-1 w-full"
                        >
                    </label>

                    <label class="text-xs text-slate-400">
                        Retención
                        <input
                            name="withholding_amount"
                            value="{{ old('withholding_amount', '0') }}"
                            inputmode="decimal"
                            placeholder="0,00"
                            class="sulu-input mt-1 w-full"
                        >
                    </label>

                    <label class="text-xs text-slate-400">
                        Neto *
                        <input
                            name="net_amount"
                            value="{{ old('net_amount') }}"
                            required
                            inputmode="decimal"
                            placeholder="1000,00"
                            class="sulu-input mt-1 w-full"
                        >
                    </label>
                </div>

                <p class="text-xs text-slate-500">
                    Debe cumplirse: bruto = neto + comisión + retención. La moneda la determina la cuenta.
                </p>

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="text-xs text-slate-400">
                        ID de operación externa
                        <input
                            name="external_operation_id"
                            value="{{ old('external_operation_id') }}"
                            maxlength="191"
                            class="sulu-input mt-1 w-full"
                        >
                    </label>

                    <label class="text-xs text-slate-400">
                        Referencia externa segura
                        <input
                            name="reference"
                            value="{{ old('reference') }}"
                            maxlength="500"
                            class="sulu-input mt-1 w-full"
                        >
                    </label>
                </div>

                <div>
                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-amber-200">
                        Motivo del fallback manual *
                    </label>
                    <textarea
                        name="manual_reason"
                        rows="3"
                        required
                        minlength="10"
                        maxlength="500"
                        placeholder="Ej.: la institución no ofrece API ni exportación utilizable para este movimiento."
                        class="sulu-input w-full"
                    >{{ old('manual_reason') }}</textarea>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-xl border border-amber-400/30 bg-amber-400/10 px-5 py-3 text-sm font-black text-amber-100 hover:border-amber-300"
                    >
                        Registrar movimiento manual
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>
