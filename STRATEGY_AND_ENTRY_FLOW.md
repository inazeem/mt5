# Strategy and Trade Entry Flow

This document explains how the bot decides when to open a trade, how mixed strategies work, and what can block a trade.

## 1. Big Picture

The auto bot runs through symbols and opens a trade only when all required checks pass.

Core command:
- `php artisan mt5:auto-forex --once`

Scheduled in app:
- Runs every minute with overlap protection.

## 2. Configuration Priority (Who overrides what)

For most bot options, priority is:
1. CLI flag (if explicitly passed)
2. Bot profile value
3. Global setting
4. Hardcoded fallback default

Example:
- If profile has `tp_pips=20`, and global has `tp_pips=25`, profile wins.
- If CLI runs with `--tp-pips=30`, CLI wins.

Important for timeframe-driven entry:
- `entry_timeframe` follows the same priority chain.
- CLI can override with `--entry-timeframe=...`.
- If entry timeframe is missing or invalid, bot falls back to the lowest selected signal timeframe.

## 3. Multi-Bot Profiles

If multiple bot profiles are enabled:
- One command cycle loops profiles sequentially.
- Each profile runs its own independent decision process.
- You can run only one profile with `--bot=<key_or_name>`.

## 4. Strategy Selection Logic

Selected strategies come from:
1. `--strategies` CLI (comma-separated), OR
2. profile `strategies[]` (or legacy `strategy`), OR
3. global `bot_strategies[]` (or legacy `bot_strategy`), OR
4. fallback `momentum`

If legacy `--strategy` is provided without `--strategies`, it is treated as single-strategy mode.

## 5. When Does It Enter a Trade?

A trade is opened only if ALL relevant checks pass.

### Step A: Basic quote/data checks
- Symbol quote must be valid (`bid > 0`, `ask > 0`).
- If needed by strategy, candle data must load successfully.

### Step B: Strategy signal checks
- Every selected strategy is evaluated.
- If any strategy returns no signal, symbol is skipped.
- If strategies disagree on direction (buy vs sell), symbol is skipped.
- If all selected strategies agree, that side becomes the candidate side.

Signal strength for mixed strategies:
- Bot uses average of selected strategies' `signal_delta_pips` as combined strength.

### Step C: Risk and guardrail checks
- Effective volume check (`lot * volume_multiplier` must be above minimum).
- Max spread check (category-aware: forex/stock/commodity/other).
- Existing open position on same symbol check.
- Cooldown check (with optional override ratio).
- Session hours check.
- Preferred/blocked hours checks.
- Preferred symbols whitelist check (if configured).
- Daily guardrails (max trades/day, max daily loss percent).
- Open positions cap and per-cycle cap.

### Step D: Trend filter (optional)
- If enabled, selected trend timeframes form the direction context.
- When multiple timeframes are selected, bot checks higher-timeframe context first.
- Bot then waits for the configured `entry_timeframe` trigger to align before entry.
- If context aligns but entry timeframe is not ready yet, signal is logged as waiting (`entry_timeframe_wait`).

### Step E: AI confirmation (optional)
- If enabled, AI must approve and pass min confidence threshold.

Only after all above checks pass, order placement is attempted.

## 6. Strategy Mix Behavior

If you choose 2 or 3 strategies:
- Trade only happens when ALL selected strategies agree.

Examples:
- SMA says BUY, EMA says BUY -> possible entry.
- SMA says BUY, EMA says SELL -> rejected (strategy conflict).
- SMA says BUY, EMA says BUY, VWAP says no signal -> rejected.

## 7. Current Strategies

### Momentum
- Uses recent tick movement vs `min_move_pips` threshold.
- No extra strategy-specific params required.

### SMA Cross
- Uses `sma_fast`, `sma_slow`, `sma_confirm_candles`.
- `sma_confirm_candles=0` means immediate direction signal.
- `sma_confirm_candles>0` requires the SMA direction (fast above/below slow) to persist for that many additional closed candles.

### EMA Cross
- Uses `ema_fast`, `ema_slow`, `ema_confirm_candles`.
- `ema_confirm_candles=0` means immediate direction signal.
- `ema_confirm_candles>0` requires EMA direction persistence across closed candles to filter whipsaws.

### Bollinger Reversion
- Uses `bb_period`, `bb_stddev`, `bb_confirm_candles`.
- `bb_confirm_candles=0` reacts on first band break.
- `bb_confirm_candles>0` requires price to remain outside the same band for consecutive candles before signaling.

### VWAP Reversion
- Uses `vwap_period`, `vwap_min_distance_pips`, `vwap_confirm_candles`.
- `vwap_confirm_candles=0` reacts on first distance breach.
- `vwap_confirm_candles>0` requires VWAP distance condition to hold across closed candles before signaling.

## 8. Why You Might See "Signal But No Trade"

Common reasons:
- One strategy in the mix did not confirm.
- Strategies conflicted on side.
- Spread too high for that symbol category (for example stock spread can use a different limit than forex).
- Symbol in cooldown.
- Trend filter not aligned across selected timeframes.
- Trend context aligned, but entry timeframe has not triggered yet.
- AI rejected or confidence too low.
- Daily loss / trade-count guardrail reached.
- Max open positions / max per cycle reached.

## 9. Where to Manage What

- Bot runtime defaults and strategy checkboxes: Bot page (`/bot`)
- Entry timeframe default (final trigger timeframe): Bot page (`/bot`)
- Strategy parameters (SMA/EMA/BB/VWAP): Strategies page (`/strategies`)
- Per-profile overrides (including entry timeframe): Bot Profiles pages
- Operational diagnostics: Bot Alerts (`/bot/alerts`) and Bot Health (`/bot/health`)

## 10. Practical Starter Setup

If you want a balanced start:
1. Select 2 strategies (for example `ema_cross` + `vwap_reversion`).
2. Keep trend filter on with `15m,30m`.
3. Set entry timeframe to `15m` (or `5m` if you want earlier but noisier triggers).
4. Keep AI confirmation on and confidence around `70`.
5. Keep volume multiplier at `1` until stable results.
6. Review Bot Alerts reasoning and only then tighten/loosen thresholds.

## 11. Quick Mental Model

The bot is not "first signal wins".
It is "consensus plus guardrails".

In short:
- Consensus from selected strategies
- Direction context from selected trend timeframes
- Trigger from configured entry timeframe
- Approval from risk limits and (optionally) AI
- Then trade

## 12. Category-Level Spread Limits

You can now set spread limits by category in one command/profile value.

CLI option:
- `--max-spread-pips-by-category="forex:2.5,stock:25,commodity:15,other:10,default:2.5"`

How category is resolved:
- Uses ticker `category` first (from `tickers` table).
- Falls back to symbol heuristic when category is missing.

Supported keys:
- `forex`, `stock`, `commodity`, `other`, `default`

If not set, defaults are automatically applied:
- `forex` = existing `max_spread_pips`
- `stock` = max(existing, 25)
- `commodity` = max(existing, 15)
- `other` = max(existing, 10)
- `default` = existing `max_spread_pips`

## 13. Ticker-Level Spread Override

Ticker settings now support a per-symbol spread cap.

Resolution order for max spread used by bot:
1. Ticker `max_spread_pips` (if set on that symbol)
2. Category map from `--max-spread-pips-by-category`
3. `default` from category map
4. Global `max_spread_pips`

Ticker category input is now a dropdown with these values:
- `Forex`
- `Stock`
- `Commodity`
- `Index`
- `Crypto`
- `Other`

Practical example:
- Set category `Forex` and leave ticker spread blank to use forex default spread.
- Set category `Forex` and set ticker spread to `3.2` to override only that symbol.
