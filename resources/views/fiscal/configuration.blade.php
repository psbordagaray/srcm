<x-app-layout>
    <div class="space-y-6">
        <header>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                P10 · Fiscalidad argentina
            </p>
            <h1 class="mt-1 text-2xl font-bold text-white">
                Configuración fiscal de {{ $organization->name }}
            </h1>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-400">
                Esta identidad y sus puntos de venta pertenecen a la capa fiscal.
                No autorizan comprobantes, no asignan numeración y no modifican
                ventas comerciales confirmadas.
            </p>
        </header>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('fiscal-configuration.profile.update') }}"
            class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6"
        >
            @csrf
            @method('PUT')

            <div>
                <h2 class="font-bold text-white">Perfil fiscal argentino</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Los códigos de condición IVA y provincia se conservan como
                    referencias externas; no se convierten en constantes eternas.
                </p>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    ['legal_name', 'Razón social', old('legal_name', $profile?->legal_name), 'text'],
                    ['tax_id', 'CUIT', old('tax_id', $profile?->tax_id), 'text'],
                    ['vat_condition_code', 'Código condición IVA ARCA', old('vat_condition_code', $profile?->vat_condition_code), 'text'],
                    ['gross_income_number', 'Ingresos Brutos', old('gross_income_number', $profile?->gross_income_number), 'text'],
                    ['activity_started_on', 'Inicio de actividades', old('activity_started_on', $profile?->activity_started_on?->format('Y-m-d')), 'date'],
                    ['address_line', 'Domicilio fiscal', old('address_line', $profile?->address_line), 'text'],
                    ['city', 'Localidad', old('city', $profile?->city), 'text'],
                    ['province_code', 'Código de provincia', old('province_code', $profile?->province_code), 'text'],
                    ['postal_code', 'Código postal', old('postal_code', $profile?->postal_code), 'text'],
                ] as [$name, $label, $value, $type])
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            {{ $label }}
                        </span>
                        <input
                            type="{{ $type }}"
                            name="{{ $name }}"
                            value="{{ $value }}"
                            @if ($name !== 'gross_income_number') required @endif
                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400"
                        >
                    </label>
                @endforeach
            </div>

            <div class="mt-5 flex justify-end">
                <button
                    type="submit"
                    class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
                >
                    Guardar perfil fiscal
                </button>
            </div>
        </form>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
            <div>
                <h2 class="font-bold text-white">Puntos de venta fiscales</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Homologación y producción son ambientes independientes. La
                    identidad de un punto creado no puede reescribirse.
                </p>
            </div>

            @if ($profile)
                <form
                    method="POST"
                    action="{{ route('fiscal-configuration.points.store') }}"
                    class="mt-5 grid gap-4 md:grid-cols-4"
                >
                    @csrf

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Número
                        </span>
                        <input
                            type="number"
                            min="1"
                            max="99999"
                            name="point_number"
                            value="{{ old('point_number') }}"
                            required
                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white"
                        >
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Ambiente
                        </span>
                        <select
                            name="environment"
                            required
                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white"
                        >
                            @foreach ($environments as $environment)
                                <option
                                    value="{{ $environment->value }}"
                                    @selected(old('environment') === $environment->value)
                                >
                                    {{ $environment->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Integración
                        </span>
                        <select
                            name="integration_mode"
                            required
                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white"
                        >
                            @foreach ($integrationModes as $mode)
                                <option
                                    value="{{ $mode->value }}"
                                    @selected(old('integration_mode') === $mode->value)
                                >
                                    {{ $mode->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex items-end">
                        <button
                            type="submit"
                            class="w-full rounded-xl border border-cyan-400/40 px-4 py-2.5 text-sm font-bold text-cyan-200 transition hover:bg-cyan-400/10"
                        >
                            Registrar punto
                        </button>
                    </div>
                </form>

                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                                <th class="px-3 py-3">Ambiente</th>
                                <th class="px-3 py-3">Punto</th>
                                <th class="px-3 py-3">Integración</th>
                                <th class="px-3 py-3">Estado</th>
                                <th class="px-3 py-3 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse ($profile->pointsOfSale as $point)
                                <tr class="text-sm text-slate-300">
                                    <td class="px-3 py-4">{{ $point->environment->label() }}</td>
                                    <td class="px-3 py-4 font-mono">{{ $point->point_number }}</td>
                                    <td class="px-3 py-4">{{ $point->integration_mode->label() }}</td>
                                    <td class="px-3 py-4">
                                        {{ $point->active ? 'Activo' : 'Inactivo' }}
                                    </td>
                                    <td class="px-3 py-4 text-right">
                                        <form
                                            method="POST"
                                            action="{{ route('fiscal-configuration.points.toggle-active', $point) }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button class="text-sm font-semibold text-cyan-300 hover:text-cyan-200">
                                                {{ $point->active ? 'Desactivar' : 'Reactivar' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">
                                        Aún no hay puntos de venta fiscales.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                    Guardá primero el perfil fiscal de la organización.
                </p>
            @endif
        </section>

        <aside class="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-5 text-sm leading-6 text-amber-100">
            <strong>P10.1 no factura.</strong> Todavía no existen documento fiscal,
            secuencia autorizada, WSAA, solicitud WSFE, CAE, CAEA ni QR. Esas
            verdades se incorporarán en cortes posteriores y nunca se inferirán
            desde el número interno de venta.
        </aside>
    </div>
</x-app-layout>
