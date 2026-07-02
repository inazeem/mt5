@php
    $navGroups = [
        'Overview' => [
            ['route' => 'dashboard', 'label' => 'Dashboard', 'pattern' => 'dashboard'],
            ['route' => 'setup.index', 'label' => 'Setup Guide', 'pattern' => 'setup.*'],
        ],
        'Setup' => [
            ['route' => 'ea-bridge.index', 'label' => 'MT5 Instances', 'pattern' => 'ea-bridge.*'],
            ['route' => 'settings.edit', 'label' => 'Settings', 'pattern' => 'settings.*'],
            ['route' => 'tickers.index', 'label' => 'Tickers', 'pattern' => 'tickers.*'],
        ],
        'Trading' => [
            ['route' => 'bot.index', 'label' => 'Bot', 'pattern' => 'bot.index|bot.trade|bot.price|bot.auto-settings|bot.close-position'],
            ['route' => 'bot-profiles.index', 'label' => 'Bot Profiles', 'pattern' => 'bot-profiles.*'],
            ['route' => 'strategies.edit', 'label' => 'Strategies', 'pattern' => 'strategies.*'],
        ],
        'Monitor' => [
            ['route' => 'bot.analytics', 'label' => 'Analytics', 'pattern' => 'bot.analytics*'],
            ['route' => 'bot.alerts', 'label' => 'Alerts', 'pattern' => 'bot.alerts*'],
            ['route' => 'bot.health', 'label' => 'Health', 'pattern' => 'bot.health'],
        ],
        'Tools' => [
            ['route' => 'ai.index', 'label' => 'AI Assistant', 'pattern' => 'ai.*'],
        ],
    ];
@endphp

<aside
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform duration-200 dark:border-slate-800 dark:bg-slate-900 lg:static lg:translate-x-0"
>
    <div class="flex h-14 items-center justify-between gap-2 border-b border-slate-200 px-4 dark:border-slate-800">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold text-slate-900 dark:text-white">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-sm text-white">M5</span>
            <span class="truncate">{{ config('app.name', 'MT5 Bot') }}</span>
        </a>
        <button type="button" @click="sidebarOpen = false" class="rounded p-1 text-slate-500 hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800" aria-label="Close menu">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6">
        @foreach ($navGroups as $group => $links)
            <div>
                <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ $group }}</p>
                <div class="space-y-1">
                    @foreach ($links as $link)
                        @php $active = request()->routeIs($link['pattern']); @endphp
                        <a
                            href="{{ route($link['route']) }}"
                            @click="sidebarOpen = false"
                            class="sidebar-link {{ $active ? 'sidebar-link-active' : 'sidebar-link-inactive' }}"
                        >
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-slate-200 p-3 space-y-2 dark:border-slate-800">
        <button
            type="button"
            @click="$store.theme.toggle()"
            class="sidebar-link sidebar-link-inactive w-full"
            aria-label="Toggle dark mode"
        >
            <span x-show="!$store.theme.dark" x-cloak>Dark mode</span>
            <span x-show="$store.theme.dark" x-cloak>Light mode</span>
        </button>
        <a href="{{ route('profile.edit') }}" class="sidebar-link {{ request()->routeIs('profile.*') ? 'sidebar-link-active' : 'sidebar-link-inactive' }}">
            Profile
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sidebar-link sidebar-link-inactive w-full text-left">
                Log out
            </button>
        </form>
        <p class="px-3 text-xs text-slate-400 truncate">{{ Auth::user()->name }}</p>
    </div>
</aside>
