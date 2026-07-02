@props(['variant' => 'default'])

@php
$styles = match ($variant) {
    'success' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300',
    'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300',
    'danger' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300',
    'info' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300',
    default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.$styles]) }}>
    {{ $slot }}
</span>
