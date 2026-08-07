<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Operaciones · Clientes</p><h1 class="mt-2 text-2xl font-bold text-white">Clientes</h1><p class="mt-2 text-sm text-slate-400">Identidades comerciales privadas de la organización activa.</p></div>
            @can('manage-customers')<a href="{{ route('customers.create') }}" class="rounded-xl bg-cyan-400 px-5 py-2 text-sm font-bold text-slate-950">Nuevo cliente</a>@endcan
        </div>
        <form method="GET" class="sulu-card grid gap-4 p-5 md:grid-cols-[1fr_12rem_12rem_auto]">
            <input name="search" value="{{ $search }}" placeholder="Nombre, DNI/CUIT, email o teléfono" class="rounded-xl border-slate-700 bg-slate-950 text-slate-100">
            <select name="type" class="rounded-xl border-slate-700 bg-slate-950 text-slate-100"><option value="">Todos los tipos</option><option value="person" @selected($type==='person')>Persona</option><option value="organization" @selected($type==='organization')>Empresa</option></select>
            <select name="status" class="rounded-xl border-slate-700 bg-slate-950 text-slate-100"><option value="">Todos los estados</option><option value="active" @selected($status==='active')>Activos</option><option value="inactive" @selected($status==='inactive')>Inactivos</option></select>
            <button class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200">Filtrar</button>
        </form>
        <div class="sulu-card overflow-hidden">
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-800 text-sm"><thead class="bg-slate-950/60 text-left text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3">Cliente</th><th class="px-5 py-3">Contacto</th><th class="px-5 py-3">Tipo</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Acciones</th></tr></thead><tbody class="divide-y divide-slate-800">
            @forelse($customers as $customer)
                <tr><td class="px-5 py-4"><a class="font-semibold text-white hover:text-cyan-300" href="{{ route('customers.show',$customer) }}">{{ $customer->party->name }}</a><div class="mt-1 text-xs text-slate-500">{{ $customer->party->tax_id ?: 'Sin documento' }}</div></td><td class="px-5 py-4 text-slate-300">{{ $customer->party->phone ?: '—' }}<div class="text-xs text-slate-500">{{ $customer->party->email ?: '—' }}</div></td><td class="px-5 py-4 text-slate-300">{{ $customer->party->party_type === 'person' ? 'Persona' : 'Empresa' }}</td><td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs {{ $customer->active ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' : 'border-slate-600 bg-slate-800 text-slate-400' }}">{{ $customer->active ? 'Activo' : 'Inactivo' }}</span></td><td class="px-5 py-4 text-right">@can('manage-customers')<div class="flex justify-end gap-2"><a href="{{ route('customers.edit',$customer) }}" class="text-cyan-300">Editar</a><form method="POST" action="{{ route('customers.toggle-active',$customer) }}">@csrf @method('PATCH')<button class="text-amber-300">{{ $customer->active ? 'Inactivar' : 'Activar' }}</button></form></div>@endcan</td></tr>
            @empty<tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No hay clientes para los filtros seleccionados.</td></tr>@endforelse
            </tbody></table></div>
        </div>
        {{ $customers->links() }}
    </div>
</x-app-layout>
