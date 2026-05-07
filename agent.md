1. What you are building (in kid language)
Imagine you’re building a control room website:

You log in with a username and password (Breeze).

Inside, there is:

A place to save MT5 API settings (server IP, port, manager login, password).

A bot that can send orders to MT5 (buy/sell).

A chat‑style page where you can ask Claude/Perplexity “What do you think about GBPUSD now?” and it answers.

You’re building a simple trading dashboard.

2. Set up the Laravel project with Breeze (login system)
Create new Laravel project

bash
composer create-project laravel/laravel mt5-panel
cd mt5-panel
Set up database

Create a MySQL DB (for example: mt5_panel).

In .env, set DB_DATABASE, DB_USERNAME, DB_PASSWORD.

Install Breeze for auth

bash
composer require laravel/breeze --dev
php artisan breeze:install
npm install
npm run dev
php artisan migrate
This gives you: Register, Login, Password reset, Profile pages out of the box.

Test

Run php artisan serve.

Open http://127.0.0.1:8000.

Register a user and log in – if you can log in, auth is done.

3. Add MetaTrader 5 package (to talk to MT5)
We’ll pick tarikhagustia/laravel-mt5 (style you can swap later).

Install package

bash
composer require tarikhagustia/laravel-mt5
Publish config (if package supports)

Check the README for exact commands, but usually:

bash
php artisan vendor:publish --provider="Tarikhagustia\LaravelMt5\LaravelMt5ServiceProvider"
Now you’ll have a config file where you put MT5 server IP, port, manager login, password.

Check simple code sample

From the README, opening an order looks like this:

php
use Tarikhagustia\LaravelMt5\LaravelMt5;

$api = new LaravelMt5();
$api->dealerSend([
    'Login'  => 8113,
    'Symbol' => 'XAUUSD',
    'Volume' => 100,
    'Type'   => 0, // 0=buy, 1=sell etc.
]);
Later we’ll wrap this in a Bot service.

4. Make a simple “API Management” section in the panel
Think of this like a settings page where you store MT5 connection details and any external keys.

4.1. Database table
Create a table api_settings with something like:

id

user_id (if per user, or just a single row)

mt5_server

mt5_port

mt5_login

mt5_password

claude_api_key

perplexity_api_key

timestamps

You can use php artisan make:migration create_api_settings_table and define those columns.

4.2. Model + controller
bash
php artisan make:model ApiSetting -m
php artisan make:controller ApiSettingController
In ApiSettingController, add methods like:

edit() – show form.

update() – save form.

The form has simple inputs (text boxes) where you can type the MT5 IP, login, passwords, and AI API keys.

4.3. Routes
In routes/web.php (inside auth middleware):

php
Route::middleware(['auth'])->group(function () {
    Route::get('/settings/apis', [ApiSettingController::class, 'edit'])->name('apis.edit');
    Route::post('/settings/apis', [ApiSettingController::class, 'update'])->name('apis.update');
});
Now, after login, you can go to /settings/apis and manage your API settings.

5. Build the MT5 Bot (service class + UI)
5.1. Make a service class for MT5
Create app/Services/Mt5Service.php:

php
<?php

namespace App\Services;

use Tarikhagustia\LaravelMt5\LaravelMt5;

class Mt5Service
{
    protected $api;

    public function __construct()
    {
        $this->api = new LaravelMt5();
    }

    public function openMarketOrder(int $login, string $symbol, float $volume, string $side)
    {
        $type = $side === 'buy' ? 0 : 1; // 0 buy, 1 sell

        return $this->api->dealerSend([
            'Login'  => $login,
            'Symbol' => $symbol,
            'Volume' => $volume,
            'Type'   => $type,
        ]);
    }
}
This is your bot’s “hand” that presses the MT5 buttons.

5.2. Controller for bot actions
bash
php artisan make:controller BotTradeController
In BotTradeController:

php
<?php

namespace App\Http\Controllers;

use App\Services\Mt5Service;
use Illuminate\Http\Request;

class BotTradeController extends Controller
{
    public function create()
    {
        return view('bot.trade'); // form view
    }

    public function store(Request $request, Mt5Service $mt5)
    {
        $data = $request->validate([
            'login'  => 'required|integer',
            'symbol' => 'required|string',
            'volume' => 'required|numeric',
            'side'   => 'required|in:buy,sell',
        ]);

        $result = $mt5->openMarketOrder(
            $data['login'],
            $data['symbol'],
            $data['volume'],
            $data['side']
        );

        return back()->with('status', 'Order sent: '.json_encode($result));
    }
}
5.3. Route + simple form
routes/web.php:

php
Route::middleware(['auth'])->group(function () {
    Route::get('/bot/trade', [BotTradeController::class, 'create'])->name('bot.trade.create');
    Route::post('/bot/trade', [BotTradeController::class, 'store'])->name('bot.trade.store');
});
resources/views/bot/trade.blade.php (simple):

text
<x-app-layout>
    <h1>Bot Trade</h1>

    @if(session('status'))
        <div>{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('bot.trade.store') }}">
        @csrf
        <label>MT5 Login:
            <input type="number" name="login" required>
        </label><br>
        <label>Symbol (e.g. GBPUSD):
            <input type="text" name="symbol" required>
        </label><br>
        <label>Volume (e.g. 0.10):
            <input type="text" name="volume" required>
        </label><br>
        <label>Side:
            <select name="side">
                <option value="buy">Buy</option>
                <option value="sell">Sell</option>
            </select>
        </label><br>
        <button type="submit">Send Trade</button>
    </form>
</x-app-layout>
Now your bot can send orders to MT5 when you click a button.

6. Connect to Claude/Perplexity for analysis
You’ll treat Claude/Perplexity like a smart friend you ask over HTTP.

6.1. Where to store keys
Put the API keys in .env or in api_settings table.

Example .env:

text
CLAUDE_API_KEY=your_key_here
PERPLEXITY_API_KEY=your_key_here
6.2. Create an AI service class
bash
php artisan make:service AiAnalysisService
(If make:service doesn’t exist, just create the file manually.)

app/Services/AiAnalysisService.php:

php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiAnalysisService
{
    public function askClaude(string $question): string
    {
        $apiKey = config('services.claude.key'); // or env('CLAUDE_API_KEY')

        $response = Http::withToken($apiKey)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-opus-20240229',
                'max_tokens' => 500,
                'messages' => [
                    ['role' => 'user', 'content' => $question],
                ],
            ]);

        return $response->json()['content'][0]['text'] ?? 'No answer';
    }

    public function askPerplexity(string $question): string
    {
        $apiKey = config('services.perplexity.key'); // or env('PERPLEXITY_API_KEY')

        $response = Http::withToken($apiKey)
            ->post('https://api.perplexity.ai/chat/completions', [
                'model' => 'sonar-pro',
                'messages' => [
                    ['role' => 'user', 'content' => $question],
                ],
            ]);

        return $response->json()['choices'][0]['message']['content'] ?? 'No answer';
    }
}
(Exact URLs/models may differ – you’ll match them to the actual docs of Claude/Perplexity.)

6.3. Controller + simple “Ask AI” page
bash
php artisan make:controller AnalysisController
AnalysisController:

php
<?php

namespace App\Http\Controllers;

use App\Services\AiAnalysisService;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function show()
    {
        return view('analysis.ask');
    }

    public function ask(Request $request, AiAnalysisService $ai)
    {
        $data = $request->validate([
            'question' => 'required|string',
            'engine'   => 'required|in:claude,perplexity',
        ]);

        $answer = $data['engine'] === 'claude'
            ? $ai->askClaude($data['question'])
            : $ai->askPerplexity($data['question']);

        return back()->with([
            'question' => $data['question'],
            'answer'   => $answer,
        ]);
    }
}
Routes:

php
Route::middleware(['auth'])->group(function () {
    Route::get('/analysis', [AnalysisController::class, 'show'])->name('analysis.show');
    Route::post('/analysis', [AnalysisController::class, 'ask'])->name('analysis.ask');
});
Blade view resources/views/analysis/ask.blade.php:

text
<x-app-layout>
    <h1>Ask AI About a Trade</h1>

    <form method="POST" action="{{ route('analysis.ask') }}">
        @csrf
        <label>Your question:
            <textarea name="question" rows="4" required>What do you think about GBPUSD short term?</textarea>
        </label><br>
        <label>Engine:
            <select name="engine">
                <option value="claude">Claude</option>
                <option value="perplexity">Perplexity</option>
            </select>
        </label><br>
        <button type="submit">Ask</button>
    </form>

    @if(session('answer'))
        <h2>Question:</h2>
        <p>{{ session('question') }}</p>

        <h2>Answer:</h2>
        <pre>{{ session('answer') }}</pre>
    @endif
</x-app-layout>
Now you have a chat‑like page inside your Laravel app that uses Claude/Perplexity for fresh analysis.