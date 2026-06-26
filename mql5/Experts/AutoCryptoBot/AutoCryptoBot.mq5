//+------------------------------------------------------------------+
//| AutoCryptoBot.mq5                                                |
//| Crypto EA — percent-based TP/SL scales with each coin's price.   |
//| Same % on BTC, ETH, alts = similar relative risk per trade.      |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.02"
#property description "Crypto — percent TP/SL (scales BTC vs ETH automatically)"

#define AFB_PIP_IS_PRICE_POINT

input string InpTradeLabel        = "AutoCryptoBot";

//--- trade sizing (fallback fixed $ distance when percent mode off)
input group "Trade sizing"
input double InpLot               = 0.01;
input int    InpTpPips            = 150;
input int    InpSlPips            = 100;
input double InpPipSizeOverride   = 0.0;
input ulong  InpMagic             = 20250623;

//--- percent sizing (recommended for crypto — ON by default)
input group "Percent sizing"
input bool   InpUsePercentSizing  = true;
input double InpTpPercent         = 0.15;
input double InpSlPercent         = 0.10;
input double InpTrailStartPercent = 0.01;
input double InpTrailPercent      = 0.008;
input double InpMinMovePercent    = 0.003;
input double InpMaxSpreadPercent  = 0.05;

//--- trailing (used only when percent mode off)
input group "Trailing stop"
input int    InpTrailStartPips    = 10;
input int    InpTrailPips         = 8;
input double InpTrailTpMultiplier = 2.0;

//--- entry filters (pips used only when percent mode off)
input group "Entry filters"
input double InpMaxSpreadPips     = 50.0;
input double InpMinMovePips       = 3.0;
input int    InpCooldownMinutes   = 30;
input int    InpSessionStartUtc   = 0;
input int    InpSessionEndUtc     = 23;
input int    InpMaxOpenPositions  = 3;
input int    InpMaxTradesPerDay   = 20;
input int    InpMaxTradesPerSymbolPerDay = 2;
input double InpMaxDailyLossPercent = 2.0;

//--- prop firm floating loss guard
input group "Prop floating loss guard"
input bool   InpEnableFloatingLossGuard = true;
input double InpFloatingLossClosePercent = 1.8;
input double InpFloatingLossHardLimitPercent = 2.0;
input double InpFloatingReferenceBalance = 0.0;
input bool   InpCloseAllIfStillBelow = true;

//--- mode
input group "Mode"
input bool   InpScalperMode       = false;

//--- strategies
input group "Strategies"
input bool   InpUseSma            = true;
input bool   InpUseEma            = true;
input int    InpSmaFast           = 9;
input int    InpSmaSlow           = 21;
input int    InpSmaConfirm        = 1;
input int    InpEmaFast           = 9;
input int    InpEmaSlow           = 21;
input int    InpEmaConfirm        = 1;

//--- trend filter
input group "Trend filter"
input bool   InpTrendFilter       = true;
input ENUM_TIMEFRAMES InpTrendTf1 = PERIOD_H1;
input ENUM_TIMEFRAMES InpTrendTf2 = PERIOD_H4;
input ENUM_TIMEFRAMES InpEntryTf  = PERIOD_M15;

//--- ADX filter
input group "ADX filter"
input bool   InpUseAdxFloor       = true;
input double InpAdxMinFloor       = 22.0;
input int    InpAdxPeriod         = 14;

//--- debug & bot score
input group "Debug & bot score"
input bool   InpDebugMode         = false;
input bool   InpUseBotScore       = true;
input int    InpMinBotScore       = 70;
input double InpScoreSignalRefPips = 120.0;
input string InpScoreCategory     = "crypto";
input bool   InpUseAdxScore       = true;
input bool   InpUseRsiScore       = true;
input int    InpRsiPeriod         = 14;

//--- scan
input group "Scanner"
input bool   InpTradeChartSymbol  = true;
input string InpSymbolList        = "BTCUSD,ETHUSD";

#include <AutoForexBot/AutoForexBotCore.mqh>
