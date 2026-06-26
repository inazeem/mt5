#ifndef AUTO_FOREX_BOT_CORE_MQH
#define AUTO_FOREX_BOT_CORE_MQH

// Shared EA logic — include after inputs in each category-specific .mq5 file.
// Define AFB_PIP_IS_PRICE_POINT before include for crypto/commodity/stock (console.php pip=1.0).

#include <Trade/Trade.mqh>

CTrade g_trade;
datetime g_lastCooldown[];
string   g_cooldownSymbols[];
bool     g_tpAdjusted[];
double   g_dayStartEquity = 0.0;
int      g_dayStartYmd = 0;
int      g_dailyLossLoggedYmd = 0;
datetime g_floatingGuardLastLog = 0;
string   g_entryBarSymbols[];
datetime g_entryBarTimes[];

int OnInit()
{
   g_trade.SetExpertMagicNumber(InpMagic);
   g_trade.SetDeviationInPoints(20);
   g_trade.SetTypeFillingBySymbol(_Symbol);

   Print(InpTradeLabel, " started (on-tick). magic=", InpMagic);
   if(InpDebugMode)
   {
      Print(InpTradeLabel, " DEBUG ON | minScore=", InpMinBotScore,
            "% useScore=", (InpUseBotScore ? "yes" : "no"),
            " signalRef=", DoubleToString(InpScoreSignalRefPips, 1),
            " category=", InpScoreCategory,
            " adxScore=", (InpUseAdxScore ? "yes" : "no"),
            " rsiScore=", (InpUseRsiScore ? "yes" : "no"));
   }
   return INIT_SUCCEEDED;
}

void OnDeinit(const int reason)
{
}

void OnTick()
{
   ApplyTrailingStops();
   ApplyFloatingLossGuard();
   RunEntryScan();
}

void RunEntryScan();

//+------------------------------------------------------------------+
int FindEntryBarIndex(const string symbol)
{
   for(int i = 0; i < ArraySize(g_entryBarSymbols); i++)
      if(g_entryBarSymbols[i] == symbol)
         return i;
   return -1;
}

//+------------------------------------------------------------------+
bool IsNewEntryBar(const string symbol)
{
   datetime barTime = iTime(symbol, InpEntryTf, 0);
   if(barTime == 0)
      return false;

   int idx = FindEntryBarIndex(symbol);
   if(idx < 0)
   {
      idx = ArraySize(g_entryBarSymbols);
      ArrayResize(g_entryBarSymbols, idx + 1);
      ArrayResize(g_entryBarTimes, idx + 1);
      g_entryBarSymbols[idx] = symbol;
      g_entryBarTimes[idx] = barTime;
      return true;
   }

   if(g_entryBarTimes[idx] == barTime)
      return false;

   g_entryBarTimes[idx] = barTime;
   return true;
}

//+------------------------------------------------------------------+
void BuildSymbolList(string &symbols[])
{
   ArrayResize(symbols, 0);
   if(InpTradeChartSymbol)
   {
      ArrayResize(symbols, 1);
      symbols[0] = _Symbol;
      return;
   }

   string parts[];
   int count = StringSplit(InpSymbolList, ',', parts);
   ArrayResize(symbols, count);
   for(int i = 0; i < count; i++)
   {
      StringTrimLeft(parts[i]);
      StringTrimRight(parts[i]);
      symbols[i] = parts[i];
   }
}

//+------------------------------------------------------------------+
double ReferencePrice(const string symbol)
{
   double bid = SymbolInfoDouble(symbol, SYMBOL_BID);
   double ask = SymbolInfoDouble(symbol, SYMBOL_ASK);
   if(bid > 0.0 && ask > 0.0)
      return (bid + ask) / 2.0;
   if(bid > 0.0)
      return bid;
   return ask;
}

//+------------------------------------------------------------------+
bool UsePercentSizing()
{
   return InpUsePercentSizing && InpTpPercent > 0.0 && InpSlPercent > 0.0;
}

//+------------------------------------------------------------------+
double DistanceFromPercent(const string symbol, double percent)
{
   if(percent <= 0.0)
      return 0.0;
   double price = ReferencePrice(symbol);
   if(price <= 0.0)
      return 0.0;
   return price * (percent / 100.0);
}

//+------------------------------------------------------------------+
double PipSize(const string symbol)
{
#ifdef AFB_PIP_IS_PRICE_POINT
   if(InpPipSizeOverride > 0.0)
      return InpPipSizeOverride;
   return 1.0;
#else
   if(InpPipSizeOverride > 0.0)
      return InpPipSizeOverride;

   int digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double point = SymbolInfoDouble(symbol, SYMBOL_POINT);
   if(digits == 3 || digits == 5)
      return point * 10.0;
   return point;
#endif
}

//+------------------------------------------------------------------+
double SpreadPips(const string symbol)
{
   double pip = PipSize(symbol);
   if(pip <= 0.0)
      return 9999.0;
   double ask = SymbolInfoDouble(symbol, SYMBOL_ASK);
   double bid = SymbolInfoDouble(symbol, SYMBOL_BID);
   return (ask - bid) / pip;
}

//+------------------------------------------------------------------+
bool SpreadOk(const string symbol)
{
   double ask = SymbolInfoDouble(symbol, SYMBOL_ASK);
   double bid = SymbolInfoDouble(symbol, SYMBOL_BID);
   if(ask <= 0.0 || bid <= 0.0)
      return false;

   if(UsePercentSizing() && InpMaxSpreadPercent > 0.0)
      return (ask - bid) <= DistanceFromPercent(symbol, InpMaxSpreadPercent);

   return SpreadPips(symbol) <= EffectiveMaxSpread();
}

//+------------------------------------------------------------------+
bool MinSignalMoveOk(const string symbol, double signalStrength)
{
   if(UsePercentSizing() && InpMinMovePercent > 0.0)
      return signalStrength >= DistanceFromPercent(symbol, InpMinMovePercent);
   return signalStrength >= EffectiveMinMovePips();
}

//+------------------------------------------------------------------+
bool InSessionUtc()
{
   MqlDateTime dt;
   TimeToStruct(TimeGMT(), dt);
   int hour = dt.hour;
   if(InpSessionStartUtc <= InpSessionEndUtc)
      return (hour >= InpSessionStartUtc && hour <= InpSessionEndUtc);
   return (hour >= InpSessionStartUtc || hour <= InpSessionEndUtc);
}

//+------------------------------------------------------------------+
int EffectiveTpPips()
{
   if(!InpScalperMode)
      return InpTpPips;
   if(InpTpPips <= 30)
      return MathMin(InpTpPips, 30);
   return MathMin(InpTpPips, 300);
}

int EffectiveSlPips()
{
   if(!InpScalperMode)
      return InpSlPips;
   if(InpSlPips <= 15)
      return MathMin(InpSlPips, 10);
   return MathMin(InpSlPips, 150);
}

double EffectiveMaxSpread()
{
   if(!InpScalperMode)
      return InpMaxSpreadPips;
   if(InpMaxSpreadPips <= 5.0)
      return MathMin(InpMaxSpreadPips, 5.0);
   return MathMin(InpMaxSpreadPips, 50.0);
}

double EffectiveMinMovePips()
{
   if(!InpScalperMode)
      return InpMinMovePips;
   if(InpMinMovePips <= 3.0)
      return MathMin(InpMinMovePips, 1.5);
   return MathMin(InpMinMovePips, 200.0);
}

int EffectiveTrailStartPips()
{
   if(!InpScalperMode)
      return InpTrailStartPips;
   if(InpTrailStartPips <= 15)
      return MathMin(InpTrailStartPips, 15);
   return MathMin(InpTrailStartPips, 400);
}

int EffectiveTrailPips()
{
   if(!InpScalperMode)
      return InpTrailPips;
   if(InpTrailPips <= 8)
      return MathMin(InpTrailPips, 8);
   return MathMin(InpTrailPips, 200);
}

double EffectiveTrailTpMultiplier()
{
   if(!InpScalperMode)
      return InpTrailTpMultiplier;
   return MathMin(InpTrailTpMultiplier, 10.0);
}

int EffectiveCooldownMinutes()
{
   return InpScalperMode ? MathMin(InpCooldownMinutes, 5) : InpCooldownMinutes;
}

int EffectiveMaxOpen()
{
   return MathMax(1, InpMaxOpenPositions);
}

int EffectiveMaxTradesPerDay()
{
   return MathMax(1, InpMaxTradesPerDay);
}

int EffectiveMaxTradesPerSymbolPerDay()
{
   return MathMax(1, InpMaxTradesPerSymbolPerDay);
}

//+------------------------------------------------------------------+
int CurrentDayYmd()
{
   MqlDateTime dt;
   TimeToStruct(TimeCurrent(), dt);
   return dt.year * 10000 + dt.mon * 100 + dt.day;
}

//+------------------------------------------------------------------+
void EnsureDayStartEquityBaseline()
{
   int ymd = CurrentDayYmd();
   if(ymd == g_dayStartYmd && g_dayStartEquity > 0.0)
      return;

   g_dayStartYmd = ymd;
   g_dayStartEquity = AccountInfoDouble(ACCOUNT_EQUITY);
   if(g_dayStartEquity <= 0.0)
      g_dayStartEquity = AccountInfoDouble(ACCOUNT_BALANCE);
}

//+------------------------------------------------------------------+
bool DailyLossLimitHit(double &drawdownPercent)
{
   drawdownPercent = 0.0;
   if(InpMaxDailyLossPercent <= 0.0)
      return false;

   EnsureDayStartEquityBaseline();
   if(g_dayStartEquity <= 0.0)
      return false;

   double equity = AccountInfoDouble(ACCOUNT_EQUITY);
   if(equity <= 0.0)
      equity = AccountInfoDouble(ACCOUNT_BALANCE);
   if(equity <= 0.0)
      return false;

   drawdownPercent = ((g_dayStartEquity - equity) / g_dayStartEquity) * 100.0;
   if(drawdownPercent < 0.0)
      drawdownPercent = 0.0;

   return drawdownPercent >= InpMaxDailyLossPercent;
}

//+------------------------------------------------------------------+
double FloatingReferenceBalance()
{
   if(InpFloatingReferenceBalance > 0.0)
      return InpFloatingReferenceBalance;

   double balance = AccountInfoDouble(ACCOUNT_BALANCE);
   if(balance > 0.0)
      return balance;

   return AccountInfoDouble(ACCOUNT_EQUITY);
}

//+------------------------------------------------------------------+
double PositionDealCharges(const ulong ticket)
{
   if(!PositionSelectByTicket(ticket))
      return 0.0;

   ulong position_id = (ulong)PositionGetInteger(POSITION_IDENTIFIER);
   double charges = 0.0;
   if(!HistorySelectByPosition(position_id))
      return charges;

   const int deals = HistoryDealsTotal();
   for(int d = 0; d < deals; d++)
   {
      ulong deal_ticket = HistoryDealGetTicket(d);
      if(deal_ticket == 0)
         continue;
      charges += HistoryDealGetDouble(deal_ticket, DEAL_COMMISSION);
      charges += HistoryDealGetDouble(deal_ticket, DEAL_FEE);
   }
   return charges;
}

//+------------------------------------------------------------------+
double PositionFloatingPnl(const ulong ticket)
{
   if(!PositionSelectByTicket(ticket))
      return 0.0;

   return PositionGetDouble(POSITION_PROFIT)
        + PositionGetDouble(POSITION_SWAP)
        + PositionDealCharges(ticket);
}

//+------------------------------------------------------------------+
double TotalOurFloatingProfit()
{
   double total = 0.0;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(!PositionSelectByTicket(ticket))
         continue;
      if(PositionGetInteger(POSITION_MAGIC) != (long)InpMagic)
         continue;

      total += PositionFloatingPnl(ticket);
   }
   return total;
}

//+------------------------------------------------------------------+
bool FloatingLossPercent(double &floatingPercent)
{
   floatingPercent = 0.0;
   double referenceBalance = FloatingReferenceBalance();
   if(referenceBalance <= 0.0)
      return false;

   floatingPercent = (TotalOurFloatingProfit() / referenceBalance) * 100.0;
   return true;
}

//+------------------------------------------------------------------+
bool FloatingLossBlocksEntries(double &floatingPercent)
{
   if(!InpEnableFloatingLossGuard)
      return false;
   if(!FloatingLossPercent(floatingPercent))
      return false;

   return floatingPercent <= (-1.0 * InpFloatingLossClosePercent);
}

//+------------------------------------------------------------------+
void LogFloatingGuardMessage(string prefix, double floatingPercent, string suffix, double thresholdPercent)
{
   if((TimeCurrent() - g_floatingGuardLastLog) < 300)
      return;

   g_floatingGuardLastLog = TimeCurrent();
   Print(prefix,
         DoubleToString(floatingPercent, 2),
         "%",
         suffix,
         DoubleToString(thresholdPercent, 2),
         "% (broker hard limit reference ",
         DoubleToString(InpFloatingLossHardLimitPercent, 2),
         "%).");
}

//+------------------------------------------------------------------+
int CloseLosingOurPositions()
{
   int closed = 0;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(!PositionSelectByTicket(ticket))
         continue;
      if(PositionGetInteger(POSITION_MAGIC) != (long)InpMagic)
         continue;

      double profit = PositionFloatingPnl(ticket);
      if(profit >= 0.0)
         continue;

      if(g_trade.PositionClose(ticket))
      {
         closed++;
         Print("Floating guard closed losing position #", ticket,
               " profit=", DoubleToString(profit, 2));
      }
      else
      {
         Print("Floating guard failed to close losing #", ticket,
               " ", g_trade.ResultRetcodeDescription());
      }
   }
   return closed;
}

//+------------------------------------------------------------------+
int CloseAllOurPositions()
{
   int closed = 0;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(!PositionSelectByTicket(ticket))
         continue;
      if(PositionGetInteger(POSITION_MAGIC) != (long)InpMagic)
         continue;

      if(g_trade.PositionClose(ticket))
      {
         closed++;
         Print("Floating guard closed position #", ticket);
      }
      else
      {
         Print("Floating guard failed to close #", ticket,
               " ", g_trade.ResultRetcodeDescription());
      }
   }
   return closed;
}

//+------------------------------------------------------------------+
void ApplyFloatingLossGuard()
{
   if(!InpEnableFloatingLossGuard)
      return;

   double floatingPct = 0.0;
   if(!FloatingLossPercent(floatingPct))
      return;

   if(floatingPct > (-1.0 * InpFloatingLossClosePercent))
      return;

   LogFloatingGuardMessage(
      "PROP floating guard triggered: open PnL ",
      floatingPct,
      "% <= close threshold -",
      InpFloatingLossClosePercent
   );

   int closedLosers = CloseLosingOurPositions();
   if(closedLosers > 0)
      Print("Floating guard closed ", closedLosers, " losing position(s).");

   if(!InpCloseAllIfStillBelow)
      return;

   if(!FloatingLossPercent(floatingPct))
      return;

   if(floatingPct > (-1.0 * InpFloatingLossClosePercent))
      return;

   int closedAll = CloseAllOurPositions();
   if(closedAll > 0)
   {
      Print("Floating guard still below -", DoubleToString(InpFloatingLossClosePercent, 2),
            "% after closing losers â€” closed remaining ", closedAll, " position(s).");
   }
}

//+------------------------------------------------------------------+
bool EvaluateConsensus(const string symbol, int &side, double &signalPips)
{
   bool smaOk = !InpUseSma;
   bool emaOk = !InpUseEma;
   int smaSide = 0;
   int emaSide = 0;
   double smaPips = 0.0;
   double emaPips = 0.0;

   if(InpUseSma)
      smaOk = EvaluateMaCross(symbol, InpEntryTf, true, InpSmaFast, InpSmaSlow, InpSmaConfirm, smaSide, smaPips);
   if(InpUseEma)
      emaOk = EvaluateMaCross(symbol, InpEntryTf, false, InpEmaFast, InpEmaSlow, InpEmaConfirm, emaSide, emaPips);

   if(!smaOk || !emaOk)
      return false;

   if(InpUseSma && InpUseEma && smaSide != emaSide)
      return false;

   side = InpUseSma ? smaSide : emaSide;
   if(InpUseSma && InpUseEma)
      signalPips = (smaPips + emaPips) / 2.0;
   else if(InpUseSma)
      signalPips = smaPips;
   else
      signalPips = emaPips;

   return (side == 1 || side == -1);
}

//+------------------------------------------------------------------+
bool EvaluateMaCross(const string symbol, ENUM_TIMEFRAMES tf, bool useSma,
                     int fastPeriod, int slowPeriod, int confirmCandles,
                     int &side, double &strengthPips)
{
   if(fastPeriod >= slowPeriod)
      slowPeriod = fastPeriod + 1;

   int needBars = slowPeriod + confirmCandles + 5;
   MqlRates rates[];
   ArraySetAsSeries(rates, true);
   int copied = CopyRates(symbol, tf, 0, needBars, rates);
   if(copied < needBars)
      return false;

   double closes[];
   ArrayResize(closes, copied);
   for(int i = 0; i < copied; i++)
      closes[i] = rates[i].close;

   double fastNow = useSma ? Sma(closes, fastPeriod, 0) : Ema(closes, fastPeriod, 0);
   double slowNow = useSma ? Sma(closes, slowPeriod, 0) : Ema(closes, slowPeriod, 0);
   if(fastNow == 0.0 || slowNow == 0.0 || fastNow == slowNow)
      return false;

   side = (fastNow > slowNow) ? 1 : -1;

   for(int offset = 1; offset <= confirmCandles; offset++)
   {
      double fastHist = useSma ? Sma(closes, fastPeriod, offset) : Ema(closes, fastPeriod, offset);
      double slowHist = useSma ? Sma(closes, slowPeriod, offset) : Ema(closes, slowPeriod, offset);
      if(fastHist == 0.0 || slowHist == 0.0 || fastHist == slowHist)
         return false;
      int histSide = (fastHist > slowHist) ? 1 : -1;
      if(histSide != side)
         return false;
   }

   double pip = PipSize(symbol);
   strengthPips = (pip > 0.0) ? MathAbs(fastNow - slowNow) / pip : 0.0;
   return true;
}

//+------------------------------------------------------------------+
double Sma(const double &closes[], int period, int shift)
{
   int size = ArraySize(closes);
   if(period <= 0 || shift + period > size)
      return 0.0;
   double sum = 0.0;
   for(int i = shift; i < shift + period; i++)
      sum += closes[i];
   return sum / period;
}

//+------------------------------------------------------------------+
double Ema(const double &closes[], int period, int shift)
{
   int size = ArraySize(closes);
   if(period <= 0 || shift + period + 2 > size)
      return 0.0;

   double k = 2.0 / (period + 1.0);
   double ema = Sma(closes, period, size - period);
   for(int i = size - period - 1; i >= shift; i--)
      ema = closes[i] * k + ema * (1.0 - k);
   return ema;
}

//+------------------------------------------------------------------+
int TrendSideFromLastCandle(const string symbol, ENUM_TIMEFRAMES tf)
{
   MqlRates rates[];
   ArraySetAsSeries(rates, true);
   if(CopyRates(symbol, tf, 1, 1, rates) < 1)
      return 0;
   if(rates[0].close > rates[0].open)
      return 1;
   if(rates[0].close < rates[0].open)
      return -1;
   return 0;
}

//+------------------------------------------------------------------+
bool TrendAligned(const string symbol, int side)
{
   int t1 = TrendSideFromLastCandle(symbol, InpTrendTf1);
   int t2 = TrendSideFromLastCandle(symbol, InpTrendTf2);
   int entry = TrendSideFromLastCandle(symbol, InpEntryTf);

   if(t1 != side || t2 != side)
      return false;
   if(entry != side)
      return false;
   return true;
}

//+------------------------------------------------------------------+
bool AdxOk(const string symbol)
{
   int handle = iADX(symbol, InpEntryTf, InpAdxPeriod);
   if(handle == INVALID_HANDLE)
      return true;

   double adx[];
   ArraySetAsSeries(adx, true);
   if(CopyBuffer(handle, 0, 1, 1, adx) < 1)
   {
      IndicatorRelease(handle);
      return true;
   }
   IndicatorRelease(handle);
   return adx[0] >= InpAdxMinFloor;
}

//+------------------------------------------------------------------+
bool HasOurPosition(const string symbol)
{
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(!PositionSelectByTicket(ticket))
         continue;
      if(PositionGetInteger(POSITION_MAGIC) != (long)InpMagic)
         continue;
      if(PositionGetString(POSITION_SYMBOL) == symbol)
         return true;
   }
   return false;
}

//+------------------------------------------------------------------+
int CountOurPositions()
{
   int count = 0;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(!PositionSelectByTicket(ticket))
         continue;
      if(PositionGetInteger(POSITION_MAGIC) == (long)InpMagic)
         count++;
   }
   return count;
}

//+------------------------------------------------------------------+
int TradesOpenedToday()
{
   datetime dayStart = StringToTime(TimeToString(TimeCurrent(), TIME_DATE));
   if(!HistorySelect(dayStart, TimeCurrent()))
      return 0;

   int count = 0;
   for(int i = HistoryDealsTotal() - 1; i >= 0; i--)
   {
      ulong deal = HistoryDealGetTicket(i);
      if(HistoryDealGetInteger(deal, DEAL_MAGIC) != (long)InpMagic)
         continue;
      if(HistoryDealGetInteger(deal, DEAL_ENTRY) != DEAL_ENTRY_IN)
         continue;
      count++;
   }
   return count;
}

//+------------------------------------------------------------------+
int TradesOpenedTodayForSymbol(const string symbol)
{
   datetime dayStart = StringToTime(TimeToString(TimeCurrent(), TIME_DATE));
   if(!HistorySelect(dayStart, TimeCurrent()))
      return 0;

   int count = 0;
   for(int i = HistoryDealsTotal() - 1; i >= 0; i--)
   {
      ulong deal = HistoryDealGetTicket(i);
      if(HistoryDealGetInteger(deal, DEAL_MAGIC) != (long)InpMagic)
         continue;
      if(HistoryDealGetInteger(deal, DEAL_ENTRY) != DEAL_ENTRY_IN)
         continue;
      if(HistoryDealGetString(deal, DEAL_SYMBOL) != symbol)
         continue;
      count++;
   }
   return count;
}

//+------------------------------------------------------------------+
int FindCooldownIndex(const string symbol)
{
   for(int i = 0; i < ArraySize(g_cooldownSymbols); i++)
      if(g_cooldownSymbols[i] == symbol)
         return i;
   return -1;
}

//+------------------------------------------------------------------+
bool InCooldown(const string symbol)
{
   int idx = FindCooldownIndex(symbol);
   if(idx < 0)
      return false;
   int mins = EffectiveCooldownMinutes();
   if(mins <= 0)
      return false;
   return (TimeCurrent() - g_lastCooldown[idx]) < (mins * 60);
}

//+------------------------------------------------------------------+
void SetCooldown(const string symbol)
{
   int idx = FindCooldownIndex(symbol);
   if(idx < 0)
   {
      idx = ArraySize(g_cooldownSymbols);
      ArrayResize(g_cooldownSymbols, idx + 1);
      ArrayResize(g_lastCooldown, idx + 1);
      g_cooldownSymbols[idx] = symbol;
   }
   g_lastCooldown[idx] = TimeCurrent();
}

//+------------------------------------------------------------------+
void OpenTrade(const string symbol, int side)
{
   double pip = PipSize(symbol);
   double tpDist = 0.0;
   double slDist = 0.0;

   if(UsePercentSizing())
   {
      tpDist = DistanceFromPercent(symbol, InpTpPercent);
      slDist = DistanceFromPercent(symbol, InpSlPercent);
   }
   else
   {
      tpDist = EffectiveTpPips() * pip;
      slDist = EffectiveSlPips() * pip;
   }

   double ask = SymbolInfoDouble(symbol, SYMBOL_ASK);
   double bid = SymbolInfoDouble(symbol, SYMBOL_BID);
   double price = (side > 0) ? ask : bid;

   double sl = (side > 0) ? price - slDist : price + slDist;
   double tp = (side > 0) ? price + tpDist : price - tpDist;

   int digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   sl = NormalizeDouble(sl, digits);
   tp = NormalizeDouble(tp, digits);

   bool ok = false;
   string comment = InpTradeLabel + (side > 0 ? " buy" : " sell");
   if(side > 0)
      ok = g_trade.Buy(InpLot, symbol, price, sl, tp, comment);
   else
      ok = g_trade.Sell(InpLot, symbol, price, sl, tp, comment);

   if(ok)
   {
      SetCooldown(symbol);
      if(UsePercentSizing())
         Print("Opened ", (side > 0 ? "BUY" : "SELL"), " ", symbol,
               " TP=", tp, " (", DoubleToString(InpTpPercent, 3), "%)",
               " SL=", sl, " (", DoubleToString(InpSlPercent, 3), "%)");
      else
         Print("Opened ", (side > 0 ? "BUY" : "SELL"), " ", symbol, " TP=", tp, " SL=", sl);
   }
   else
      Print("Open failed ", symbol, " err=", GetLastError(), " ret=", g_trade.ResultRetcodeDescription());
}

//+------------------------------------------------------------------+
void ApplyTrailingStops()
{
   double tpMult = EffectiveTrailTpMultiplier();

   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(!PositionSelectByTicket(ticket))
         continue;
      if(PositionGetInteger(POSITION_MAGIC) != (long)InpMagic)
         continue;

      string symbol = PositionGetString(POSITION_SYMBOL);
      double pip = PipSize(symbol);
      int digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
      long type = PositionGetInteger(POSITION_TYPE);
      double openPrice = PositionGetDouble(POSITION_PRICE_OPEN);
      double currentSl = PositionGetDouble(POSITION_SL);
      double currentTp = PositionGetDouble(POSITION_TP);
      double bid = SymbolInfoDouble(symbol, SYMBOL_BID);
      double ask = SymbolInfoDouble(symbol, SYMBOL_ASK);
      double price = (type == POSITION_TYPE_BUY) ? bid : ask;

      double trailStartDist = 0.0;
      double trailDist = 0.0;
      if(UsePercentSizing() && InpTrailStartPercent > 0.0 && InpTrailPercent > 0.0)
      {
         trailStartDist = DistanceFromPercent(symbol, InpTrailStartPercent);
         trailDist = DistanceFromPercent(symbol, InpTrailPercent);
      }
      else
      {
         trailStartDist = EffectiveTrailStartPips() * pip;
         trailDist = EffectiveTrailPips() * pip;
      }

      double profitDist = (type == POSITION_TYPE_BUY)
         ? (price - openPrice)
         : (openPrice - price);

      if(profitDist < trailStartDist)
         continue;

      double newSl = (type == POSITION_TYPE_BUY)
         ? price - trailDist
         : price + trailDist;
      newSl = NormalizeDouble(newSl, digits);

      if(type == POSITION_TYPE_BUY && currentSl > 0.0 && newSl <= currentSl)
         continue;
      if(type == POSITION_TYPE_SELL && currentSl > 0.0 && newSl >= currentSl)
         continue;

      double newTp = currentTp;
      string tpKey = IntegerToString((long)ticket);
      bool tpAdjusted = StringFind(JoinedTpKeys(), tpKey) >= 0;

      if(!tpAdjusted && tpMult > 1.0 && currentTp > 0.0)
      {
         double tpDistance = (type == POSITION_TYPE_BUY)
            ? (currentTp - openPrice)
            : (openPrice - currentTp);
         if(tpDistance > 0.0)
         {
            newTp = (type == POSITION_TYPE_BUY)
               ? openPrice + tpDistance * tpMult
               : openPrice - tpDistance * tpMult;
            newTp = NormalizeDouble(newTp, digits);
            MarkTpAdjusted(ticket);
         }
      }

      if(!g_trade.PositionModify(ticket, newSl, newTp))
         Print("Trail modify failed #", ticket, " ", g_trade.ResultRetcodeDescription());
   }
}

// crude TP-adjusted tracker (per session)
string g_tpAdjustedKeys = "";

string JoinedTpKeys()
{
   return g_tpAdjustedKeys;
}

void MarkTpAdjusted(ulong ticket)
{
   string key = IntegerToString((long)ticket);
   if(StringFind(g_tpAdjustedKeys, key) < 0)
      g_tpAdjustedKeys = g_tpAdjustedKeys + key + ",";
}

//+------------------------------------------------------------------+
void RunEntryScan()
{
   if(!InSessionUtc())
   {
      DebugOnce("session", "Outside UTC session window - entry scan skipped");
      return;
   }

   if(CountOurPositions() >= EffectiveMaxOpen())
   {
      DebugOnce("max_open", "Max open positions reached - entry scan skipped");
      return;
   }

   if(TradesOpenedToday() >= EffectiveMaxTradesPerDay())
   {
      DebugOnce("max_trades_day", "Max trades per day reached - entry scan skipped");
      return;
   }

   double dailyDrawdownPct = 0.0;
   if(DailyLossLimitHit(dailyDrawdownPct))
   {
      if(g_dailyLossLoggedYmd != CurrentDayYmd())
      {
         g_dailyLossLoggedYmd = CurrentDayYmd();
         Print("Daily loss guard: drawdown ", DoubleToString(dailyDrawdownPct, 2),
               "% >= limit ", DoubleToString(InpMaxDailyLossPercent, 2), "% - new entries blocked.");
      }
      DebugOnce("daily_loss", "Daily loss guard active - entry scan skipped");
      return;
   }

   double floatingPct = 0.0;
   if(FloatingLossBlocksEntries(floatingPct))
   {
      LogFloatingGuardMessage(
         "Floating loss guard: open PnL ",
         floatingPct,
         " - new entries blocked until floating recovers above -",
         InpFloatingLossClosePercent
      );
      DebugOnce("floating_loss", "Floating loss guard active - entry scan skipped");
      return;
   }

   string symbols[];
   BuildSymbolList(symbols);

   for(int i = 0; i < ArraySize(symbols); i++)
   {
      string sym = symbols[i];
      if(sym == "")
         continue;

      if(!IsNewEntryBar(sym))
         continue;

      DebugPrint(sym + " new " + EnumToString(InpEntryTf) + " bar - evaluating signal");

      if(HasOurPosition(sym))
      {
         DebugSkip(sym, "has_position");
         continue;
      }

      if(InCooldown(sym))
      {
         DebugSkip(sym, "cooldown");
         continue;
      }

      if(TradesOpenedTodayForSymbol(sym) >= EffectiveMaxTradesPerSymbolPerDay())
      {
         DebugSkip(sym, "max_trades_symbol_day");
         continue;
      }

      if(!SpreadOk(sym))
      {
         DebugSkip(sym, "spread", "spread=" + DoubleToString(SpreadPips(sym), 2)
            + " max=" + DoubleToString(MaxSpreadReferencePips(sym), 2));
         continue;
      }

      int side = 0;
      double signalPips = 0.0;
      if(!EvaluateConsensus(sym, side, signalPips))
      {
         DebugSkip(sym, "no_consensus", "SMA/EMA do not agree or no cross");
         continue;
      }

      string sideStr = (side > 0) ? "BUY" : "SELL";
      DebugPrint(sym + " consensus " + sideStr + " strength=" + DoubleToString(signalPips, 2));

      if(!MinSignalMoveOk(sym, signalPips))
      {
         DebugSkip(sym, "min_move", "strength=" + DoubleToString(signalPips, 2));
         continue;
      }

      if(InpTrendFilter && !TrendAligned(sym, side))
      {
         DebugSkip(sym, "trend_filter", sideStr + " not aligned on HTF/entry candles");
         continue;
      }

      if(InpUseAdxFloor && !AdxOk(sym))
      {
         DebugSkip(sym, "adx_floor", "ADX < " + DoubleToString(InpAdxMinFloor, 1));
         continue;
      }

      if(InpUseBotScore)
      {
         BotScoreResult scoreResult = CalculateBotScore(sym, side, signalPips);
         DebugPrint(sym + " " + FormatBotScore(scoreResult));

         if(scoreResult.hard_reject)
         {
            DebugSkip(sym, scoreResult.reject_reason, FormatBotScore(scoreResult));
            continue;
         }
         if(scoreResult.score < InpMinBotScore)
         {
            DebugSkip(sym, "low_score", FormatBotScore(scoreResult)
               + " min=" + IntegerToString(InpMinBotScore));
            continue;
         }

         DebugPrint(sym + " PASS " + sideStr + " " + FormatBotScore(scoreResult));
      }
      else if(InpDebugMode)
      {
         DebugPrint(sym + " PASS " + sideStr + " (bot score disabled)");
      }

      OpenTrade(sym, side);
   }
}

#endif // AUTO_FOREX_BOT_CORE_MQH
