//+------------------------------------------------------------------+
//| AutoCryptoBot.mq5                                                |
//| Crypto/BTC EA — console.php category defaults (crypto).            |
//| Pip size = 1 price point (matches Mt5Service::resolvePipSize).   |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.00"
#property description "Crypto/BTC — console.php crypto category defaults"

#define AFB_PIP_IS_PRICE_POINT

input string InpTradeLabel        = "AutoCryptoBot";

//--- trade sizing (crypto: tp 150 / sl 100)
input group "Trade sizing"
input double InpLot               = 0.01;
input int    InpTpPips            = 150;
input int    InpSlPips            = 100;
input double InpPipSizeOverride   = 0.0;
input ulong  InpMagic             = 20250623;

//--- trailing (crypto uses console default trail map: 10 / 8 / x2)
input group "Trailing stop"
input int    InpTrailStartPips    = 10;
input int    InpTrailPips         = 8;
input double InpTrailTpMultiplier = 2.0;

//--- entry filters (crypto: spread 50 / min-move 3)
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

//--- scan
input group "Scanner"
input bool   InpTradeChartSymbol  = true;
input string InpSymbolList        = "BTCUSD,ETHUSD";

#include <AutoForexBot/AutoForexBotCore.mqh>
