@props(['type' => 'info'])

@php
$styles = match ($type) {
    'success' => 'bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-950/50 dark:border-emerald-800 dark:text-emerald-200',
    'error' => 'bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-950/50 dark:border-rose-800 dark:text-rose-200',
    'warning' => 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/50 dark:border-amber-800 dark:text-amber-200',
    default => 'bg-sky-50 border-sky-200 text-sky-900 dark:bg-sky-950/50 dark:border-sky-800 dark:text-sky-200',
};
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg border p-4 text-sm '.$styles]) }}>
    {{ $slot }}
</div>
