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
        value="{{ old('name', $brand->name ?? '') }}"
        required
        autofocus
        maxlength="100"
        placeholder="Ejemplo: Samsung"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
    >

    @error('name')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div>
    <label
        for="logo"
        class="block text-sm font-medium text-slate-300"
    >
        Logotipo
    </label>

    <input
        id="logo"
        name="logo"
        type="text"
        value="{{ old('logo', $brand->logo ?? '') }}"
        maxlength="255"
        placeholder="URL o referencia del archivo"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
    >

    @error('logo')
        <p class="mt-2 text-sm text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>

<div>
    <label
        for="website"
        class="block text-sm font-medium text-slate-300"
    >
        Sitio web principal
    </label>

    <input
        id="website"
        name="website"
                        inputmode="url"
                        autocapitalize="none"
                        spellcheck="false"
        type="text"
        value="{{ old('website', $brand->website ?? '') }}"
        maxlength="255"
        placeholder="https://www.samsung.com"
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
    >

    @error('website')
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
        placeholder="Información general y alcance comercial de la marca."
        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
    >{{ old('description', $brand->description ?? '') }}</textarea>

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
        @checked(old('active', $brand->active ?? true))
        class="rounded border-slate-700 bg-slate-950 text-cyan-400 focus:ring-cyan-400"
    >

    <span class="text-sm font-medium text-slate-300">
        Marca activa
    </span>
</label>