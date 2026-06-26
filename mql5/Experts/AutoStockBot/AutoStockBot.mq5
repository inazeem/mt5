//+------------------------------------------------------------------+
//| AutoStockBot.mq5                                                 |
//| Stocks/indices EA — console.php category defaults (stock).       |
//| Indices — pip = 1 price point (Mt5Service non-FX rule).          |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.00"
#property description "Stocks/indices — console.php stock category defaults"

#define AFB_PIP_IS_PRICE_POINT

input string InpTradeLabel        = "AutoStockBot";

//--- trade sizing (stock: tp 160 / sl 80)
input group "Trade sizing"
input double InpLot               = 0.01;
input int    InpTpPips            = 160;
input int    InpSlPips            = 80;
input double InpPipSizeOverride   = 0.0;
input ulong  InpMagic             = 20250625;

//--- percent sizing (off for stocks — uses pips above)
input group "Percent sizing"
input bool   InpUsePercentSizing  = false;
input double InpTpPercent         = 0.0;
input double InpSlPercent         = 0.0;
input double InpTrailStartPercent = 0.0;
input double InpTrailPercent      = 0.0;
input double InpMinMovePercent    = 0.0;
input double InpMaxSpreadPercent  = 0.0;

//--- trailing (stock: start 50 / trail 25 / tp x3)
input group "Trailing stop"
input int    InpTrailStartPips    = 50;
input int    InpTrailPips         = 25;
input double InpTrailTpMultiplier = 3.0;

//--- entry filters (stock: spread 40 / min-move 25)
input group "Entry filters"
input double InpMaxSpreadPips     = 40.0;
input double InpMinMovePips       = 25.0;
input int    InpCooldownMinutes   = 30;
input int    InpSessionStartUtc   = 14;
input int    InpSessionEndUtc     = 21;
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
input double InpScoreSignalRefPips = 30.0;
input string InpScoreCategory     = "stock";
input bool   InpUseAdxScore       = true;
input bool   InpUseRsiScore       = true;
input int    InpRsiPeriod         = 14;

//--- scan
input group "Scanner"
input bool   InpTradeChartSymbol  = true;
input string InpSymbolList        = "US30,US500,NAS100,SPX500";

#include <AutoForexBot/AutoForexBotCore.mqh>
