@props(['title' => 'How this page works', 'open' => false])

<div x-data="{ open: @js($open) }" {{ $attributes->merge(['class' => 'rounded-xl border border-indigo-200 bg-indigo-50/50 dark:border-indigo-900 dark:bg-indigo-950/30']) }}>
    <button
        type="button"
        @click="open = !open"
        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-semibold text-indigo-900 dark:text-indigo-200"
    >
        <span>{{ $title }}</span>
        <svg class="h-5 w-5 shrink-0 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div x-show="open" x-cloak class="border-t border-indigo-200 px-4 py-3 dark:border-indigo-900">
        <div class="guide-prose">
            {{ $slot }}
        </div>
    </div>
</div>
