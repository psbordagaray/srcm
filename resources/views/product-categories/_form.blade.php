@csrf

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
        value="{{ old('name', $category->name ?? '') }}"
        required
        autofocus
        maxlength="100"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
    >

    @error('name')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div>
    <label
        for="icon"
        class="block text-sm font-medium text-slate-300"
    >
        Icono
    </label>

    <input
        id="icon"
        name="icon"
        type="text"
        value="{{ old('icon', $category->icon ?? '') }}"
        maxlength="60"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
    >

    @error('icon')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div>
    <label
        for="description"
        class="block text-sm font-medium text-slate-300"
    >
        Descripción
    </label>

    <textarea
        id="description"
        name="description"
        rows="4"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white"
    >{{ old('description', $category->description ?? '') }}</textarea>

    @error('description')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<label class="flex items-center gap-3">

    <input
        type="checkbox"
        name="active"
        value="1"
        @checked(old('active', $category->active ?? true))
    >

    <span class="text-slate-300">
        Categoría activa
    </span>

</label>