<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                <p class="text-gray-900">
                    Control your MT5 demo bot and AI assistant from one place.
                </p>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('settings.edit') }}" class="px-4 py-2 rounded bg-gray-900 text-white text-sm">Open Settings</a>
                    <a href="{{ route('bot.index') }}" class="px-4 py-2 rounded bg-indigo-600 text-white text-sm">Open Bot</a>
                    <a href="{{ route('ai.index') }}" class="px-4 py-2 rounded bg-emerald-600 text-white text-sm">Open AI</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
