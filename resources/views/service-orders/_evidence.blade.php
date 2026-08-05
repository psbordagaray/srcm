@can('view-service-evidence')
    <section id="service-evidence" class="sulu-card scroll-mt-6 p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-cyan-300">Archivo privado</p>
                <h2 class="mt-1 text-lg font-bold text-white">Evidencias del expediente</h2>
                <p class="mt-1 text-sm text-slate-500">Fotografías y documentos protegidos, inmutables y verificados antes de cada descarga.</p>
            </div>
            @can('upload-service-evidence')
                <a href="{{ route('service-orders.evidences.create', $order) }}" class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Adjuntar evidencia</a>
            @endcan
        </div>

        @if($errors->has('service_evidence'))
            <div role="alert" class="mt-5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $errors->first('service_evidence') }}</div>
        @endif

        @if($order->evidences->isEmpty())
            <div class="mt-5 rounded-xl border border-dashed border-slate-700 bg-slate-950/40 px-5 py-8 text-center">
                <p class="text-sm font-semibold text-slate-300">Todavía no hay evidencias adjuntas.</p>
                <p class="mt-1 text-xs text-slate-600">Los archivos nunca se publican mediante enlaces abiertos.</p>
            </div>
        @else
            <div class="mt-5 space-y-3">
                @foreach($order->evidences->sortByDesc('captured_at') as $evidence)
                    <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full border border-cyan-500/20 bg-cyan-500/5 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-cyan-300">{{ $evidence->context->label() }}</span>
                                    <span class="font-mono text-[10px] text-slate-600">SHA-256 {{ \Illuminate\Support\Str::limit($evidence->sha256, 16, '') }}…</span>
                                </div>
                                <p class="mt-3 break-all text-sm font-semibold text-white">{{ $evidence->original_filename }}</p>
                                @if($evidence->description)
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $evidence->description }}</p>
                                @endif
                                <p class="mt-3 text-xs text-slate-500">
                                    {{ number_format($evidence->size_bytes / 1024, 1, ',', '.') }} KB
                                    · capturada {{ $evidence->captured_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                                    · registró {{ $evidence->uploadedBy?->name ?? 'Usuario no disponible' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                <a href="{{ route('service-orders.evidences.download', [
                                    'serviceOrder' => $order,
                                    'evidencePublicId' => $evidence->public_id,
                                ]) }}" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-cyan-400 hover:text-cyan-200">Descargar</a>
                                @can('verify-service-evidence')
                                    <form method="POST" action="{{ route('service-orders.evidences.verify', [
                                            'serviceOrder' => $order,
                                            'evidencePublicId' => $evidence->public_id,
                                        ]) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-400 transition hover:border-emerald-400 hover:text-emerald-200">Verificar integridad</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endcan
