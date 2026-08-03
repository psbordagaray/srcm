@props(['name'])

<svg {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
            @break
        @case('categories')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h6l2 2h8v10H4V6Z" />
            @break
        @case('tag')
            <path stroke-linecap="round" stroke-linejoin="round" d="M20 13 13 20l-9-9V4h7l9 9Z" /><circle cx="8.5" cy="8.5" r="1" />
            @break
        @case('factory')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21V9l6 3V9l6 3V5h6v16H3Z" /><path d="M7 17h2M13 17h2M18 9h1" />
            @break
        @case('box')
            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 4-8 4-8-4 8-4Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 4 8-4v10l-8 4-8-4V7Z" /><path d="M12 11v10" />
            @break
        @case('tv')
            <rect x="3" y="5" width="18" height="13" rx="2" /><path stroke-linecap="round" d="m8 2 4 3 4-3M9 21h6" />
            @break
        @case('link')
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.1 1.1M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.1-1.1" />
            @break
        @case('inventory')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16v14H4V7Z" /><path d="M2 3h20v4H2zM9 11h6" />
            @break
        @case('repair')
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 9.6 6 7.3 3.7a4 4 0 0 0 5 5L4 17l3 3 7.7-8.3a4 4 0 0 0 5-5L17.4 9 15 6.6l2.3-2.3a4 4 0 0 0-2.6 2Z" />
            @break
        @case('users')
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path stroke-linecap="round" d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
            @break
        @case('truck')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h11v12H3V5Zm11 4h4l3 3v5h-7V9Z" /><circle cx="7" cy="18" r="2" /><circle cx="18" cy="18" r="2" />
            @break
        @case('cart')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6" /><circle cx="10" cy="20" r="1" /><circle cx="18" cy="20" r="1" />
            @break
        @case('receipt')
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Z" /><path stroke-linecap="round" d="M9 8h6M9 12h6" />
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.4.28.75.62 1 1 .26.4.4.87.4 1.35V13h-1.4a1.7 1.7 0 0 0 0 2Z" />
            @break
    @endswitch
</svg>
