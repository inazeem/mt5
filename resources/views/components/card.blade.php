@props(['title' => null, 'padding' => true])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900']) }}>
    @if ($title)
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
        </div>
    @endif
    <div @class(['px-5 py-4' => $padding])>
        {{ $slot }}
    </div>
</div>
