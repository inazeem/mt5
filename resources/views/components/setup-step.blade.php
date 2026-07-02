@props(['number', 'title', 'href' => null, 'done' => false])

<div {{ $attributes->merge(['class' => 'flex gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900']) }}>
    <div @class([
        'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
        'bg-emerald-600 text-white' => $done,
        'bg-indigo-600 text-white' => ! $done,
    ])>{{ $number }}</div>
    <div class="min-w-0 flex-1">
        @if ($href)
            <a href="{{ $href }}" class="font-semibold text-slate-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">{{ $title }}</a>
        @else
            <p class="font-semibold text-slate-900 dark:text-white">{{ $title }}</p>
        @endif
        @if ($slot->isNotEmpty())
            <div class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $slot }}</div>
        @endif
    </div>
</div>
