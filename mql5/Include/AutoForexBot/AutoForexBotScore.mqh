#ifndef AUTO_FOREX_BOT_SCORE_MQH
#define AUTO_FOREX_BOT_SCORE_MQH

// Bot score + debug helpers (port of App\Services\BotScoreCalculator).

datetime g_debugLastGlobalLog = 0;
string   g_debugLastGlobalKey = "";

//+------------------------------------------------------------------+
void DebugPrint(const string message)
{
   if(!InpDebugMode)
      return;
   Print("[", InpTradeLabel, "] ", message);
}

//+------------------------------------------------------------------+
void DebugOnce(const string key, const string message)
{
   if(!InpDebugMode)
      return;
   if(g_debugLastGlobalKey == key && (TimeCurrent() - g_debugLastGlobalLog) < 300)
      return;
   g_debugLastGlobalKey = key;
   g_debugLastGlobalLog = TimeCurrent();
   Print("[", InpTradeLabel, "] ", message);
}

//+------------------------------------------------------------------+
void DebugSkip(const string symbol, const string reason, const string detail = "")
{
   if(!InpDebugMode)
      return;
   if(detail != "")
      Print("[", InpTradeLabel, "] ", symbol, " SKIP: ", reason, " | ", detail);
   else
      Print("[", InpTradeLabel, "] ", symbol, " SKIP: ", reason);
}

//+------------------------------------------------------------------+
double ReadRsi(const string symbol, ENUM_TIMEFRAMES tf)
{
   int handle = iRSI(symbol, tf, InpRsiPeriod, PRICE_CLOSE);
   if(handle == INVALID_HANDLE)
      return -1.0;

   double buf[];
   ArraySetAsSeries(buf, true);
   if(CopyBuffer(handle, 0, 1, 1, buf) < 1)
   {
      IndicatorRelease(handle);
      return -1.0;
   }
   IndicatorRelease(handle);
   return buf[0];
}

//+------------------------------------------------------------------+
double ReadAdx(const string symbol)
{
   int handle = iADX(symbol, InpEntryTf, InpAdxPeriod);
   if(handle == INVALID_HANDLE)
      return -1.0;

   double buf[];
   ArraySetAsSeries(buf, true);
   if(CopyBuffer(handle, 0, 1, 1, buf) < 1)
   {
      IndicatorRelease(handle);
      return -1.0;
   }
   IndicatorRelease(handle);
   return buf[0];
}

//+------------------------------------------------------------------+
double AdxStrongThreshold()
{
   if(InpScoreCategory == "crypto")
      return 40.0;
   if(InpScoreCategory == "stock")
      return 32.0;
   if(InpScoreCategory == "commodity")
      return 32.0;
   if(InpScoreCategory == "other")
      return 30.0;
   return 35.0;
}

//+------------------------------------------------------------------+
double AdxStrengthScore(double adx)
{
   double weak = InpAdxMinFloor;
   double strong = AdxStrongThreshold();
   if(strong <= weak)
      return 0.0;
   return MathMax(0.0, MathMin(100.0, ((adx - weak) / (strong - weak)) * 100.0));
}

//+------------------------------------------------------------------+
double RsiBuyHtfScore(double rsi)
{
   if(rsi < 40.0)  return MathMax(0.0, (rsi / 40.0) * 40.0);
   if(rsi < 50.0)  return 40.0 + ((rsi - 40.0) / 10.0) * 30.0;
   if(rsi <= 68.0) return 70.0 + ((rsi - 50.0) / 18.0) * 30.0;
   return 65.0;
}

double RsiSellHtfScore(double rsi)
{
   if(rsi > 60.0)  return MathMax(0.0, ((100.0 - rsi) / 40.0) * 40.0);
   if(rsi > 50.0)  return 40.0 + ((60.0 - rsi) / 10.0) * 30.0;
   if(rsi >= 32.0) return 70.0 + ((50.0 - rsi) / 18.0) * 30.0;
   return 65.0;
}

double RsiBuyEntryScore(double rsi, double overbought, double oversold)
{
   if(rsi >= overbought) return 25.0;
   if(rsi <= oversold)   return 55.0;
   if(rsi >= 50.0)       return 70.0 + MathMin(30.0, ((rsi - 50.0) / MathMax(1.0, overbought - 50.0)) * 30.0);
   return 55.0 + ((rsi - oversold) / MathMax(1.0, 50.0 - oversold)) * 15.0;
}

double RsiSellEntryScore(double rsi, double overbought, double oversold)
{
   if(rsi <= oversold)   return 25.0;
   if(rsi >= overbought) return 55.0;
   if(rsi <= 50.0)       return 70.0 + MathMin(30.0, ((50.0 - rsi) / MathMax(1.0, 50.0 - oversold)) * 30.0);
   return 55.0 + ((overbought - rsi) / MathMax(1.0, overbought - 50.0)) * 15.0;
}

//+------------------------------------------------------------------+
double RsiTrendAlignmentScore(int side, double rsiHtf, double rsiEntry)
{
   bool isCrypto = (InpScoreCategory == "crypto");
   double overbought = isCrypto ? 78.0 : 72.0;
   double oversold   = isCrypto ? 22.0 : 28.0;
   double total = 0.0;
   int count = 0;

   if(rsiHtf >= 0.0)
   {
      total += (side > 0) ? RsiBuyHtfScore(rsiHtf) : RsiSellHtfScore(rsiHtf);
      count++;
   }
   if(rsiEntry >= 0.0)
   {
      total += (side > 0)
         ? RsiBuyEntryScore(rsiEntry, overbought, oversold)
         : RsiSellEntryScore(rsiEntry, overbought, oversold);
      count++;
   }
   if(count == 0)
      return 50.0;
   return total / count;
}

//+------------------------------------------------------------------+
void ScoreWeights(bool hasAdx, bool hasRsi,
                  double &wSignal, double &wSpread, double &wAdx, double &wRsi)
{
   if(hasAdx && hasRsi)      { wSignal = 0.45; wSpread = 0.25; wAdx = 0.15; wRsi = 0.15; return; }
   if(hasAdx)                { wSignal = 0.55; wSpread = 0.25; wAdx = 0.20; wRsi = 0.0;  return; }
   if(hasRsi)                { wSignal = 0.55; wSpread = 0.25; wAdx = 0.0;  wRsi = 0.20; return; }
   wSignal = 0.70; wSpread = 0.30; wAdx = 0.0; wRsi = 0.0;
}

//+------------------------------------------------------------------+
double MaxSpreadReferencePips(const string symbol)
{
   if(UsePercentSizing() && InpMaxSpreadPercent > 0.0)
   {
      double pip = PipSize(symbol);
      if(pip <= 0.0)
         return InpMaxSpreadPercent;
      return DistanceFromPercent(symbol, InpMaxSpreadPercent) / pip;
   }
   return EffectiveMaxSpread();
}

//+------------------------------------------------------------------+
double SlReferencePips(const string symbol)
{
   if(UsePercentSizing() && InpSlPercent > 0.0)
   {
      double pip = PipSize(symbol);
      if(pip <= 0.0)
         return InpSlPercent;
      return DistanceFromPercent(symbol, InpSlPercent) / pip;
   }
   return EffectiveSlPips();
}

//+------------------------------------------------------------------+
struct BotScoreResult
{
   int    score;
   bool   hard_reject;
   string reject_reason;
   double signal_score;
   double spread_score;
   double adx_score;
   double rsi_score;
   double adx;
   double rsi_htf;
   double rsi_entry;
};

//+------------------------------------------------------------------+
BotScoreResult CalculateBotScore(const string symbol, int side, double signalStrength)
{
   BotScoreResult r;
   r.score = 0;
   r.hard_reject = false;
   r.reject_reason = "";
   r.signal_score = 0.0;
   r.spread_score = 0.0;
   r.adx_score = -1.0;
   r.rsi_score = -1.0;
   r.adx = -1.0;
   r.rsi_htf = -1.0;
   r.rsi_entry = -1.0;

   double signalRef = MathMax(0.1, InpScoreSignalRefPips);
   r.signal_score = MathMin(100.0, (MathAbs(signalStrength) / signalRef) * 100.0);

   double spreadPips = SpreadPips(symbol);
   double maxSpread = MathMax(0.1, MaxSpreadReferencePips(symbol));
   r.spread_score = MathMax(0.0, MathMin(100.0, (1.0 - (spreadPips / maxSpread)) * 100.0));

   double slPips = SlReferencePips(symbol);
   if(slPips > 0.0 && spreadPips > (slPips * 0.25))
      r.spread_score *= 0.5;

   bool hasAdx = false;
   bool hasRsi = false;
   double wSignal = 0.0, wSpread = 0.0, wAdx = 0.0, wRsi = 0.0;

   if(InpUseAdxScore)
   {
      r.adx = ReadAdx(symbol);
      if(r.adx >= 0.0)
      {
         hasAdx = true;
         if(r.adx < InpAdxMinFloor)
         {
            r.hard_reject = true;
            r.reject_reason = "adx_below_floor";
         }
         r.adx_score = AdxStrengthScore(r.adx);
      }
   }

   if(InpUseRsiScore)
   {
      r.rsi_htf = ReadRsi(symbol, InpTrendTf1);
      r.rsi_entry = ReadRsi(symbol, InpEntryTf);
      if(r.rsi_htf >= 0.0 || r.rsi_entry >= 0.0)
      {
         hasRsi = true;
         r.rsi_score = RsiTrendAlignmentScore(side, r.rsi_htf, r.rsi_entry);
      }
   }

   ScoreWeights(hasAdx, hasRsi, wSignal, wSpread, wAdx, wRsi);
   double total = (r.signal_score * wSignal) + (r.spread_score * wSpread);
   if(hasAdx)
      total += r.adx_score * wAdx;
   if(hasRsi)
      total += r.rsi_score * wRsi;

   r.score = (int)MathRound(MathMax(0.0, MathMin(100.0, total)));
   return r;
}

//+------------------------------------------------------------------+
string FormatBotScore(const BotScoreResult &r)
{
   string msg = "SCORE=" + IntegerToString(r.score)
      + "% sig=" + DoubleToString(r.signal_score, 1)
      + " spr=" + DoubleToString(r.spread_score, 1);
   if(r.adx_score >= 0.0)
      msg += " adx=" + DoubleToString(r.adx_score, 1) + " (" + DoubleToString(r.adx, 1) + ")";
   if(r.rsi_score >= 0.0)
      msg += " rsi=" + DoubleToString(r.rsi_score, 1);
   return msg;
}

#endif // AUTO_FOREX_BOT_SCORE_MQH
