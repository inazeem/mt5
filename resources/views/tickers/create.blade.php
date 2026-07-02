<x-app-layout>
    <x-page-header title="Add Ticker" subtitle="Register a symbol with optional spread and TP/SL overrides." />

    <div class="mx-auto max-w-2xl space-y-6">
        <x-flash-messages />

            <x-card>
                <form method="POST" action="{{ route('tickers.store') }}" class="space-y-6">
                    @csrf

                    @include('tickers._form')

                    <div class="flex items-center gap-4 pt-2">
                        <x-primary-button>Save Ticker</x-primary-button>
                        <a href="{{ route('tickers.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                    </div>
                </form>
            </x-card>
    </div>
</x-app-layout>
