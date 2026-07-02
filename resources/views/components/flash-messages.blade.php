@if (session('status'))
    <x-alert type="success" class="mb-6">{{ session('status') }}</x-alert>
@endif

@if ($errors->any())
    <x-alert type="error" class="mb-6">
        <ul class="list-disc list-inside space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif
