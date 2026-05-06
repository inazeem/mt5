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
                            <div class="mt-1 flex items-center gap-2">
                                <input id="mt5_manager_password" name="mt5_manager_password" type="password" value="{{ old('mt5_manager_password', $settings->mt5_manager_password) }}" placeholder="Leave blank to keep existing password" class="block w-full rounded border-gray-300" />
                                <button type="button" data-toggle-password="mt5_manager_password" class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50" aria-label="Toggle manager password visibility" title="Show/Hide">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 4C5 4 1.73 7.11.46 9.02a1.75 1.75 0 0 0 0 1.96C1.73 12.89 5 16 10 16s8.27-3.11 9.54-5.02a1.75 1.75 0 0 0 0-1.96C18.27 7.11 15 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z" />
                                    </svg>
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                @if(!empty($settings->mt5_manager_password))
                                    Password is saved (hidden). Leave blank to keep it unchanged.
                                @else
                                    No password saved yet.
                                @endif
                            </p>
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
                            <div class="mt-1 flex items-center gap-2">
                                <input id="claude_api_key" name="claude_api_key" type="password" value="{{ old('claude_api_key', $settings->claude_api_key) }}" placeholder="Leave blank to keep existing key" class="block w-full rounded border-gray-300" />
                                <button type="button" data-toggle-password="claude_api_key" class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50" aria-label="Toggle Claude API key visibility" title="Show/Hide">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 4C5 4 1.73 7.11.46 9.02a1.75 1.75 0 0 0 0 1.96C1.73 12.89 5 16 10 16s8.27-3.11 9.54-5.02a1.75 1.75 0 0 0 0-1.96C18.27 7.11 15 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z" />
                                    </svg>
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                @if(!empty($settings->claude_api_key))
                                    Key is saved (hidden). Leave blank to keep it unchanged.
                                @else
                                    No Claude API key saved yet.
                                @endif
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Perplexity Model</label>
                            <input name="perplexity_model" type="text" value="{{ old('perplexity_model', $settings->perplexity_model) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Perplexity API Key</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input id="perplexity_api_key" name="perplexity_api_key" type="password" value="{{ old('perplexity_api_key', $settings->perplexity_api_key) }}" placeholder="Leave blank to keep existing key" class="block w-full rounded border-gray-300" />
                                <button type="button" data-toggle-password="perplexity_api_key" class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50" aria-label="Toggle Perplexity API key visibility" title="Show/Hide">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 4C5 4 1.73 7.11.46 9.02a1.75 1.75 0 0 0 0 1.96C1.73 12.89 5 16 10 16s8.27-3.11 9.54-5.02a1.75 1.75 0 0 0 0-1.96C18.27 7.11 15 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z" />
                                    </svg>
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                @if(!empty($settings->perplexity_api_key))
                                    Key is saved (hidden). Leave blank to keep it unchanged.
                                @else
                                    No Perplexity API key saved yet.
                                @endif
                            </p>
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
                            <div class="mt-1 flex items-center gap-2">
                                <input id="metaapi_token" name="metaapi_token" type="password" value="{{ old('metaapi_token', $settings->metaapi_token) }}"
                                    maxlength="4096"
                                       placeholder="Leave blank to keep existing token"
                                       class="block w-full rounded border-gray-300" />
                                <button type="button" data-toggle-password="metaapi_token" class="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50" aria-label="Toggle MetaApi token visibility" title="Show/Hide">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 4C5 4 1.73 7.11.46 9.02a1.75 1.75 0 0 0 0 1.96C1.73 12.89 5 16 10 16s8.27-3.11 9.54-5.02a1.75 1.75 0 0 0 0-1.96C18.27 7.11 15 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z" />
                                    </svg>
                                </button>
                            </div>
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

                {{-- Auto-Bot Section --}}
                <section class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-900">Auto-Bot Parameters</h3>
                    <p class="text-sm text-gray-500">These values are used as defaults when the <code>mt5:auto-forex</code> command runs without explicit flags.</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Lot Size</label>
                            <input name="bot_lot" type="number" step="0.001" min="0.001" value="{{ old('bot_lot', $settings->bot_lot ?? 0.01) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">e.g. 0.01 for micro lots.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">TP Pips</label>
                            <input name="bot_tp_pips" type="number" step="0.1" min="0.1" value="{{ old('bot_tp_pips', $settings->bot_tp_pips ?? 25) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">SL Pips</label>
                            <input name="bot_sl_pips" type="number" step="0.1" min="0.1" value="{{ old('bot_sl_pips', $settings->bot_sl_pips ?? 15) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail Start Pips</label>
                            <input name="bot_trail_start_pips" type="number" step="0.1" min="0.1" value="{{ old('bot_trail_start_pips', $settings->bot_trail_start_pips ?? 10) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Profit required before trailing activates.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail Pips</label>
                            <input name="bot_trail_pips" type="number" step="0.1" min="0.1" value="{{ old('bot_trail_pips', $settings->bot_trail_pips ?? 8) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Move Pips</label>
                            <input name="bot_min_move_pips" type="number" step="0.1" min="0.1" value="{{ old('bot_min_move_pips', $settings->bot_min_move_pips ?? 3) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Minimum tick move to trigger signal.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Spread Pips</label>
                            <input name="bot_max_spread_pips" type="number" step="0.1" min="0.1" value="{{ old('bot_max_spread_pips', $settings->bot_max_spread_pips ?? 2.5) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Cooldown (minutes)</label>
                            <input name="bot_cooldown_minutes" type="number" min="0" value="{{ old('bot_cooldown_minutes', $settings->bot_cooldown_minutes ?? 30) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Wait after a successful entry on the same symbol.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Trades / Day</label>
                            <input name="bot_max_trades_per_day" type="number" min="1" value="{{ old('bot_max_trades_per_day', $settings->bot_max_trades_per_day ?? 20) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Daily Loss %</label>
                            <input name="bot_max_daily_loss_percent" type="number" step="0.1" min="0.1" value="{{ old('bot_max_daily_loss_percent', $settings->bot_max_daily_loss_percent ?? 2) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Session Start (UTC hour)</label>
                            <input name="bot_session_start_utc" type="number" min="0" max="23" value="{{ old('bot_session_start_utc', $settings->bot_session_start_utc ?? 6) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Session End (UTC hour)</label>
                            <input name="bot_session_end_utc" type="number" min="0" max="23" value="{{ old('bot_session_end_utc', $settings->bot_session_end_utc ?? 20) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Symbols / Cycle</label>
                            <input name="bot_max_symbols" type="number" min="1" value="{{ old('bot_max_symbols', $settings->bot_max_symbols ?? 200) }}" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">AI Min Confidence %</label>
                            <input name="bot_ai_min_confidence" type="number" min="0" max="100" value="{{ old('bot_ai_min_confidence', $settings->bot_ai_min_confidence ?? 70) }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">AI must express ≥ this confidence to approve. 0 = any APPROVE accepted.</p>
                        </div>
                        <div class="flex items-center">
                            <label class="inline-flex items-center gap-2 mt-5">
                                <input type="checkbox" name="bot_ai_confirm" value="1" {{ old('bot_ai_confirm', $settings->bot_ai_confirm ?? true) ? 'checked' : '' }} class="rounded border-gray-300" />
                                <span class="text-sm text-gray-700">Require AI confirmation before each trade</span>
                            </label>
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

    <script>
        document.querySelectorAll('[data-toggle-password]').forEach((button) => {
            button.addEventListener('click', () => {
                const inputId = button.getAttribute('data-toggle-password');
                const input = document.getElementById(inputId);
                if (!input) return;
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        });
    </script>
</x-app-layout>
