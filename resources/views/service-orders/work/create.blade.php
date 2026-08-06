<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Ejecución</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Planificar trabajo</h1>
            <p class="mt-2 text-sm text-slate-400">Orden #{{ $order->order_number }} · {{ $order->asset->brand_name }} {{ $order->asset->model_name }}</p>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <p class="font-semibold">Revisá la información ingresada.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="sulu-card p-6">
            @if($warrantyResolution)
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">Cobertura de garantía</p>
                <h2 class="mt-2 text-lg font-bold text-white">Alcance correctivo autorizado</h2>
                <p class="mt-3 whitespace-pre-line text-sm text-slate-300">{{ $warrantyResolution->covered_scope }}</p>
                @if($warrantyResolution->excluded_scope)
                    <p class="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs text-amber-200"><strong>Exclusiones:</strong> {{ $warrantyResolution->excluded_scope }}</p>
                @endif
            @else
                <p class="text-xs font-bold uppercase tracking-wider text-amber-300">Presupuesto aprobado</p>
                <h2 class="mt-2 text-lg font-bold text-white">{{ $approvedOption->label }}</h2>
                <div class="mt-4 space-y-2">
                    @foreach($approvedOption->lines as $line)
                        <div class="flex justify-between gap-4 rounded-lg border border-slate-800 bg-slate-950/50 px-3 py-2 text-xs">
                            <span class="text-slate-300">{{ $line->line_type->label() }} · {{ $line->description }}</span>
                            <span class="whitespace-nowrap text-amber-200">$ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <form method="POST" action="{{ route('service-orders.work-items.store', $order) }}" class="sulu-card space-y-6 p-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="title" class="text-sm font-semibold text-slate-200">Nombre del trabajo</label>
                    <input id="title" name="title" type="text" maxlength="200" required value="{{ old('title') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400" placeholder="Ej.: Reemplazo de módulo y pruebas">
                </div>
                <div>
                    <label for="execution_mode" class="text-sm font-semibold text-slate-200">Modalidad</label>
                    <select id="execution_mode" name="execution_mode" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">Seleccionar</option>
                        @foreach($executionModes as $mode)
                            <option value="{{ $mode->value }}" @selected(old('execution_mode') === $mode->value)>{{ $mode->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">Elegí trabajo propio para un miembro interno o tercerizado para un especialista registrado.</p>
                </div>
            </div>

            <div>
                <label for="description" class="text-sm font-semibold text-slate-200">Alcance técnico</label>
                <textarea id="description" name="description" rows="5" maxlength="5000" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400" placeholder="Describí qué debe hacerse y qué resultado se espera.">{{ old('description') }}</textarea>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                    <label for="assigned_user_id" class="text-sm font-semibold text-cyan-200">Responsable interno</label>
                    <select id="assigned_user_id" name="assigned_user_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400">
                        <option value="">No corresponde</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}" @selected((string) old('assigned_user_id') === (string) $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/5 p-4">
                    <label for="provider_business_party_id" class="text-sm font-semibold text-fuchsia-200">Especialista externo</label>
                    <select id="provider_business_party_id" name="provider_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-fuchsia-400 focus:ring-fuchsia-400">
                        <option value="">No corresponde</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->id }}" @selected((string) old('provider_business_party_id') === (string) $provider->id)>{{ $provider->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">Se muestran las personas y organizaciones registradas en la organización activa.</p>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                <a href="{{ route('service-orders.show', $order) }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                <button type="submit" class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Guardar trabajo</button>
            </div>
        </form>
    </div>
</x-app-layout>
