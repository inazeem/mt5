# Ideal Bot Setup (Forex Trend-Following)

Reference configuration for `mt5:auto-forex` when you want **aligned strategies and timeframes to trade in the same direction** (not contrarian).

Related docs:

- [`routes/console.md`](routes/console.md) — full command reference
- [`STRATEGY_AND_ENTRY_FLOW.md`](STRATEGY_AND_ENTRY_FLOW.md) — entry pipeline
- [`app/Services/console-vs-mt5service.md`](app/Services/console-vs-mt5service.md) — what lives in console vs Mt5Service

---

## Before you start

1. **`reverse-strategy=0`** (unchecked on bot profile) — trades **with** consensus.  
   Only enable reverse for deliberate contrarian / test runs.
2. **`demo_only=1`** in settings until you trust results.
3. After any config change, verify one trade in **Bot Alerts**:  
   `recommended_side` and `execution_side` should **match** when reverse is off.

---

## Mental model

```text
Higher timeframes (1h, 4h)  →  direction context (trend filter)
15m candles                 →  strategy signals (SMA/EMA) + entry trigger
Guardrails                  →  spread, cooldown, daily limits, AI
Execution                   →  same side as consensus (reverse-strategy=0)
```

Do **not** mix mean-reversion strategies (Bollinger, VWAP) with trend strategies (SMA, EMA) in the same profile unless you understand they often conflict.

---

## Recommended bot profile (JSON)

Save in **Settings → Bot profiles** or create via **Bot Profiles** UI. Adjust `key` / `name` as needed.

```json
{
  "key": "forex_trend",
  "name": "Forex Trend",
  "enabled": true,
  "lot": 0.01,
  "tp_pips": 25,
  "sl_pips": 15,
  "trail_start_pips": 12,
  "trail_pips": 8,
  "trail_tp_multiplier": 2,
  "min_move_pips": 4,
  "max_spread_pips": 2.5,
  "cooldown_minutes": 30,
  "cooldown_override_ratio": 1.25,
  "session_start_utc": 6,
  "session_end_utc": 16,
  "max_trades_per_day": 8,
  "max_trades_per_asset_per_day": 2,
  "max_daily_loss_percent": 1.5,
  "max_open_positions": 3,
  "max_per_cycle": 2,
  "max_symbols": 40,
  "ai_confirm": true,
  "ai_min_confidence": 78,
  "min_bot_score": 78,
  "scalper": false,
  "reverse_strategy": false,
  "trend_filter": true,
  "strategies": ["ema_cross", "sma_cross"],
  "signal_timeframes": ["1h", "4h"],
  "entry_timeframe": "15m",
  "blocked_hours_utc": [15],
  "preferred_symbols": []
}
```

### Why these choices

| Setting | Value | Reason |
|---------|-------|--------|
| `strategies` | `ema_cross`, `sma_cross` | Two trend strategies; both must agree |
| `signal_timeframes` | `1h`, `4h` | HTF context only (15m is entry, not duplicated) |
| `entry_timeframe` | `15m` | Trigger on lowest TF; strategies also evaluate 15m candles |
| `reverse_strategy` | `false` | Trade **with** alignment |
| `trend_filter` | `true` | 1h + 4h last candle must match signal side; then 15m entry candle |
| `min_move_pips` | `4` | Reduces tick noise vs default `3` |
| `max_trades_per_asset_per_day` | `2` | Stops over-trading same pair |
| `max_per_cycle` | `2` | Limits correlated losses in one scan |
| `min_bot_score` / AI | `78` | Fewer but higher-quality entries |
| `tp_pips` / `sl_pips` | `25` / `15` | ~1.67 R:R; needs ~40%+ win rate after spread |

---

## Strategy parameters (Strategies page)

Set on **`/strategies`** (global) or override in profile `strategy_params`:

| Parameter | Suggested | Purpose |
|-----------|-----------|---------|
| `sma_fast` | `9` | Fast SMA period |
| `sma_slow` | `21` | Slow SMA period |
| `sma_confirm_candles` | `1` | Cross must hold 1 extra closed 15m bar |
| `ema_fast` | `9` | Fast EMA period |
| `ema_slow` | `21` | Slow EMA period |
| `ema_confirm_candles` | `1` | Same whipsaw filter for EMA |

Avoid `momentum` in this profile — it uses **tick-to-tick** movement between 1-minute cycles, not 15m structure, and can fire late in a move.

---

## Cron / CLI command

One-shot (manual or scheduler):

```bash
php artisan mt5:auto-forex --once --bot=forex_trend \
  --lot=0.01 \
  --tp-pips=25 \
  --sl-pips=15 \
  --trail-start-pips=12 \
  --trail-pips=8 \
  --min-move-pips=4 \
  --max-spread-pips=2.5 \
  --cooldown-minutes=30 \
  --cooldown-override-ratio=1.25 \
  --session-start-utc=6 \
  --session-end-utc=16 \
  --max-trades-per-day=8 \
  --max-trades-per-asset-per-day=2 \
  --max-daily-loss-percent=1.5 \
  --max-open-positions=3 \
  --max-per-cycle=2 \
  --max-symbols=40 \
  --trend-filter=1 \
  --signal-timeframes=1h,4h \
  --entry-timeframe=15m \
  --strategies=ema_cross,sma_cross \
  --reverse-strategy=0 \
  --ai-confirm=1 \
  --ai-min-confidence=78 \
  --min-bot-score=78 \
  --blocked-hours-utc=15
```

Scheduler (already in `routes/console.php`):

```bash
* * * * * cd /path/to/project && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Runs `mt5:auto-forex --once` every minute with overlap lock.

---

## Symbol list

Prefer a **small, liquid majors** list on the profile instead of scanning 200 symbols:

```json
"symbols": ["EURUSD", "GBPUSD", "USDJPY", "AUDUSD", "USDCAD", "USDCHF"]
```

Or maintain **`/tickers`** with `active=1` and category `forex`.

---

## What to watch (Bot Alerts / Analytics)

| Check | Good sign | Bad sign |
|-------|-----------|----------|
| `recommended_side` vs `execution_side` | Same when reverse off | Always opposite → reverse on or old bug |
| `status=confirmed` then `trade_open` | Flow completes | Many `ai_rejected`, `spread_rejected` |
| `strategy_conflict_rejected` | Rare | Often → drop a conflicting strategy |
| `entry_timeframe_wait` | Some | Too many → 15m trigger too strict vs 1h/4h |
| Win rate @ 25/15 SL | ≥ 40% over 30+ trades | Below 35% → tighten score or hours |

Filter alerts by bot key and read `alert_reasoning` / `meta_payload.strategy_results`.

---

## Tuning loop (weekly)

1. Run shadow analysis:

   ```bash
   php artisan mt5:learn-policy --bot=forex_trend --lookback-days=30
   ```

2. Review recommended `min_bot_score`, `preferred_hours_utc`, `blocked_hours_utc`, `preferred_symbols`.

3. Apply only after ≥ 20 resolved trades:

   ```bash
   php artisan mt5:learn-policy --bot=forex_trend --lookback-days=30 --apply
   ```

4. Change **one** variable at a time (score, hours, or SL/TP — not all at once).

---

## Configurations to avoid

| Setup | Problem |
|-------|---------|
| `reverse-strategy=1` for live trend trading | Fades every aligned signal |
| `momentum` + `sma_cross` + `ema_cross` | Tick spike can align with 15m late in move |
| `bollinger_reversion` + `ema_cross` | Reversion vs trend — frequent conflict or bad agreement |
| `signal-timeframes=15m,1h,4h` with entry `15m` | 15m used twice (strategies + trend); noisier |
| `max_per_cycle=5`, many symbols | Several correlated losses same cycle |
| SL `15` on volatile pairs without wider spread filter | Stop hunts on 15m noise |
| `--test-mode` on production | Bypasses all safety |

---

## If you still see “trend reversal” losses

Even with correct execution direction:

1. **Last-candle trend filter is lagging** — one green 4h bar can be the end of a move. Consider `blocked_hours_utc` around news (e.g. 12–15 UTC).
2. **Raise `min_bot_score`** to 82–85 for a week and compare win rate.
3. **Increase `cooldown_minutes`** to 45–60 on pairs that re-enter too fast.
4. **Use `learn-policy` preferred_symbols** — drop pairs with negative net P/L.
5. **Trailing** — if winners turn to breakeven losses, review `trail_start_pips` (12+) so SL locks profit sooner.

---

## Quick verification checklist

- [ ] Bot profile: `reverse_strategy` unchecked  
- [ ] Strategies: `ema_cross`, `sma_cross` only  
- [ ] Trend: `1h,4h` context + `15m` entry  
- [ ] `max_trades_per_asset_per_day=2`  
- [ ] Alerts show `recommended_side` === `execution_side`  
- [ ] Demo account only until 30+ resolved trades look sane  
- [ ] Re-read this doc after any profile edit  

---

## Change log

| Date | Note |
|------|------|
| 2026-06 | Fixed execution: `reverse-strategy=0` now trades **with** consensus (previously always inverted). |
