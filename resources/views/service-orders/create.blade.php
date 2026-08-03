<x-app-layout>
    @php($identifierRows = old('identifiers', [['type' => 'imei', 'value' => '']]))
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Reparaciones · Recepción</p>
                <h1 class="mt-1 text-2xl font-bold text-white">Recibir un equipo</h1>
                <p class="mt-2 text-sm text-slate-400">Registrá lo declarado por el cliente y lo observado al recibirlo. Esta fotografía inicial no se modifica.</p>
            </div>
            <a href="{{ route('service-orders.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Volver a órdenes</a>
        </div>

        @if($errors->has('service_order'))
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('service_order') }}</div>
        @endif

        <form method="POST" action="{{ route('service-orders.store') }}" class="space-y-6" data-service-order-form>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card p-6">
                <div class="flex items-center gap-3">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-cyan-400/10 font-bold text-cyan-300">1</span>
                    <div><h2 class="font-bold text-white">Equipo e identidad técnica</h2><p class="text-xs text-slate-500">Marca, modelo e identificadores que permiten reconocerlo cuando vuelva.</p></div>
                </div>
                <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label for="asset_type" class="text-sm font-semibold text-slate-200">Tipo de equipo</label>
                        <select id="asset_type" name="asset_type" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Seleccionar</option>
                            @foreach($assetTypes as $type)<option value="{{ $type->value }}" @selected(old('asset_type') === $type->value)>{{ $type->label() }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('asset_type')" class="mt-2" />
                    </div>
                    <div>
                        <label for="brand_name" class="text-sm font-semibold text-slate-200">Marca</label>
                        <input id="brand_name" name="brand_name" value="{{ old('brand_name') }}" required placeholder="Motorola, Lenovo, Ford..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('brand_name')" class="mt-2" />
                    </div>
                    <div>
                        <label for="model_name" class="text-sm font-semibold text-slate-200">Modelo</label>
                        <input id="model_name" name="model_name" value="{{ old('model_name') }}" required placeholder="E22i, IdeaPad 3..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('model_name')" class="mt-2" />
                    </div>
                    <div>
                        <label for="color" class="text-sm font-semibold text-slate-200">Color <span class="font-normal text-slate-600">(opcional)</span></label>
                        <input id="color" name="color" value="{{ old('color') }}" placeholder="Negro" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-between gap-4">
                    <div><h3 class="text-sm font-bold text-white">Identificadores</h3><p class="mt-1 text-xs text-slate-500">El equipo puede recibirse sin identificador, pero IMEI, serie, VIN o patente fortalecen la trazabilidad.</p></div>
                    <button type="button" data-add-identifier class="shrink-0 rounded-xl border border-cyan-500/40 px-3 py-2 text-xs font-bold text-cyan-300 transition hover:bg-cyan-500/10">Agregar identificador</button>
                </div>
                <div class="mt-4 space-y-3" data-identifiers>
                    @foreach($identifierRows as $index => $identifier)
                        @include('service-orders._identifier', compact('index', 'identifier', 'identifierTypes'))
                    @endforeach
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-xl bg-violet-400/10 font-bold text-violet-300">2</span><div><h2 class="font-bold text-white">Cliente, propietario y contacto</h2><p class="text-xs text-slate-500">La persona que entrega puede ser distinta del propietario del equipo.</p></div></div>
                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    <div>
                        <label for="customer_business_party_id" class="text-sm font-semibold text-slate-200">Ficha existente de quien entrega</label>
                        <select id="customer_business_party_id" name="customer_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Sin ficha seleccionada</option>
                            @foreach($parties as $party)<option value="{{ $party->id }}" @selected((string) old('customer_business_party_id') === (string) $party->id)>{{ $party->name }}{{ $party->phone ? ' · '.$party->phone : '' }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('customer_business_party_id')" class="mt-2" />
                    </div>
                    <div>
                        <label for="customer_name" class="text-sm font-semibold text-slate-200">Nombre declarado <span class="font-normal text-slate-600">(si no tiene ficha)</span></label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" placeholder="Nombre y apellido o comercio" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('customer_name')" class="mt-2" />
                    </div>
                    <div>
                        <label for="owner_business_party_id" class="text-sm font-semibold text-slate-200">Ficha del propietario <span class="font-normal text-slate-600">(opcional)</span></label>
                        <select id="owner_business_party_id" name="owner_business_party_id" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Mismo que quien entrega</option>
                            @foreach($parties as $party)<option value="{{ $party->id }}" @selected((string) old('owner_business_party_id') === (string) $party->id)>{{ $party->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="owner_name" class="text-sm font-semibold text-slate-200">Propietario declarado <span class="font-normal text-slate-600">(opcional)</span></label>
                        <input id="owner_name" name="owner_name" value="{{ old('owner_name') }}" placeholder="Sólo si es otra persona sin ficha" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                    </div>
                </div>
                <div class="mt-5 grid gap-5 lg:grid-cols-[auto_minmax(0,1fr)] lg:items-end">
                    <label class="flex min-h-11 items-center gap-3 rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-slate-200">
                        <input type="hidden" name="contact_available" value="0"><input type="checkbox" name="contact_available" value="1" @checked(old('contact_available')) class="rounded border-slate-600 bg-slate-900 text-cyan-400 focus:ring-cyan-400"> Posee medio de contacto
                    </label>
                    <div>
                        <label for="contact_reference" class="text-sm font-semibold text-slate-200">Teléfono, correo o referencia</label>
                        <input id="contact_reference" name="contact_reference" value="{{ old('contact_reference') }}" placeholder="Ej.: +54 9 3447..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('contact_reference')" class="mt-2" />
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-xl bg-amber-400/10 font-bold text-amber-300">3</span><div><h2 class="font-bold text-white">Condición y compromiso de recepción</h2><p class="text-xs text-slate-500">Separá claramente lo que declara el cliente de lo que observa el local.</p></div></div>
                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        <label for="customer_reported_issue" class="text-sm font-semibold text-slate-200">Problema declarado por el cliente</label>
                        <textarea id="customer_reported_issue" name="customer_reported_issue" rows="3" required placeholder="Ej.: pantalla rota; declara que nunca fue reemplazada." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">{{ old('customer_reported_issue') }}</textarea>
                        <x-input-error :messages="$errors->get('customer_reported_issue')" class="mt-2" />
                    </div>
                    <div>
                        <label for="intake_observations" class="text-sm font-semibold text-slate-200">Observaciones propias al recibir</label>
                        <textarea id="intake_observations" name="intake_observations" rows="4" placeholder="Golpes, piezas faltantes, pantalla no original, estado general..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">{{ old('intake_observations') }}</textarea>
                    </div>
                    <div>
                        <label for="received_accessories" class="text-sm font-semibold text-slate-200">Accesorios recibidos</label>
                        <textarea id="received_accessories" name="received_accessories" rows="4" placeholder="Equipo, cargador, funda, batería, llaves..." class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white placeholder:text-slate-600 focus:border-cyan-400 focus:ring-cyan-400">{{ old('received_accessories') }}</textarea>
                    </div>
                    <div>
                        <label for="intake_location_id" class="text-sm font-semibold text-slate-200">Ubicación de recepción</label>
                        <select id="intake_location_id" name="intake_location_id" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                            <option value="">Seleccionar</option>
                            @foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) old('intake_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('intake_location_id')" class="mt-2" />
                    </div>
                    <div>
                        <label for="promised_at" class="text-sm font-semibold text-slate-200">Fecha prometida <span class="font-normal text-slate-600">(opcional)</span></label>
                        <input id="promised_at" type="datetime-local" name="promised_at" value="{{ old('promised_at') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-sm text-white focus:border-cyan-400 focus:ring-cyan-400">
                        <x-input-error :messages="$errors->get('promised_at')" class="mt-2" />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" @disabled($locations->isEmpty()) class="rounded-xl bg-cyan-400 px-6 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-40">Registrar recepción y custodia</button>
                <a href="{{ route('service-orders.index') }}" class="rounded-xl border border-slate-700 px-6 py-3 font-semibold text-white transition hover:border-slate-500">Cancelar</a>
            </div>
        </form>

        <template data-identifier-template>@include('service-orders._identifier', ['index' => '__INDEX__', 'identifier' => ['type' => '', 'value' => ''], 'identifierTypes' => $identifierTypes])</template>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const rows = document.querySelector('[data-identifiers]');
            const add = document.querySelector('[data-add-identifier]');
            const template = document.querySelector('[data-identifier-template]');
            if (!rows || !add || !template) return;
            const refresh = () => {
                rows.querySelectorAll('[data-remove-identifier]').forEach((button) => {
                    button.onclick = () => button.closest('[data-identifier-row]').remove();
                });
                add.disabled = rows.children.length >= 10;
                add.classList.toggle('opacity-40', add.disabled);
            };
            add.addEventListener('click', () => {
                if (rows.children.length >= 10) return;
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', Date.now().toString()).trim();
                rows.appendChild(wrapper.firstElementChild);
                refresh();
            });
            refresh();
        });
    </script>
</x-app-layout>
