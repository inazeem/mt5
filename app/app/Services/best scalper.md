# MT5 Scalper Reference (Strategy + Implementation)

## 1) Goal

Improve consistency by reducing weak entries, avoiding noisy trades, and allowing exceptions when a stronger setup appears during cooldown.

## 2) Strategy Recommendation (Reference)

This section is strategy guidance, not a full statement of what the bot enforces automatically today. The code-backed items are listed in Section 3.

### Core framework

- Use M15 as trend filter. Status: implemented when `--trend-filter=1`.
- Use M5 as signal timeframe. Status: partially implemented as part of the same M15/M5 trend check when `--trend-filter=1`.
- Use M1 only for fine entry timing, not standalone signal generation. Status: not implemented.
- Only trade when M15 and M5 agree with signal direction. Status: implemented when `--trend-filter=1`.

### Quality filters

- Increase minimum move threshold to avoid marginal momentum entries. Status: implemented through `--min-move-pips`.
- Keep tight spread filter to avoid paying too much friction. Status: implemented through `--max-spread-pips`.
- Keep AI confirmation enabled with stronger confidence threshold. Status: implemented through `--ai-confirm` and `--ai-min-confidence`.
- Limit symbols to top liquid majors for scalping. Status: partially implemented; enforced only if bot profile symbols are explicitly set.

### Risk and execution controls

- Use conservative daily loss cap. Status: implemented through `--max-daily-loss-percent`.
- Limit max open positions and max entries per cycle. Status: implemented through `--max-open-positions` and `--max-per-cycle`.
- Keep cooldown, but allow override when the new setup is clearly stronger. Status: implemented through `--cooldown-minutes` and `--cooldown-override-ratio`.

## 3) Implemented Logic Upgrades (Already Added)

The command logic now includes both improvements below:

1. Trend agreement filter

- New option: --trend-filter=1
- Requires both M15 and M5 trend to match the signal side before entry.
- If not aligned, signal is logged as trend_rejected.

2. Smarter cooldown override

- New option: --cooldown-override-ratio=1.25
- During cooldown, a new signal is allowed only if:
  abs(current signal move) >= abs(last successful signal move) x ratio
- If not strong enough, signal is logged as cooldown_rejected.

## 4) Recommended Baseline Command

Use this baseline preset for the current scalper implementation:

```bash
php artisan mt5:auto-forex --once \
  --scalper=1 \
  --trend-filter=1 \
  --cooldown-override-ratio=1.25 \
  --lot=0.01 \
  --tp-pips=15 \
  --sl-pips=10 \
  --trail-start-pips=8 \
  --trail-pips=5 \
  --trail-tp-multiplier=1.5 \
  --min-move-pips=1.5 \
  --max-spread-pips=1.0 \
  --cooldown-minutes=5 \
  --session-start-utc=6 \
  --session-end-utc=16 \
  --max-trades-per-day=8 \
  --max-daily-loss-percent=1.5 \
  --ai-confirm=1 \
  --ai-min-confidence=78 \
  --min-bot-score=78 \
  --max-symbols=40 \
  --max-open-positions=3 \
  --max-per-cycle=2
```

Note: with `--scalper=1`, the current code clamps `min-move-pips` to `1.5` and `cooldown-minutes` to `5`, so the command above reflects actual runtime behavior.

## 5) Weekday Cron Entry

```cron
* * * * 1-5 cd /c/Users/Nasee/Herd/mt5/app && php artisan mt5:auto-forex --once --scalper=1 --trend-filter=1 --cooldown-override-ratio=1.25 --lot=0.01 --tp-pips=15 --sl-pips=10 --trail-start-pips=8 --trail-pips=5 --trail-tp-multiplier=1.5 --min-move-pips=1.5 --max-spread-pips=1.0 --cooldown-minutes=5 --session-start-utc=6 --session-end-utc=16 --max-trades-per-day=8 --max-daily-loss-percent=1.5 --ai-confirm=1 --ai-min-confidence=78 --min-bot-score=78 --max-symbols=40 --max-open-positions=3 --max-per-cycle=2 >> storage/logs/cron-auto-forex.log 2>&1
```

## 6) Symbol Universe (Scalper)

Use only liquid majors for this profile:

- EURUSD
- GBPUSD
- USDJPY
- USDCAD
- AUDUSD

Set these in bot profile symbols if you want this list enforced exactly. If bot profile symbols are empty, the bot currently falls back to active tickers in the database, then to MetaAPI discovery.

## 7) Next Validation Checklist

After 40-60 resolved trades, review:

- Win rate by symbol
- Net P/L by symbol
- Avg win vs avg loss
- Rejected signals by reason (trend_rejected, cooldown_rejected, spread_rejected)

Tune only one parameter group at a time (signal quality first, then risk settings).

## 8) Quick Run Example

```bash
php artisan mt5:auto-forex --scalper=1 --trend-filter=1 --cooldown-override-ratio=1.25 --lot=0.01 --tp-pips=15 --sl-pips=10 --trail-start-pips=8 --trail-pips=5 --trail-tp-multiplier=1.5 --min-move-pips=1.5 --max-spread-pips=1.0 --cooldown-minutes=5 --max-daily-loss-percent=1.5 --ai-confirm=1 --ai-min-confidence=78 --min-bot-score=78 --max-symbols=40 --max-open-positions=3 --max-per-cycle=2
```