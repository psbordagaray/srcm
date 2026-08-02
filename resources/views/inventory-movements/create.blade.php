<x-app-layout>
    @php
        $oldLines = old('lines', [[
            'condition' => 'new',
            'entered_quantity' => '',
        ]]);
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Inventario · Libro operativo</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Nuevo movimiento</h1>
                <p class="mt-2 text-sm text-slate-400">Creá un borrador verificable. El stock no cambia hasta su confirmación.</p>
            </div>
            <a href="{{ route('inventory-movements.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al libro</a>
        </div>

        @if(session('error'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <p class="font-semibold">Revisá el borrador:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if($products->isEmpty() || $locations->isEmpty())
            <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                Para crear movimientos se necesita al menos un producto activo y una ubicación activa en la organización.
            </div>
        @endif

        <form method="POST" action="{{ route('inventory-movements.store') }}" class="space-y-6" data-movement-form>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
                <h2 class="text-base font-semibold text-white">Cabecera del movimiento</h2>
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-300">Tipo</span>
                        <select name="type" required data-movement-type class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Seleccionar tipo</option>
                            @foreach($types as $movementType)
                                <option value="{{ $movementType->value }}" @selected(old('type') === $movementType->value)>{{ $movementType->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-slate-300">Fecha efectiva</span>
                        <input type="datetime-local" name="effective_at" value="{{ old('effective_at', now()->format('Y-m-d\TH:i')) }}" required class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="text-sm font-semibold text-slate-300">Motivo</span>
                        <textarea name="reason" rows="3" required minlength="5" maxlength="2000" placeholder="Explicá por qué se realiza este movimiento." class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400">{{ old('reason') }}</textarea>
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="text-sm font-semibold text-slate-300">Referencia externa</span>
                        <input type="text" name="source_reference" value="{{ old('source_reference') }}" maxlength="255" placeholder="Factura, remito, orden o referencia opcional" class="mt-2 block w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400">
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-white">Líneas</h2>
                        <p class="mt-1 text-xs text-slate-500">La unidad base y la precisión se toman de cada producto.</p>
                    </div>
                    <button type="button" data-add-line class="rounded-xl border border-cyan-400/40 px-4 py-2 text-sm font-semibold text-cyan-200 transition hover:bg-cyan-400/10">Agregar línea</button>
                </div>

                <div data-lines class="mt-5 space-y-4">
                    @foreach($oldLines as $index => $line)
                        @include('inventory-movements._line', compact('index', 'line'))
                    @endforeach
                </div>
            </section>

            <div class="flex flex-wrap gap-4">
                <button type="submit" @disabled($products->isEmpty() || $locations->isEmpty()) class="rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-40">Guardar borrador</button>
                <a href="{{ route('inventory-movements.index') }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>

        <template data-line-template>
            @include('inventory-movements._line', [
                'index' => '__INDEX__',
                'line' => ['condition' => 'new'],
            ])
        </template>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-movement-form]');
            if (!form) return;

            const rules = @json($movementRules);
            const type = form.querySelector('[data-movement-type]');
            const lines = form.querySelector('[data-lines]');
            const template = document.querySelector('[data-line-template]');

            const refresh = () => {
                const rule = rules[type.value] ?? {
                    allowsSource: true,
                    allowsDestination: true,
                    requiresSource: false,
                    requiresDestination: false,
                };

                lines.querySelectorAll('[data-movement-line]').forEach((line, index) => {
                    line.querySelector('[data-line-number]').textContent = index + 1;
                    const source = line.querySelector('[data-source]');
                    const destination = line.querySelector('[data-destination]');
                    const remove = line.querySelector('[data-remove-line]');
                    source.disabled = !rule.allowsSource;
                    source.required = rule.requiresSource;
                    destination.disabled = !rule.allowsDestination;
                    destination.required = rule.requiresDestination;
                    if (!rule.allowsSource) source.value = '';
                    if (!rule.allowsDestination) destination.value = '';
                    line.querySelector('[data-source-field]').classList.toggle('opacity-40', !rule.allowsSource);
                    line.querySelector('[data-destination-field]').classList.toggle('opacity-40', !rule.allowsDestination);
                    remove.disabled = lines.children.length === 1;
                    remove.classList.toggle('opacity-30', remove.disabled);

                    const product = line.querySelector('[data-product]');
                    const option = product.options[product.selectedIndex];
                    line.querySelector('[data-product-unit]').textContent = option?.value
                        ? `Unidad: ${option.dataset.unit} · ${option.dataset.scale} decimales`
                        : '';
                });
            };

            const bind = (line) => {
                line.querySelector('[data-remove-line]').addEventListener('click', () => {
                    if (lines.children.length > 1) line.remove();
                    refresh();
                });
                line.querySelector('[data-product]').addEventListener('change', refresh);
            };

            lines.querySelectorAll('[data-movement-line]').forEach(bind);
            type.addEventListener('change', refresh);
            form.querySelector('[data-add-line]').addEventListener('click', () => {
                if (lines.children.length >= 20) return;
                const index = Date.now().toString();
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', index).trim();
                const line = wrapper.firstElementChild;
                lines.appendChild(line);
                bind(line);
                refresh();
            });
            refresh();
        });
    </script>
</x-app-layout>
