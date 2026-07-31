@csrf

@if($errors->any())
    <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
        Revisá los campos marcados antes de guardar la ubicación.
    </div>
@endif

<div>
    <label
        for="name"
        class="block text-sm font-medium text-slate-300"
    >
        Nombre
    </label>

    <input
        id="name"
        name="name"
        type="text"
        value="{{ old('name', $inventoryLocation->name ?? '') }}"
        required
        autofocus
        maxlength="255"
        placeholder="Ejemplo: Estantería A"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-600"
    >

    @error('name')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div>
    <label
        for="type"
        class="block text-sm font-medium text-slate-300"
    >
        Tipo de ubicación
    </label>

    <select
        id="type"
        name="type"
        required
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
    >
        <option value="">Seleccionar tipo...</option>

        @foreach($types as $locationType)
            <option
                value="{{ $locationType->value }}"
                @selected(
                    old(
                        'type',
                        isset($inventoryLocation)
                            ? $inventoryLocation->type->value
                            : ''
                    ) === $locationType->value
                )
            >
                {{ $locationType->label() }}
            </option>
        @endforeach
    </select>

    @error('type')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div>
    <label
        for="parent_id"
        class="block text-sm font-medium text-slate-300"
    >
        Ubicación superior
    </label>

    <select
        id="parent_id"
        name="parent_id"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
    >
        <option value="">
            Nivel principal de la organización
        </option>

        @foreach($parentRows as $row)
            <option
                value="{{ $row['location']->id }}"
                @selected(
                    (string) old(
                        'parent_id',
                        $inventoryLocation->parent_id ?? ''
                    ) === (string) $row['location']->id
                )
            >
                {{ str_repeat('— ', $row['depth']) }}{{ $row['path'] }}
            </option>
        @endforeach
    </select>

    <p class="mt-2 text-xs leading-5 text-slate-500">
        La organización es la raíz. Podés crear sucursales principales o ubicar este elemento dentro de otra ubicación activa.
    </p>

    @error('parent_id')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div class="rounded-xl border border-amber-400/10 bg-amber-400/5 px-4 py-3 text-sm leading-6 text-slate-400">
    SRCM impedirá guardar padres de otra organización, ciclos jerárquicos y nombres equivalentes dentro del mismo nivel.
</div>
