# MT5 Scalper Reference (Strategy + Implementation)

## 1) Goal

Improve consistency by reducing weak entries, avoiding noisy trades, and allowing exceptions when a stronger setup appears during cooldown.

## 2) Strategy Recommendation (Reference)

### Core framework

- Use M15 as trend filter.
- Use M5 as signal timeframe.
- Use M1 only for fine entry timing, not standalone signal generation.
- Only trade when M15 and M5 agree with signal direction.

### Quality filters

- Increase minimum move threshold to avoid marginal momentum entries.
- Keep tight spread filter to avoid paying too much friction.
- Keep AI confirmation enabled with stronger confidence threshold.
- Limit symbols to top liquid majors for scalping.

### Risk and execution controls

- Use conservative daily loss cap.
- Limit max open positions and max entries per cycle.
- Keep cooldown, but allow override when the new setup is clearly stronger.

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

Use this strict-quality baseline preset:

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
  --min-move-pips=12 \
  --max-spread-pips=1.0 \
  --cooldown-minutes=12 \
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

## 5) Weekday Cron Entry

```cron
* * * * 1-5 cd /c/Users/Nasee/Herd/mt5/app && php artisan mt5:auto-forex --once --scalper=1 --trend-filter=1 --cooldown-override-ratio=1.25 --lot=0.01 --tp-pips=15 --sl-pips=10 --trail-start-pips=8 --trail-pips=5 --trail-tp-multiplier=1.5 --min-move-pips=12 --max-spread-pips=1.0 --cooldown-minutes=12 --session-start-utc=6 --session-end-utc=16 --max-trades-per-day=8 --max-daily-loss-percent=1.5 --ai-confirm=1 --ai-min-confidence=78 --min-bot-score=78 --max-symbols=40 --max-open-positions=3 --max-per-cycle=2 >> storage/logs/cron-auto-forex.log 2>&1
```

## 6) Symbol Universe (Scalper)

Use only liquid majors for this profile:

- EURUSD
- GBPUSD
- USDJPY
- USDCAD
- AUDUSD

Set these in bot profile symbols and avoid running full symbol discovery for scalping.

## 7) Next Validation Checklist

After 40-60 resolved trades, review:

- Win rate by symbol
- Net P/L by symbol
- Avg win vs avg loss
- Rejected signals by reason (trend_rejected, cooldown_rejected, spread_rejected)

Tune only one parameter group at a time (signal quality first, then risk settings).

php artisan mt5:auto-forex --scalper=1 --trend-filter=1 --cooldown-override-ratio=1.25 --lot=0.01 --tp-pips=15 --sl-pips=10 --trail-start-pips=8 --trail-pips=5 --trail-tp-multiplier=1.5 --min-move-pips=12 --max-spread-pips=1.0 --cooldown-minutes=12  --max-daily-loss-percent=1.5 --ai-confirm=1 --ai-min-confidence=78 --min-bot-score=78 --max-symbols=40 --max-open-positions=3 --max-per-cycle=2