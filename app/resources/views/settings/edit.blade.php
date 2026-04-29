<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Settings</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-green-100 border border-green-200 text-green-800 p-4 rounded">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="bg-red-100 border border-red-200 text-red-800 p-4 rounded">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('settings.update') }}" class="bg-white p-6 rounded-lg shadow space-y-8">
                @csrf
                @method('PUT')

                <section class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-900">Access</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Owner Email</label>
                        <input name="owner_email" type="email" value="{{ old('owner_email', $settings->owner_email) }}" class="mt-1 block w-full rounded border-gray-300" placeholder="you@example.com" />
                        <p class="text-xs text-gray-500 mt-1">Only this email can access protected pages when APP_OWNER_EMAIL is set.</p>
                    </div>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="demo_only" value="1" {{ old('demo_only', $settings->demo_only) ? 'checked' : '' }} class="rounded border-gray-300" />
                        <span class="text-sm text-gray-700">Demo-only mode (recommended)</span>
                    </label>
                </section>

                <section class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-900">MT5 Connection</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">MT5 Server</label>
                            <input name="mt5_server" type="text" value="{{ old('mt5_server', $settings->mt5_server) }}" class="mt-1 block w-full rounded border-gray-300" placeholder="mt5.yourbroker.com" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">MT5 Port</label>
                            <input name="mt5_port" type="number" value="{{ old('mt5_port', $settings->mt5_port) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Manager Login</label>
                            <input name="mt5_manager_login" type="text" value="{{ old('mt5_manager_login', $settings->mt5_manager_login) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Use MT5 Manager/Web API numeric login, not client trading login.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Manager Password</label>
                            <input name="mt5_manager_password" type="password" value="{{ old('mt5_manager_password') }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trading Account Login</label>
                            <input name="mt5_account_login" type="text" value="{{ old('mt5_account_login', $settings->mt5_account_login) }}" class="mt-1 block w-full rounded border-gray-300" placeholder="e.g. 62120569" />
                            <p class="text-xs text-gray-500 mt-1">Must be numeric MT5 account id.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Action (deal)</label>
                            <input name="mt5_action_deal" type="number" value="{{ old('mt5_action_deal', $settings->mt5_action_deal) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Volume Multiplier</label>
                            <input name="mt5_volume_multiplier" type="number" value="{{ old('mt5_volume_multiplier', $settings->mt5_volume_multiplier) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Used to convert lot size into MT5 volume units.</p>
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-900">AI</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Provider</label>
                            <select name="ai_provider" class="mt-1 block w-full rounded border-gray-300">
                                <option value="claude" {{ old('ai_provider', $settings->ai_provider) === 'claude' ? 'selected' : '' }}>Claude</option>
                                <option value="perplexity" {{ old('ai_provider', $settings->ai_provider) === 'perplexity' ? 'selected' : '' }}>Perplexity</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Claude Model</label>
                            <input name="claude_model" type="text" value="{{ old('claude_model', $settings->claude_model) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Claude API Key</label>
                            <input name="claude_api_key" type="password" value="{{ old('claude_api_key') }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Perplexity Model</label>
                            <input name="perplexity_model" type="text" value="{{ old('perplexity_model', $settings->perplexity_model) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Perplexity API Key</label>
                            <input name="perplexity_api_key" type="password" value="{{ old('perplexity_api_key') }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                    </div>
                </section>

                {{-- MetaApi Section --}}
                <section>
                    <h2 class="text-lg font-medium text-gray-900">MetaApi Connection</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Connect your retail MT5 account via <a href="https://metaapi.cloud" target="_blank" class="text-indigo-600 hover:underline">metaapi.cloud</a>.
                        Create an account, add your MT5 account there, and paste the token and Account ID below.
                    </p>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">MetaApi Auth Token</label>
                            <input name="metaapi_token" type="password" value="{{ old('metaapi_token') }}"
                                maxlength="4096"
                                   placeholder="Leave blank to keep existing token"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="mt-1 text-xs text-gray-500">Found in MetaApi dashboard → API Tokens. Leave blank to preserve the saved token.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">MetaApi Account ID</label>
                            <input name="metaapi_account_id" type="text"
                                   value="{{ old('metaapi_account_id', $settings->metaapi_account_id) }}"
                                   placeholder="e.g. abc123de-f456-..."
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="mt-1 text-xs text-gray-500">The UUID of your MT5 account in the MetaApi dashboard.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">MetaApi Region</label>
                            <select name="metaapi_region" class="mt-1 block w-full rounded border-gray-300">
                                @foreach(['new-york' => 'New York (default)', 'london' => 'London', 'singapore' => 'Singapore', 'sydney' => 'Sydney'] as $val => $label)
                                    <option value="{{ $val }}" @selected(($settings->metaapi_region ?? 'new-york') === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Choose the server region closest to your broker.</p>
                        </div>
                    </div>
                </section>

                <div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
