# Console vs Mt5Service

> **Full reference:** see [`routes/console.md`](../../routes/console.md) for complete documentation of `routes/console.php`.  
> **Recommended production config:** see [`IDEAL_BOT_SETUP.md`](../../IDEAL_BOT_SETUP.md).

## Purpose

This note explains the difference between:

- `routes/console.php`
- `app/Services/Mt5Service.php`

Use it as a quick rule for where new bot logic should live.

## Short Version

- `routes/console.php` is the bot's decision layer.
- `Mt5Service.php` is the broker/MetaAPI execution layer.

If the code is deciding whether the bot should trade, skip, reject, or wait, it belongs in `console.php`.

If the code is talking to MetaAPI, resolving broker symbols, fetching prices/candles, or sending trade requests, it belongs in `Mt5Service.php`.

## What `routes/console.php` Does

`routes/console.php` contains the `mt5:auto-forex` command and most of the actual bot strategy flow.

This file is responsible for:

- reading CLI options like `--trend-filter`, `--cooldown-override-ratio`, `--max-spread-pips`, `--ai-confirm`
- merging CLI options with bot profile values and DB settings
- deciding which symbols to scan
- reading the latest quote and comparing it to the cached previous quote
- deciding trade side from price movement
- enforcing filters and guardrails
- deciding whether a signal is rejected, confirmed, or traded
- logging signal and trade outcomes to `BotTradeLog`
- calling `Mt5Service` only when market data or execution is needed

### Strategy logic currently in `console.php`

Examples of logic that live there:

- minimum move filter
- spread filter
- cooldown filter
- cooldown override
- M15/M5 trend filter
- daily trade limit
- daily loss guard
- max open positions guard
- max per cycle guard
- AI confirmation and confidence threshold
- bot score threshold
- symbol list selection order

In other words, `console.php` answers the question:

"Should the bot trade right now?"

## What `Mt5Service.php` Does

`Mt5Service.php` is a service wrapper around MetaAPI/MT5 operations.

This file is responsible for:

- building authenticated MetaAPI requests
- resolving broker symbol variants like `_SB`, `_sb`, `.a`, `.c`
- fetching current prices
- fetching candles
- fetching account info
- fetching open positions and orders
- discovering available forex symbols
- placing market orders
- closing positions
- modifying stops
- applying trailing stops

`Mt5Service.php` does not decide whether a trade is a good idea.

It answers a different question:

"How do we fetch broker data or execute a broker action safely?"

## Simple Mental Model

Think of the split like this:

- `console.php` is the trader brain.
- `Mt5Service.php` is the broker hands and broker phone line.

The brain decides:

- buy or sell
- trade or skip
- reject or approve
- which filter blocked the trade

The service performs:

- get quote
- get candles
- place order
- update stops
- resolve symbol format

## Current Data Flow

The current bot flow is roughly:

1. `console.php` starts the `mt5:auto-forex` command.
2. `console.php` resolves settings, profile values, and command options.
3. `console.php` chooses symbols to scan.
4. `console.php` asks `Mt5Service` for quotes and candles.
5. `console.php` applies filters and strategy rules.
6. If a trade is allowed, `console.php` asks `Mt5Service` to place the order.
7. `console.php` records the result in `BotTradeLog`.
8. `Mt5Service` handles the MetaAPI request/response details.

## Examples of Where Code Should Go

### Put it in `console.php`

Use `console.php` when adding logic like:

- reject trades during a certain market session
- require M15, M5, and maybe H1 alignment
- change scoring rules
- add a new guardrail before opening trades
- add a cooldown rule based on last trade outcome
- prefer only some symbols for one bot profile

These are strategy and orchestration decisions.

### Put it in `Mt5Service.php`

Use `Mt5Service.php` when adding logic like:

- support another broker symbol suffix
- fetch a new type of MetaAPI market data
- retry a specific API call more safely
- normalize broker volume rules
- change how trailing stop requests are sent
- add a helper for another MetaAPI endpoint

These are transport, broker, or execution concerns.

## What Is Not Fully Implemented Yet

The strategy note in `best scalper.md` contains a few ideas that are not fully implemented in either file.

Examples:

- M1 fine-entry timing is not implemented.
- "Top liquid majors only" is not automatically enforced unless bot profile symbols are explicitly set.
- The trend model is currently simple candle-direction alignment, not a richer multi-layer market structure model.

That means the strategy note is partly reference and partly implementation status, not a one-to-one map of live code.

## Rule of Thumb for Future Changes

Before adding new logic, ask:

1. Is this deciding whether to trade?
   Put it in `console.php`.
2. Is this fetching broker data or executing broker actions?
   Put it in `Mt5Service.php`.
3. Is this reusable broker integration code that more than one command/controller may need?
   Put it in `Mt5Service.php`.
4. Is this specific to the `mt5:auto-forex` command's strategy?
   Put it in `console.php`.

## Practical Summary

- Change strategy in `routes/console.php`.
- Change broker integration in `app/Services/Mt5Service.php`.
- Keep `Mt5Service.php` reusable and focused on MetaAPI operations.
- Keep `console.php` responsible for bot rules, orchestration, and logging.