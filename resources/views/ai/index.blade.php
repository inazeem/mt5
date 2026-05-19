<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">AI</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="bg-red-100 border border-red-200 text-red-800 p-4 rounded">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('ai.ask') }}" class="bg-white p-6 rounded-lg shadow space-y-4">
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

            @if (!empty($answer))
                <div class="bg-white p-6 rounded-lg shadow space-y-3">
                    <h3 class="text-lg font-semibold">Answer ({{ strtoupper($provider) }})</h3>
                    <div class="whitespace-pre-wrap text-gray-800 leading-relaxed">{{ $answer }}</div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
