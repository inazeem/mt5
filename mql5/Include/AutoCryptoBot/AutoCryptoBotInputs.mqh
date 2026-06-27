#ifndef AUTO_CRYPTO_BOT_INPUTS_MQH
#define AUTO_CRYPTO_BOT_INPUTS_MQH

// Defaults match Laravel bot profile "crypto" (Execution, Strategy, and Risk).
// Distance = pips x InpPipSizeOverride (Laravel pip_size; crypto default 1.0 = $1/pip).
// Profile: TP 200, SL 100 => ~$200 / ~$100 on BTC when pip_size = 1.0.
// Note: ticker max_tp/sl/spread overrides win on the Laravel bot when set in tickers table.

input string InpTradeLabel        = "AutoCryptoBot";

input group "Ticker pip model (BTC / crypto)"
input double InpPipSizeOverride   = 1.0;
input int    InpTpPips            = 200;
input int    InpSlPips            = 100;
input double InpMaxSpreadPips     = 50.0;
input double InpMinMovePips       = 25.0;

input group "Trade sizing"
input double InpLot               = 0.001;
input ulong  InpMagic             = 20250623;

input group "Percent sizing (off — use profile pips above)"
input bool   InpUsePercentSizing  = false;
input double InpTpPercent         = 0.0;
input double InpSlPercent         = 0.0;
input double InpTrailStartPercent = 0.0;
input double InpTrailPercent      = 0.0;
input double InpMinMovePercent    = 0.0;
input double InpMaxSpreadPercent  = 0.0;

input group "Trailing stop"
input int    InpTrailStartPips    = 50;
input int    InpTrailPips         = 50;
input double InpTrailTpMultiplier = 1.0;

input group "Entry filters"
input int    InpCooldownMinutes   = 28;
input int    InpSessionStartUtc   = 0;
input int    InpSessionEndUtc     = 23;
input int    InpMaxOpenPositions  = 1;
input int    InpMaxTradesPerDay   = 20;
input int    InpMaxTradesPerSymbolPerDay = 2;
input double InpMaxDailyLossPercent = 2.0;

input group "Prop floating loss guard"
input bool   InpEnableFloatingLossGuard = true;
input double InpFloatingLossClosePercent = 1.8;
input double InpFloatingLossHardLimitPercent = 2.0;
input double InpFloatingReferenceBalance = 0.0;
input bool   InpCloseAllIfStillBelow = true;

input group "Mode"
input bool   InpScalperMode       = false;

input group "Strategies"
input bool   InpUseSma            = true;
input bool   InpUseEma            = true;
input int    InpSmaFast           = 9;
input int    InpSmaSlow           = 21;
input int    InpSmaConfirm        = 1;
input int    InpEmaFast           = 9;
input int    InpEmaSlow           = 21;
input int    InpEmaConfirm        = 1;

input group "Trend filter"
input bool   InpTrendFilter       = true;
input ENUM_TIMEFRAMES InpTrendTf1 = PERIOD_H1;
input ENUM_TIMEFRAMES InpTrendTf2 = PERIOD_H4;
input ENUM_TIMEFRAMES InpEntryTf  = PERIOD_M15;

input group "ADX filter"
input bool   InpUseAdxFloor       = true;
input double InpAdxMinFloor       = 18.0;
input int    InpAdxPeriod         = 14;

input group "Debug & bot score"
input bool   InpDebugMode         = false;
input bool   InpUseBotScore       = true;
input int    InpMinBotScore       = 70;
input double InpScoreSignalRefPips = 120.0;
input string InpScoreCategory     = "crypto";
input bool   InpUseAdxScore       = true;
input bool   InpUseRsiScore       = true;
input int    InpRsiPeriod         = 14;

input group "Scanner"
input bool   InpTradeChartSymbol  = true;
input string InpSymbolList        = "BTCUSD";

#endif // AUTO_CRYPTO_BOT_INPUTS_MQH
