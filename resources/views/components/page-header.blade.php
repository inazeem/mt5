@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between']) }}>
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            {{ $actions }}
        </div>
    @endif
</div>
