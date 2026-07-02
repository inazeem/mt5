<x-app-layout>
    <x-page-header title="Edit Ticker" :subtitle="$ticker->symbol" />

    <div class="mx-auto max-w-2xl space-y-6">
        <x-flash-messages />

            <x-card>
                <form method="POST" action="{{ route('tickers.update', $ticker) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    @include('tickers._form')

                    <div class="flex items-center gap-4 pt-2">
                        <x-primary-button>Update Ticker</x-primary-button>
                        <a href="{{ route('tickers.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                    </div>
                </form>
            </x-card>
    </div>
</x-app-layout>
