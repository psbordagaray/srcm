@props([
    'disabled' => false,
    'variant' => 'light',
])

@php
    $classes = $variant === 'dark'
        ? 'rounded-xl border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500 shadow-sm focus:border-cyan-400 focus:ring-cyan-400 disabled:cursor-not-allowed disabled:opacity-60'
        : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm';
@endphp

<input
    @disabled($disabled)
    data-input-variant="{{ $variant }}"
    {{ $attributes->merge(['class' => $classes]) }}
>
