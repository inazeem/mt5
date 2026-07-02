<x-app-layout>
    <x-page-header title="AI" subtitle="Ask questions using your configured AI provider." />

    <div class="mx-auto max-w-4xl space-y-6">
        <x-flash-messages />

            <x-card>
            <form method="POST" action="{{ route('ai.ask') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700">Ask AI</label>
                    <textarea name="prompt" rows="4" class="mt-1 block w-full rounded border-gray-300" placeholder="What about GBPUSD today?">{{ old('prompt', $prompt) }}</textarea>
                    <p class="text-xs text-gray-500 mt-1">Current provider: {{ strtoupper($settings->ai_provider) }}</p>
                </div>

                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700">
                    Ask
                </button>
            </form>
            </x-card>

            @if (!empty($answer))
                <x-card :title="'Answer (' . strtoupper($provider) . ')'">
                    <div class="whitespace-pre-wrap text-slate-800 leading-relaxed dark:text-slate-200">{{ $answer }}</div>
                </x-card>
            @endif
    </div>
</x-app-layout>
