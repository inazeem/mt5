//+------------------------------------------------------------------+
//| AutoForexBot.mq5                                                 |
//| Forex EA — console.php category defaults (forex).                |
//| TP/SL/spread/trail/min-move match mt5:auto-forex forex maps.       |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.06"
#property description "Forex pairs — console.php forex category defaults"

input string InpTradeLabel        = "AutoForexBot";

//--- trade sizing (forex: tp 25 / sl 15)
input group "Trade sizing"
input double InpLot               = 0.01;
input int    InpTpPips            = 25;
input int    InpSlPips            = 15;
input double InpPipSizeOverride   = 0.0;
input ulong  InpMagic             = 20250622;

//--- percent sizing (off for forex — uses pips above)
input group "Percent sizing"
input bool   InpUsePercentSizing  = false;
input double InpTpPercent         = 0.0;
input double InpSlPercent         = 0.0;
input double InpTrailStartPercent = 0.0;
input double InpTrailPercent      = 0.0;
input double InpMinMovePercent    = 0.0;
input double InpMaxSpreadPercent  = 0.0;

//--- trailing (forex: start 10 / trail 8 / tp x2)
input group "Trailing stop"
input int    InpTrailStartPips    = 10;
input int    InpTrailPips         = 8;
input double InpTrailTpMultiplier = 2.0;

//--- entry filters (forex: spread 2.5 / min-move 3)
input group "Entry filters"
input double InpMaxSpreadPips     = 2.5;
input double InpMinMovePips       = 3.0;
input int    InpCooldownMinutes   = 30;
input int    InpSessionStartUtc   = 6;
input int    InpSessionEndUtc     = 20;
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

//--- debug & bot score (matches Laravel BotScoreCalculator)
input group "Debug & bot score"
input bool   InpDebugMode         = false;
input bool   InpUseBotScore       = true;
input int    InpMinBotScore       = 70;
input double InpScoreSignalRefPips = 10.0;
input string InpScoreCategory     = "forex";
input bool   InpUseAdxScore       = true;
input bool   InpUseRsiScore       = true;
input int    InpRsiPeriod         = 14;

//--- scan
input group "Scanner"
input bool   InpTradeChartSymbol  = true;
input string InpSymbolList        = "EURUSD,GBPUSD,USDJPY,AUDUSD";

#include <AutoForexBot/AutoForexBotCore.mqh>
