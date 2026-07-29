@props([
    'value',
    'variant' => 'light',
])

@php
    $classes = $variant === 'dark'
        ? 'block text-sm font-medium text-slate-300'
        : 'block font-medium text-sm text-gray-700';
@endphp

<label
    data-label-variant="{{ $variant }}"
    {{ $attributes->merge(['class' => $classes]) }}
>
    {{ $value ?? $slot }}
</label>
