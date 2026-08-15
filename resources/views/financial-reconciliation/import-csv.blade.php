<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Finanzas · P7.2
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">
                    Previsualizar extracto CSV
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
                        Archivo CSV
                    </label>
                    <input
                        type="file"
                        name="statement"
                        accept=".csv,text/csv"
                        required
                        class="sulu-input w-full"
                    >
                    @error('statement')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-xl border border-white/10 bg-slate-950/40 p-4">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-300">
                        Contrato canónico P7.1
                    </p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        UTF-8, separador coma, máximo 2 MiB y 1000 filas. Los importes usan punto decimal con hasta dos decimales. La moneda se toma de la cuenta seleccionada y no del archivo.
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
