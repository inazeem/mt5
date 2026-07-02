@props(['title', 'actionLabel' => null, 'actionHref' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-900/50']) }}>
    <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</p>
    @if ($slot->isNotEmpty())
        <p class="mt-2 max-w-md text-sm text-slate-600 dark:text-slate-400">{{ $slot }}</p>
    @endif
    @if ($actionHref && $actionLabel)
        <a href="{{ $actionHref }}" class="mt-4 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ $actionLabel }}</a>
    @endif
</div>
