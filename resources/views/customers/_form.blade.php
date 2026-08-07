@php
    $party = $customer?->party;
@endphp

@if($errors->any())
    <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
        <ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-2">
    <div class="sulu-card p-6">
        <h2 class="text-lg font-bold text-white">Identidad comercial</h2>
        <p class="mt-1 text-sm text-slate-500">Una misma identidad puede actuar como cliente, proveedor o ambas cosas sin duplicarse.</p>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label class="text-sm font-semibold text-slate-200" for="party_type">Tipo</label>
                <select id="party_type" name="party_type" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
                    <option value="person" @selected(old('party_type',$party?->party_type ?? 'person') === 'person')>Persona</option>
                    <option value="organization" @selected(old('party_type',$party?->party_type) === 'organization')>Empresa / organización</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-200" for="name">Nombre / razón social</label>
                <input id="name" name="name" required maxlength="255" value="{{ old('name',$party?->name) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-200" for="tax_id">DNI / CUIT / identificación fiscal</label>
                <input id="tax_id" name="tax_id" maxlength="64" value="{{ old('tax_id',$party?->tax_id) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-200" for="email">Correo</label>
                <input id="email" name="email" type="email" maxlength="255" value="{{ old('email',$party?->email) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-200" for="phone">Teléfono</label>
                <input id="phone" name="phone" maxlength="80" value="{{ old('phone',$party?->phone) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-200" for="website">Sitio web</label>
                <input id="website" name="website" maxlength="2048" value="{{ old('website',$party?->website) }}" placeholder="https://..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">
            </div>
        </div>
    </div>
    <div class="sulu-card p-6">
        <h2 class="text-lg font-bold text-white">Rol cliente</h2>
        <div class="mt-5">
            <label class="text-sm font-semibold text-slate-200" for="notes">Notas comerciales</label>
            <textarea id="notes" name="notes" rows="8" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100">{{ old('notes',$customer?->notes) }}</textarea>
        </div>
        <input type="hidden" name="active" value="0">
        <label class="mt-5 flex items-center gap-3 text-sm text-slate-200">
            <input type="checkbox" name="active" value="1" @checked((bool) old('active',$customer?->active ?? true)) class="rounded border-slate-700 bg-slate-950 text-cyan-400">
            Cliente activo para operaciones nuevas
        </label>
    </div>
</div>

<div class="flex justify-end gap-3">
    <a href="{{ route('customers.index') }}" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300">Cancelar</a>
    <button class="rounded-xl bg-cyan-400 px-5 py-2 text-sm font-bold text-slate-950">{{ $submitLabel }}</button>
</div>
