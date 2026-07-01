# EA Bridge Setup (Laravel ↔ MT5)

Remote-control your MT5 account from the Laravel panel. The **LaravelBridge** Expert Advisor polls your server every second, sends account data, and executes queued trade commands.

This is an alternative to **MetaAPI** (`Mt5Service`). **Forex bot profiles default to EA Bridge**; you can switch any profile back to MetaApi under **Execution broker** in Bot Profiles. Alpaca crypto profiles are unchanged.

| Approach | Best for |
|----------|----------|
| **EA Bridge** (this doc) | MT5 multi-account, LaravelBridge, direct broker execution |
| **MetaApi** | Cloud MT5 when EA bridge is not used (Settings → token + account ID) |
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

### 1. One instance = one API token

Each MT5 install gets its **own** API token. Create the instance in Laravel first, copy that token into `InpApiToken` on that chart only. Authentication binds the EA to that instance row on the first poll.

### 2. Register instances

1. Open **MT5 Instances** (`/ea-bridge`).
2. Click **Create Instance** — name it by broker and environment (e.g. `Pepperstone Demo`, `IC Markets Live`).
3. Copy the revealed **API Token** into MT5 → `LaravelBridge` → `InpApiToken`.
4. Attach `LaravelBridge` on that MT5 account. After the first poll, login/server bind to the instance.

Optional EA input: `InpInstanceKey` — must match the Laravel **Instance Key** if you set one manually.

Use **Test Trade** on the instance row (when **Online**) to queue a small demo buy and confirm the EA executes it.

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

| Install | Display name | Instance key | Token |
|---------|--------------|--------------|-------|
| MT5 #1  | Pepperstone Demo | `pepperstone-demo` | unique per instance |
| MT5 #2  | IC Markets Live  | `ic-markets-live`  | unique per instance |

Bot profile `scalper` → `pepperstone-demo`, profile `swing` → `ic-markets-live`.

### 5. EA Bridge vs MetaApi (per bot profile)

In **Bot Profiles → Forex / MT5 execution → Execution broker**:

| Value | Behavior |
|-------|----------|
| **EA Bridge (LaravelBridge)** | Default. Uses instance checkboxes, polls `/api/ea/poll`, queues trades to selected MT5 terminals. |
| **MetaApi (cloud MT5)** | Uses **Settings → MetaApi token + account ID**. Trailing stops and MetaApi outage cooldown apply. Instance checkboxes are ignored. |

Existing profiles without `mt5_broker` behave as **EA Bridge**. The manual **Bot** dashboard (`/bot`) still uses MetaApi for live positions when configured, independent of profile broker choice.

---

## Bot execution flow (EA Bridge)

### 1. Run migrations

```bash
php artisan migrate
```

Creates:

- `mt5_ea_terminals` — registered EA instances (name, demo/live, per-instance API token, login, balance, last seen, positions)
- `mt5_ea_commands` — queued commands and their status (`source`: `test`, `manual`, `bot`)

### 2. Get your API token (per instance)

**Option A — Web UI**

1. Log in to Laravel.
2. Open **MT5 Instances** (`/ea-bridge`).
3. **Create Instance** or use **Regenerate Token** on an existing row.
4. Copy that instance's **API Token** into `InpApiToken` on the matching MT5 chart.

**Option B — Artisan**

```bash
php artisan ea:token pepperstone-demo
```

Regenerate for one instance (invalidates the old token in that MT5):

```bash
php artisan ea:token pepperstone-demo --regenerate
```

Tokens are stored encrypted on each `mt5_ea_terminals` row (`api_token` / `api_token_hash`).

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

Use `https://mt5.test` (or your Herd URL) in both MT5 WebRequest whitelist and `InpServerUrl`. For production, use a real domain with valid TLS (see below).

---

## Production deployment (iiadigital)

Use your live Laravel app instead of Herd when MT5 should talk to the server on the internet (e.g. **`https://mt5.iiadigital.co.uk`**). The EA bridge is the same API; only the base URL and tokens change.

### Server (Laravel)

1. Deploy the latest code (branch **`mttest`** or merged main).
2. Set in `.env`:

   ```env
   APP_URL=https://mt5.iiadigital.co.uk
   ```

3. Run migrations on production:

   ```bash
   php artisan migrate
   ```

4. Log in to **`https://mt5.iiadigital.co.uk/ea-bridge`** and create each MT5 instance there.
5. Use **Show Token** (or create/regenerate) and copy the token — **production tokens are separate from Herd**. Tokens from `mt5.test` do not work on iiadigital unless you recreate the same instances on the live database.

### MT5 (each chart)

1. **Tools → Options → Expert Advisors → Allow WebRequest** — add:

   ```text
   https://mt5.iiadigital.co.uk
   ```

   (Host only, no path, no trailing slash.)

2. **LaravelBridge** inputs:

   | Input | Example |
   |-------|---------|
   | `InpServerUrl` | `https://mt5.iiadigital.co.uk` |
   | `InpApiToken` | From live `/ea-bridge` → **Credentials** → **Show Token** |
   | `InpInstanceKey` | Same as the instance key on live (if set) |

   Poll endpoint: `https://mt5.iiadigital.co.uk/api/ea/poll`

3. Enable **Algo Trading**, attach the EA, confirm the instance is **Online** on live `/ea-bridge`.

### Verify on production

- [ ] `APP_URL` matches `https://mt5.iiadigital.co.uk`
- [ ] `php artisan migrate` completed on production
- [ ] Instances created on **live** `/ea-bridge` (not only Herd)
- [ ] WebRequest whitelist includes `https://mt5.iiadigital.co.uk`
- [ ] `InpServerUrl` = `https://mt5.iiadigital.co.uk` (no trailing slash)
- [ ] `InpApiToken` = token from **live** instance (**Show Token**)
- [ ] Instance **Online** on live site; **Test Trade** completes
- [ ] No `4014` in Experts (URL not allowed) or `401` (wrong/missing token)

### Local vs production

| | Herd (dev) | Production |
|---|------------|------------|
| URL | `https://mt5.test` | `https://mt5.iiadigital.co.uk` |
| Instances / tokens | Local DB | Production DB — recreate on live |
| MT5 whitelist | `https://mt5.test` | `https://mt5.iiadigital.co.uk` |

Do not point one EA at Herd and another at production unless you intentionally run two environments. Each chart should use one base URL and the matching instance token from that same environment.

Requirements: valid HTTPS certificate on the domain, Laravel reachable from the internet (not localhost-only), firewall allows normal HTTPS to the app.

---

## Security notes

- Treat each instance API token like a password. Anyone with the token can queue trades for that terminal.
- Regenerate the token if it is exposed (`/ea-bridge` → **Regenerate Token** on that instance, or `php artisan ea:token {instance} --regenerate`).
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
- [ ] Create each instance at `/ea-bridge` and copy its API token (or `php artisan ea:token {instance}`)
- [ ] Copy `LaravelBridge.mq5` into MT5 `MQL5/Experts/` and compile
- [ ] Whitelist Laravel URL in MT5 WebRequest settings
- [ ] Attach EA with correct `InpServerUrl` and that instance's `InpApiToken`
- [ ] Algo Trading enabled (green button)
- [ ] Instance shows **Online** on `/ea-bridge`
- [ ] Click **Test Trade** or queue a small **demo** trade and confirm `completed` status

---

## Branch note

The EA bridge feature lives on the **`mttest`** branch (Laravel + `LaravelBridge.mq5`). Pine/MQL strategy work is on the **`mql`** branch. Merge or cherry-pick as needed for your deployment.
