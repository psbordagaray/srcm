<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Finanzas · P7.4
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">
                    Previsualizar extracto CSV/XLSX
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    Primero valida y normaliza sin tocar el libro financiero. Sólo una confirmación posterior y explícita importa movimientos; nunca concilia automáticamente.
                </p>
            </div>

            <a
                href="{{ route('financial-reconciliation.index') }}"
                class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-black text-slate-300 hover:border-slate-500 hover:text-white"
            >
                Volver al Centro
            </a>
        </div>

        @if($errors->has('statement_import'))
            <div class="rounded-xl border border-rose-400/20 bg-rose-400/5 px-4 py-3 text-sm text-rose-200">
                {{ $errors->first('statement_import') }}
            </div>
        @endif

        <section class="sulu-card p-5">
            <form
                method="POST"
                action="{{ route('financial-statement-imports.csv.preview') }}"
                enctype="multipart/form-data"
                class="space-y-5"
            >
                @csrf

                <div>
                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-400">
                        Cuenta financiera
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
                                {{ $account->name }} · {{ $account->type->label() }} · {{ $account->currency_code }}
                            </option>
                        @endforeach
                    </select>
                    @error('financial_account')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-400">
                        Archivo CSV o XLSX
                    </label>
                    <input
                        type="file"
                        name="statement"
                        accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                        class="sulu-input w-full"
                    >
                    @error('statement')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-xl border border-cyan-400/20 bg-cyan-400/5 p-4">
                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-cyan-200">
                        Modo de columnas
                    </label>
                    <select
                        name="mapping_mode"
                        class="sulu-input w-full"
                    >
                        <option
                            value="canonical"
                            @selected(old('mapping_mode', 'canonical') === 'canonical')
                        >
                            Canónico SRCM
                        </option>
                        <option
                            value="mapped"
                            @selected(old('mapping_mode') === 'mapped')
                        >
                            Mapear columnas del extracto
                        </option>
                    </select>

                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs font-black text-cyan-200">
                            Configurar mapeo no canónico
                        </summary>

                        <p class="mt-3 text-xs leading-5 text-slate-400">
                            Usá los nombres exactos de la cabecera del banco o billetera. Las columnas opcionales pueden quedar vacías. No uses separadores de miles en importes. En XLSX el separador de archivo se ignora; el separador decimal sigue definiendo cómo se normalizan celdas numéricas.
                        </p>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <label class="text-xs text-slate-400">
                                Separador CSV (sólo CSV)
                                <select name="mapping_delimiter" class="sulu-input mt-1 w-full">
                                    <option value="comma" @selected(old('mapping_delimiter', 'comma') === 'comma')>Coma (,)</option>
                                    <option value="semicolon" @selected(old('mapping_delimiter') === 'semicolon')>Punto y coma (;)</option>
                                    <option value="tab" @selected(old('mapping_delimiter') === 'tab')>Tabulación</option>
                                </select>
                            </label>

                            <label class="text-xs text-slate-400">
                                Separador decimal
                                <select name="mapping_decimal_separator" class="sulu-input mt-1 w-full">
                                    <option value="dot" @selected(old('mapping_decimal_separator', 'dot') === 'dot')>Punto (1234.56)</option>
                                    <option value="comma" @selected(old('mapping_decimal_separator') === 'comma')>Coma (1234,56)</option>
                                </select>
                            </label>

                            <label class="text-xs text-slate-400">
                                Formato de fecha
                                <select name="mapping_date_format" class="sulu-input mt-1 w-full">
                                    <option value="iso8601" @selected(old('mapping_date_format', 'iso8601') === 'iso8601')>ISO 8601 con offset</option>
                                    <option value="ymd_his" @selected(old('mapping_date_format') === 'ymd_his')>AAAA-MM-DD HH:MM:SS</option>
                                    <option value="dmy_his" @selected(old('mapping_date_format') === 'dmy_his')>DD/MM/AAAA HH:MM:SS</option>
                                    <option value="dmy" @selected(old('mapping_date_format') === 'dmy')>DD/MM/AAAA</option>
                                </select>
                            </label>

                            <label class="text-xs text-slate-400">
                                Zona horaria de fechas sin offset
                                <input
                                    name="mapping_timezone"
                                    value="{{ old('mapping_timezone', 'America/Argentina/Buenos_Aires') }}"
                                    class="sulu-input mt-1 w-full"
                                >
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna fecha *
                                <input name="mapping_occurred_at_header" value="{{ old('mapping_occurred_at_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna dirección *
                                <input name="mapping_direction_header" value="{{ old('mapping_direction_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna bruto *
                                <input name="mapping_gross_amount_header" value="{{ old('mapping_gross_amount_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna neto *
                                <input name="mapping_net_amount_header" value="{{ old('mapping_net_amount_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna comisión
                                <input name="mapping_fee_amount_header" value="{{ old('mapping_fee_amount_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna retención
                                <input name="mapping_withholding_amount_header" value="{{ old('mapping_withholding_amount_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna ID externo
                                <input name="mapping_external_operation_id_header" value="{{ old('mapping_external_operation_id_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Columna referencia
                                <input name="mapping_reference_header" value="{{ old('mapping_reference_header') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Valor que significa crédito *
                                <input name="mapping_credit_value" value="{{ old('mapping_credit_value', 'credit') }}" class="sulu-input mt-1 w-full">
                            </label>

                            <label class="text-xs text-slate-400">
                                Valor que significa débito *
                                <input name="mapping_debit_value" value="{{ old('mapping_debit_value', 'debit') }}" class="sulu-input mt-1 w-full">
                            </label>
                        </div>
                    </details>
                </div>

                <div class="rounded-xl border border-white/10 bg-slate-950/40 p-4">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-300">
                        Contrato canónico P7.1
                    </p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        CSV canónico: UTF-8, separador coma. XLSX: primera hoja, valores confirmados y sin fórmulas. Ambos: máximo 2 MiB y 1000 filas. La moneda se toma de la cuenta seleccionada y no del archivo.
                    </p>
                    <pre class="mt-3 overflow-x-auto text-[11px] leading-5 text-cyan-200">occurred_at,direction,gross_amount,fee_amount,withholding_amount,net_amount,external_operation_id,reference
2026-08-15T10:30:00-03:00,credit,60000.00,3180.00,0.00,56820.00,OP-123,"Acreditación lote 123"</pre>
                </div>

                <button
                    type="submit"
                    class="w-full rounded-xl border border-cyan-400/30 bg-cyan-400/10 px-4 py-3 text-sm font-black text-cyan-100 hover:border-cyan-300"
                >
                    Validar y previsualizar
                </button>
            </form>
        </section>
    </div>
</x-app-layout>
