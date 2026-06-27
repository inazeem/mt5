#ifndef AUTO_FOREX_BOT_INPUTS_MQH
#define AUTO_FOREX_BOT_INPUTS_MQH

// Define ONE preset in each .mq5 before including this file:
// AFB_PRESET_FOREX | AFB_PRESET_CRYPTO | AFB_PRESET_COMMODITY | AFB_PRESET_STOCK

#ifdef AFB_PRESET_CRYPTO
   #define AFB_DEF_LABEL           "AutoCryptoBot"
   #define AFB_DEF_MAGIC           20250623
   #define AFB_DEF_TP_PIPS         150
   #define AFB_DEF_SL_PIPS         100
   #define AFB_DEF_USE_PERCENT     1
   #define AFB_DEF_TP_PCT          0.15
   #define AFB_DEF_SL_PCT          0.10
   #define AFB_DEF_TRAIL_START_PCT 0.01
   #define AFB_DEF_TRAIL_PCT       0.008
   #define AFB_DEF_MIN_MOVE_PCT    0.003
   #define AFB_DEF_MAX_SPREAD_PCT  0.05
   #define AFB_DEF_TRAIL_START     10
   #define AFB_DEF_TRAIL_PIPS      8
   #define AFB_DEF_TRAIL_TP_MULT   2.0
   #define AFB_DEF_MAX_SPREAD      50.0
   #define AFB_DEF_MIN_MOVE        3.0
   #define AFB_DEF_SESSION_START   0
   #define AFB_DEF_SESSION_END     23
   #define AFB_DEF_SCORE_REF       120.0
   #define AFB_DEF_SCORE_CATEGORY  "crypto"
   #define AFB_DEF_SYMBOL_LIST     "BTCUSD,ETHUSD"
#else
#ifdef AFB_PRESET_COMMODITY
   #define AFB_DEF_LABEL           "AutoCommodityBot"
   #define AFB_DEF_MAGIC           20250624
   #define AFB_DEF_TP_PIPS         80
   #define AFB_DEF_SL_PIPS         40
   #define AFB_DEF_USE_PERCENT     0
   #define AFB_DEF_TP_PCT          0.0
   #define AFB_DEF_SL_PCT          0.0
   #define AFB_DEF_TRAIL_START_PCT 0.0
   #define AFB_DEF_TRAIL_PCT       0.0
   #define AFB_DEF_MIN_MOVE_PCT    0.0
   #define AFB_DEF_MAX_SPREAD_PCT  0.0
   #define AFB_DEF_TRAIL_START     30
   #define AFB_DEF_TRAIL_PIPS      15
   #define AFB_DEF_TRAIL_TP_MULT   2.5
   #define AFB_DEF_MAX_SPREAD      15.0
   #define AFB_DEF_MIN_MOVE        12.0
   #define AFB_DEF_SESSION_START   6
   #define AFB_DEF_SESSION_END     20
   #define AFB_DEF_SCORE_REF       50.0
   #define AFB_DEF_SCORE_CATEGORY  "commodity"
   #define AFB_DEF_SYMBOL_LIST     "XAUUSD,XAGUSD,WTI,BRENT"
#else
#ifdef AFB_PRESET_STOCK
   #define AFB_DEF_LABEL           "AutoStockBot"
   #define AFB_DEF_MAGIC           20250625
   #define AFB_DEF_TP_PIPS         160
   #define AFB_DEF_SL_PIPS         80
   #define AFB_DEF_USE_PERCENT     0
   #define AFB_DEF_TP_PCT          0.0
   #define AFB_DEF_SL_PCT          0.0
   #define AFB_DEF_TRAIL_START_PCT 0.0
   #define AFB_DEF_TRAIL_PCT       0.0
   #define AFB_DEF_MIN_MOVE_PCT    0.0
   #define AFB_DEF_MAX_SPREAD_PCT  0.0
   #define AFB_DEF_TRAIL_START     50
   #define AFB_DEF_TRAIL_PIPS      25
   #define AFB_DEF_TRAIL_TP_MULT   3.0
   #define AFB_DEF_MAX_SPREAD      40.0
   #define AFB_DEF_MIN_MOVE        25.0
   #define AFB_DEF_SESSION_START   14
   #define AFB_DEF_SESSION_END     21
   #define AFB_DEF_SCORE_REF       30.0
   #define AFB_DEF_SCORE_CATEGORY  "stock"
   #define AFB_DEF_SYMBOL_LIST     "US30,US500,NAS100,SPX500"
#else
   #define AFB_DEF_LABEL           "AutoForexBot"
   #define AFB_DEF_MAGIC           20250622
   #define AFB_DEF_TP_PIPS         25
   #define AFB_DEF_SL_PIPS         15
   #define AFB_DEF_USE_PERCENT     0
   #define AFB_DEF_TP_PCT          0.0
   #define AFB_DEF_SL_PCT          0.0
   #define AFB_DEF_TRAIL_START_PCT 0.0
   #define AFB_DEF_TRAIL_PCT       0.0
   #define AFB_DEF_MIN_MOVE_PCT    0.0
   #define AFB_DEF_MAX_SPREAD_PCT  0.0
   #define AFB_DEF_TRAIL_START     10
   #define AFB_DEF_TRAIL_PIPS      8
   #define AFB_DEF_TRAIL_TP_MULT   2.0
   #define AFB_DEF_MAX_SPREAD      2.5
   #define AFB_DEF_MIN_MOVE        3.0
   #define AFB_DEF_SESSION_START   6
   #define AFB_DEF_SESSION_END     20
   #define AFB_DEF_SCORE_REF       10.0
   #define AFB_DEF_SCORE_CATEGORY  "forex"
   #define AFB_DEF_SYMBOL_LIST     "EURUSD,GBPUSD,USDJPY,AUDUSD"
#endif
#endif
#endif

input string InpTradeLabel        = AFB_DEF_LABEL;

input group "Trade sizing"
input double InpLot               = 0.01;
input int    InpTpPips            = AFB_DEF_TP_PIPS;
input int    InpSlPips            = AFB_DEF_SL_PIPS;
input double InpPipSizeOverride   = 0.0;
input ulong  InpMagic             = AFB_DEF_MAGIC;

input group "Percent sizing"
input bool   InpUsePercentSizing  = (bool)AFB_DEF_USE_PERCENT;
input double InpTpPercent         = AFB_DEF_TP_PCT;
input double InpSlPercent         = AFB_DEF_SL_PCT;
input double InpTrailStartPercent = AFB_DEF_TRAIL_START_PCT;
input double InpTrailPercent      = AFB_DEF_TRAIL_PCT;
input double InpMinMovePercent    = AFB_DEF_MIN_MOVE_PCT;
input double InpMaxSpreadPercent  = AFB_DEF_MAX_SPREAD_PCT;

input group "Trailing stop"
input int    InpTrailStartPips    = AFB_DEF_TRAIL_START;
input int    InpTrailPips         = AFB_DEF_TRAIL_PIPS;
input double InpTrailTpMultiplier = AFB_DEF_TRAIL_TP_MULT;

input group "Entry filters"
input double InpMaxSpreadPips     = AFB_DEF_MAX_SPREAD;
input double InpMinMovePips       = AFB_DEF_MIN_MOVE;
input int    InpCooldownMinutes   = 30;
input int    InpSessionStartUtc   = AFB_DEF_SESSION_START;
input int    InpSessionEndUtc     = AFB_DEF_SESSION_END;
input int    InpMaxOpenPositions  = 3;
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
input double InpAdxMinFloor       = 22.0;
input int    InpAdxPeriod         = 14;

input group "Pullback filter"
input bool   InpUsePullbackFilter    = true;
input double InpPullbackRetraceAtrMult = 0.5;
input double InpPullbackMaxExtAtrMult  = 1.2;
input double InpPullbackRsiBuyMax      = 58.0;
input double InpPullbackRsiSellMin     = 42.0;
input int    InpPullbackLookbackBars   = 5;

input group "Debug & bot score"
input bool   InpDebugMode         = false;
input bool   InpUseBotScore       = true;
input int    InpMinBotScore       = 70;
input double InpScoreSignalRefPips = AFB_DEF_SCORE_REF;
input string InpScoreCategory     = AFB_DEF_SCORE_CATEGORY;
input bool   InpUseAdxScore       = true;
input bool   InpUseRsiScore       = true;
input int    InpRsiPeriod         = 14;

input group "Scanner"
input bool   InpTradeChartSymbol  = true;
input string InpSymbolList        = AFB_DEF_SYMBOL_LIST;

#endif // AUTO_FOREX_BOT_INPUTS_MQH
