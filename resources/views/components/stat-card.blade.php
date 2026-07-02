@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900']) }}>
    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
    <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
</div>
