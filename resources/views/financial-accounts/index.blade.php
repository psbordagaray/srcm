<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6" data-financial-accounts-index>
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">
                    Finanzas · Destinos privados
                </p>
                <h1 class="mt-2 text-2xl font-bold text-white">Cuentas financieras</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                    Representan dónde pertenece cada cobro declarado: caja, banco, billetera o procesador.
                    La cuenta no prueba acreditación ni conciliación por sí sola.
                </p>
            </div>

            @can('manage-financial-accounts')
                <a
                    href="{{ route('financial-accounts.create') }}"
                    class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-black text-slate-950 hover:bg-amber-300"
                >
                    Nueva cuenta
                </a>
            @endcan
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

        <section class="sulu-card overflow-hidden">
            <div class="border-b border-white/10 px-5 py-4">
                <p class="text-sm font-bold text-white">{{ $accounts->count() }} cuenta{{ $accounts->count() === 1 ? '' : 's' }}</p>
                <p class="mt-1 text-xs text-slate-500">Sólo las activas y de la moneda de la venta pueden utilizarse como destino en Terminal.</p>
            </div>

            @if($accounts->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="font-bold text-slate-200">Todavía no hay cuentas financieras.</p>
                    <p class="mt-2 text-sm text-slate-500">Un administrador debe crear al menos una antes de asignar destinos de cobro.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-slate-950/50 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Cuenta</th>
                                <th class="px-5 py-3">Tipo</th>
                                <th class="px-5 py-3">Moneda</th>
                                <th class="px-5 py-3">Proveedor / referencia</th>
                                <th class="px-5 py-3">Estado</th>
                                @can('manage-financial-accounts')
                                    <th class="px-5 py-3 text-right">Acciones</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach($accounts as $account)
                                <tr class="hover:bg-white/[0.025]">
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-white">{{ $account->name }}</p>
                                        <p class="mt-1 font-mono text-[10px] text-slate-600">{{ $account->public_id }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-slate-300">{{ $account->type->label() }}</td>
                                    <td class="px-5 py-4 font-mono font-bold text-cyan-200">{{ $account->currency_code }}</td>
                                    <td class="px-5 py-4">
                                        <p class="text-slate-300">{{ $account->provider ?: '—' }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $account->external_label ?: 'Sin referencia externa' }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="rounded-full px-3 py-1 text-xs font-bold {{ $account->active ? 'bg-emerald-500/10 text-emerald-200' : 'bg-slate-700/50 text-slate-400' }}">
                                            {{ $account->active ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                    @can('manage-financial-accounts')
                                        <td class="px-5 py-4">
                                            <div class="flex justify-end gap-2">
                                                <a
                                                    href="{{ route('financial-accounts.edit', $account) }}"
                                                    class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-bold text-slate-300 hover:border-slate-500 hover:text-white"
                                                >
                                                    Editar
                                                </a>
                                                <form
                                                    method="POST"
                                                    action="{{ route('financial-accounts.toggle-active', $account) }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')
                                                    <button
                                                        type="submit"
                                                        class="rounded-lg border px-3 py-2 text-xs font-bold {{ $account->active ? 'border-red-400/20 text-red-300' : 'border-emerald-400/20 text-emerald-300' }}"
                                                    >
                                                        {{ $account->active ? 'Inactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
