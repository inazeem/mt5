# EA Bridge Setup (Laravel ↔ MT5)

Remote-control your MT5 account from the Laravel panel. The **LaravelBridge** Expert Advisor polls your server every second, sends account data, and executes queued trade commands.

This is an alternative to **MetaAPI** (`Mt5Service`). **Forex bot profiles now use EA bridge only** — no MetaAPI for quotes, candles, or trade execution. Alpaca crypto profiles are unchanged.

| Approach | Best for |
|----------|----------|
| **EA Bridge** (this doc) | MT5 multi-account, bot profiles, direct broker execution |
| **Alpaca** | Crypto bot profiles |

Related files:

- [`mql5/Experts/LaravelBridge/LaravelBridge.mq5`](mql5/Experts/LaravelBridge/LaravelBridge.mq5) — MT5 Expert Advisor
- [`app/Services/EaBridgeService.php`](app/Services/EaBridgeService.php) — Laravel bridge logic
- [`routes/api.php`](routes/api.php) — EA poll endpoint
- [`tests/Feature/EaBridgeTest.php`](tests/Feature/EaBridgeTest.php) — API tests

---

## Architecture

```text
User (browser)
      │
      ▼
Laravel panel  (/ea-bridge)
      │
      ▼
MySQL  (mt5_ea_terminals, mt5_ea_commands)
      ▲
      │  POST /api/ea/poll  (every ~1 second)
      │
LaravelBridge EA  (running in MT5)
      │
      ▼
Broker
```

**Flow:**

1. You queue a command in Laravel (UI or PHP).
2. The EA polls `POST /api/ea/poll` with account snapshot + last command result.
3. Laravel returns the next pending command (or `null`).
4. The EA executes the trade on MT5 and reports the result on the next poll.

---

## Prerequisites

- Laravel app running (e.g. [Laravel Herd](https://herd.laravel.com) at `https://mt5.test`)
- Database migrated (`php artisan migrate`)
- Logged-in owner account (`APP_OWNER_EMAIL` in `.env`)
- MetaTrader 5 installed with **Algo Trading** enabled
- MT5 account connected to your broker (demo recommended first)

---

## Multiple MT5 instances + bot profile mapping

### 1. One token, many terminals

Use the **same** API token on every MT5 install. Each instance is identified by `account_login` + `server` and an **`instance_key`**.

### 2. Register instances

1. Attach `LaravelBridge` on each MT5 account (different installs or same install, different logins).
2. Open **EA Bridge** → **MT5 Instance Profiles**.
3. Set a unique **Instance Key** (e.g. `demo-pepperstone`, `live-icmarkets`) and display name per terminal.

Optional EA input: `InpInstanceKey` — sent on poll so Laravel can match before you name it in the UI.

### 3. Link bot profiles

In **Bot Profiles** → **MT5 Instance Key**, choose which terminal receives that profile's trades.

```json
{
  "key": "forex-scalper",
  "mt5_instance_key": "demo-pepperstone",
  "symbols": ["EURUSD", "GBPUSD"]
}
```

`mt5:auto-forex` then:

- Reads quotes/candles from that terminal's EA poll (no MetaAPI)
- Queues `BUY`/`SELL` commands to that instance only
- Logs trades as `pending` until the EA confirms (~1 second)

### 4. Multiple MT5 installs checklist

| Install | Account | InpInstanceKey | Laravel instance_key |
|---------|---------|----------------|----------------------|
| MT5 #1  | Demo A  | `demo-a`       | `demo-a`             |
| MT5 #2  | Demo B  | `demo-b`       | `demo-b`             |

Bot profile `scalper` → `demo-a`, profile `swing` → `demo-b`.

---

## Bot execution flow (no MetaAPI)

### 1. Run migrations

```bash
php artisan migrate
```

Creates:

- `mt5_ea_terminals` — registered EA instances (login, balance, last seen, positions)
- `mt5_ea_commands` — queued commands and their status
- `ea_bridge_token` column on `app_settings`

### 2. Get your API token

**Option A — Web UI**

1. Log in to Laravel.
2. Open **EA Bridge** in the navigation (`/ea-bridge`).
3. Copy the **API Token** field.

**Option B — Artisan**

```bash
php artisan ea:token
```

Regenerate (invalidates the old token in MT5):

```bash
php artisan ea:token --regenerate
```

The token is stored encrypted in `app_settings.ea_bridge_token`. It is auto-created on first access.

### 3. Verify the poll URL

Default endpoint:

```text
POST https://mt5.test/api/ea/poll
```

Replace `mt5.test` with your real `APP_URL` host.

Headers required by the EA:

```text
Content-Type: application/json
Accept: application/json
Authorization: Bearer YOUR_TOKEN_HERE
```

---

## Part 2 — MT5 Expert Advisor setup

### 1. Copy the EA file

Copy from this repo:

```text
mql5/Experts/LaravelBridge/LaravelBridge.mq5
```

To your MT5 data folder:

```text
%APPDATA%\MetaQuotes\Terminal\<HASH>\MQL5\Experts\LaravelBridge\LaravelBridge.mq5
```

On Windows, open MT5 → **File → Open Data Folder** → `MQL5\Experts\`.

### 2. Compile

1. Open **MetaEditor** (F4 from MT5).
2. Open `LaravelBridge.mq5`.
3. Press **Compile** (F7). Fix any errors before continuing.

### 3. Allow WebRequest (required)

MT5 blocks HTTP calls unless the URL is whitelisted.

1. **Tools → Options → Expert Advisors**
2. Check **Allow algorithmic trading**
3. Check **Allow WebRequest for listed URL**
4. Add your Laravel host, e.g.:
   - `https://mt5.test`
   - or `https://your-domain.com`
5. Click **OK** and restart MT5 if prompted.

Without this step, the EA logs `WebRequest failed err=4014` (URL not allowed).

### 4. Attach the EA to a chart

1. Open any chart (symbol does not limit which symbols you can trade).
2. Drag **LaravelBridge** from Navigator → Expert Advisors onto the chart.
3. Set inputs:

| Input | Description | Example |
|-------|-------------|---------|
| `InpServerUrl` | Laravel base URL, **no trailing slash** | `https://mt5.test` |
| `InpApiToken` | Token from `/ea-bridge` or `ea:token` | (64-char string) |
| `InpPollSeconds` | Poll interval in seconds | `1` |
| `InpMagic` | Magic number for bridge orders | `88001` |
| `InpDebug` | Print full API responses to Experts log | `false` |

4. Enable **Algo Trading** (toolbar button must be green).
5. Check the **Experts** tab — you should see: `LaravelBridge started. polling=1s url=...`

### 5. Confirm registration in Laravel

1. Refresh `/ea-bridge`.
2. Your account should appear under **Connected Terminals** with status **Online** (last seen within ~10 seconds).

---

## Part 3 — Queue and execute a trade

### Via web UI

1. Go to **EA Bridge → Queue Command**.
2. Example:
   - Action: `BUY`
   - Symbol: `GBPUSD`
   - Lot: `0.10`
   - SL (pips): `20`
   - TP (pips): `40`
3. Click **Queue for EA**.
4. Within ~1 second, the EA picks up the command and places the order.
5. Check **Recent Commands** for status: `pending` → `sent` → `completed` or `failed`.

### Via PHP

```php
use App\Services\EaBridgeService;

app(EaBridgeService::class)->queueCommand([
    'action' => 'BUY',
    'symbol' => 'GBPUSD',
    'lot'    => 0.10,
    'sl'     => 20,   // pips
    'tp'     => 40,   // pips
]);
```

Target a specific MT5 login (optional):

```php
app(EaBridgeService::class)->queueCommand([
    'action'         => 'SELL',
    'symbol'         => 'EURUSD',
    'lot'            => 0.05,
    'sl'             => 15,
    'tp'             => 30,
    'account_login'  => 12345678,
]);
```

If `account_login` is omitted, the next online terminal to poll receives the command.

---

## API contract

### EA → Laravel (poll request body)

```json
{
  "login": 12345678,
  "server": "Broker-Demo",
  "terminal_name": "MetaTrader 5",
  "broker_company": "Your Broker Ltd",
  "balance": 10000.00,
  "equity": 10050.00,
  "margin": 100.00,
  "free_margin": 9950.00,
  "currency": "USD",
  "trade_allowed": true,
  "positions": [
    {
      "ticket": 555001,
      "symbol": "GBPUSD",
      "type": "BUY",
      "lot": 0.10,
      "price_open": 1.26500,
      "sl": 1.26300,
      "tp": 1.26900,
      "profit": 12.50
    }
  ],
  "command_result": {
    "id": 5,
    "ok": true,
    "message": "Order placed",
    "ticket": 555002
  }
}
```

`command_result` is only sent after the EA has executed a command (reports success or failure).

### Laravel → EA (poll response)

**Command waiting:**

```json
{
  "ok": true,
  "command": {
    "id": 6,
    "action": "BUY",
    "symbol": "GBPUSD",
    "lot": 0.10,
    "sl": 20,
    "tp": 40
  }
}
```

**No command:**

```json
{
  "ok": true,
  "command": null
}
```

**Unauthorized (wrong/missing token):**

```json
{
  "ok": false,
  "error": "Unauthorized"
}
```

---

## Supported commands

| Action | Required fields | EA behavior |
|--------|-----------------|-------------|
| `BUY` | `symbol`, `lot` | Market buy; `sl`/`tp` in **pips** |
| `SELL` | `symbol`, `lot` | Market sell; `sl`/`tp` in **pips** |
| `CLOSE` | `ticket` | Close one position by ticket |
| `CLOSE_ALL` | — | Close all open positions |
| `MODIFY` | — | Queued in Laravel but **not yet implemented** in the EA |

SL/TP pip conversion matches standard forex rules (3/5 digit symbols use point × 10).

---

## Command statuses

| Status | Meaning |
|--------|---------|
| `pending` | Queued, waiting for EA poll |
| `sent` | Delivered to EA, awaiting result |
| `completed` | EA reported success |
| `failed` | EA or broker rejected the action |

---

## Troubleshooting

### Terminal shows Offline in Laravel

- EA not attached or Algo Trading disabled.
- Wrong `InpServerUrl` or `InpApiToken`.
- WebRequest URL not whitelisted in MT5 options.
- Laravel not reachable from the machine running MT5 (firewall, wrong host).

Enable `InpDebug = true` and read **Experts** tab in MT5.

### `WebRequest failed err=4014`

Add your Laravel URL under **Tools → Options → Expert Advisors → Allow WebRequest**.

### `INIT_PARAMETERS_INCORRECT` on attach

`InpApiToken` is empty or shorter than 16 characters. Copy the full token from `/ea-bridge`.

### Command stays `pending`

- No EA is polling (terminal offline).
- `account_login` on the command does not match the connected terminal.
- Database connection issue on Laravel side.

### Command `failed`

Check `error_message` in the database or enable `InpDebug`. Common causes:

- Symbol not available on broker (`SymbolSelect` failed).
- Trading disabled on account or terminal.
- Invalid lot size for the symbol.
- Insufficient margin.

### Token regenerated but EA still fails

Update `InpApiToken` on the chart (right-click EA → Properties → Inputs) or re-attach the EA.

### HTTPS / local dev (Herd)

Use `https://mt5.test` (or your Herd URL) in both MT5 WebRequest whitelist and `InpServerUrl`. For production, use a real domain with valid TLS.

---

## Security notes

- Treat the EA bridge token like a password. Anyone with the token can queue trades for connected terminals.
- Regenerate the token if it is exposed (`/ea-bridge` → **Regenerate Token** or `php artisan ea:token --regenerate`).
- Keep `demo_only` enabled in Laravel settings until you trust the setup.
- The `/ea-bridge` UI is protected by auth + `owner` middleware (same as the rest of the panel).

---

## Running tests

```bash
php artisan test --filter=EaBridgeTest
```

---

## Quick checklist

- [ ] `php artisan migrate`
- [ ] Copy API token from `/ea-bridge` or `php artisan ea:token`
- [ ] Copy `LaravelBridge.mq5` into MT5 `MQL5/Experts/` and compile
- [ ] Whitelist Laravel URL in MT5 WebRequest settings
- [ ] Attach EA with correct `InpServerUrl` and `InpApiToken`
- [ ] Algo Trading enabled (green button)
- [ ] Terminal shows **Online** on `/ea-bridge`
- [ ] Queue a small **demo** trade and confirm `completed` status

---

## Branch note

The EA bridge feature lives on the **`mttest`** branch (Laravel + `LaravelBridge.mq5`). Pine/MQL strategy work is on the **`mql`** branch. Merge or cherry-pick as needed for your deployment.
