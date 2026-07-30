<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                Catálogo maestro
            </p>

            <h1 class="mt-1 text-2xl font-bold text-white">
                Editar producto
            </h1>

            <p class="mt-2 text-sm text-slate-400">
                {{ $product->name }} · {{ $product->sku }}
            </p>
        </div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-black/10">
            <form method="POST" action="{{ route('products.update', $product) }}">
                @csrf
                @method('PUT')

                @include('products._form')
            </form>
        </section>
    </div>
</x-app-layout>
