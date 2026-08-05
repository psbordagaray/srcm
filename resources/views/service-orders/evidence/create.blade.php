<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Evidencia privada</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Adjuntar archivo · Orden #{{ $order->order_number }}</h1>
                <p class="mt-2 text-sm text-slate-400">{{ $order->asset->brand_name }} {{ $order->asset->model_name }} · El archivo quedará asociado al expediente activo y no tendrá una URL pública.</p>
            </div>
            <a href="{{ route('service-orders.show', $order) }}#service-evidence" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver al expediente</a>
        </div>

        @if($errors->has('service_evidence'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('service_evidence') }}</div>
        @endif

        <section class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-5">
            <h2 class="text-sm font-bold text-cyan-200">Controles aplicados</h2>
            <p class="mt-2 text-sm leading-6 text-slate-300">SRCM validará el contenido real, tamaño y tipo MIME; calculará SHA-256; asignará un nombre interno aleatorio; almacenará el archivo fuera del directorio público y comprobará su integridad antes de confirmar el registro.</p>
        </section>

        <form method="POST" enctype="multipart/form-data" action="{{ route('service-orders.evidences.store', $order) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <h2 class="font-bold text-white">Archivo y hecho documentado</h2>
                <div class="mt-5 space-y-5">
                    <div>
                        <label for="target" class="text-sm font-semibold text-slate-200">Parte del expediente</label>
                        <select id="target" name="target" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            @foreach($targets as $target)
                                <option value="{{ $target['value'] }}" @selected(old('target', 'order') === $target['value'])>{{ $target['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('target')" class="mt-2" />
                    </div>

                    <div>
                        <label for="evidence_file" class="text-sm font-semibold text-slate-200">Archivo privado</label>
                        <input id="evidence_file" name="evidence_file" type="file" required accept="{{ $accept }}" class="mt-2 block w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-slate-300 file:mr-4 file:rounded-lg file:border-0 file:bg-cyan-400 file:px-4 file:py-2 file:font-bold file:text-slate-950 hover:file:bg-cyan-300">
                        <p class="mt-2 text-xs text-slate-500">JPG, PNG, WEBP, PDF o TXT · máximo {{ $maximumMegabytes }} MB. La extensión declarada no reemplaza la inspección del contenido.</p>
                        <x-input-error :messages="$errors->get('evidence_file')" class="mt-2" />
                    </div>

                    <div>
                        <label for="captured_at" class="text-sm font-semibold text-slate-200">Fecha y hora de captura</label>
                        <input id="captured_at" name="captured_at" type="datetime-local" step="1" value="{{ old('captured_at', now()->format('Y-m-d\TH:i:s')) }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <p class="mt-2 text-xs text-slate-500">Puede representar una captura anterior; no se aceptan fechas futuras.</p>
                        <x-input-error :messages="$errors->get('captured_at')" class="mt-2" />
                    </div>

                    <div>
                        <label for="description" class="text-sm font-semibold text-slate-200">Descripción verificable</label>
                        <textarea id="description" name="description" rows="5" maxlength="2000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400" placeholder="Qué muestra el archivo, desde qué ángulo, en qué condición y por qué es relevante.">{{ old('description') }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-cyan-300">Registrar evidencia privada</button>
                <a href="{{ route('service-orders.show', $order) }}#service-evidence" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>
    </div>
</x-app-layout>
