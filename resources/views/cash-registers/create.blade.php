<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">
                Finanzas · Caja operativa
            </p>
            <h1 class="mt-2 text-2xl font-bold text-white">Nueva caja operativa</h1>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('cash-registers.store') }}" class="sulu-card space-y-5 p-6">
            @csrf

            <label class="block">
                <span class="text-sm font-bold text-slate-300">Nombre</span>
                <input
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    placeholder="Caja 3"
                    maxlength="120"
                    required
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-emerald-400 focus:ring-emerald-400"
                >
            </label>

            <label class="block">
                <span class="text-sm font-bold text-slate-300">Cuenta financiera de efectivo</span>
                <select
                    name="financial_account_id"
                    required
                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
                >
                    <option value="">Seleccionar cuenta…</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('financial_account_id') === (string) $account->id)>
                            {{ $account->name }} · {{ $account->currency_code }}
                        </option>
                    @endforeach
                </select>
            </label>

            @if($accounts->isEmpty())
                <p class="rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
                    No hay cuentas cash_box libres. Creá primero una cuenta financiera de tipo Caja de efectivo.
                </p>
            @endif

            <div class="flex justify-end gap-2">
                <a href="{{ route('cash-registers.index') }}" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-bold text-slate-300">
                    Volver
                </a>
                <button type="submit" class="rounded-xl bg-amber-400 px-5 py-2 text-sm font-black text-slate-950" @disabled($accounts->isEmpty())>
                    Crear caja
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
