#ifndef AUTO_FOREX_BOT_ASSET_MQH
#define AUTO_FOREX_BOT_ASSET_MQH

// Per-asset presets (matches console.php / Pine strategy). Included from AutoForexBotInputs.mqh.

//+------------------------------------------------------------------+
struct AfbAssetProfile
{
   string category;
   int    tp_pips;
   int    sl_pips;
   double max_spread;
   double min_move;
   int    trail_start;
   int    trail_pips;
   double trail_tp_mult;
   int    session_start;
   int    session_end;
   int    cooldown_minutes;
   double adx_floor;
   double score_ref;
   bool   use_price_point_pip;
};

//+------------------------------------------------------------------+
string AfbUpperSymbol(const string symbol)
{
   string u = symbol;
   StringToUpper(u);
   return u;
}

//+------------------------------------------------------------------+
string AfbCategoryFromEnum(const ENUM_AFB_ASSET asset)
{
   switch(asset)
   {
      case AFB_ASSET_FOREX:     return "forex";
      case AFB_ASSET_CRYPTO:    return "crypto";
      case AFB_ASSET_COMMODITY: return "commodity";
      case AFB_ASSET_STOCK:
      case AFB_ASSET_INDEX:     return "stock";
      default:                  return "forex";
   }
}

//+------------------------------------------------------------------+
string AfbDetectCategory(const string symbol)
{
   string u = AfbUpperSymbol(symbol);

   if(StringFind(u, "BTC") >= 0 || StringFind(u, "ETH") >= 0 ||
      StringFind(u, "LTC") >= 0 || StringFind(u, "XRP") >= 0 ||
      StringFind(u, "SOL") >= 0 || StringFind(u, "DOGE") >= 0 ||
      StringFind(u, "CRYPTO") >= 0)
      return "crypto";

   if(StringFind(u, "XAU") >= 0 || StringFind(u, "GOLD") >= 0 ||
      StringFind(u, "XAG") >= 0 || StringFind(u, "SILVER") >= 0 ||
      StringFind(u, "WTI") >= 0 || StringFind(u, "BRENT") >= 0 ||
      StringFind(u, "OIL") >= 0)
      return "commodity";

   if(StringFind(u, "US30") >= 0 || StringFind(u, "US500") >= 0 ||
      StringFind(u, "NAS") >= 0 || StringFind(u, "SPX") >= 0 ||
      StringFind(u, "DAX") >= 0 || StringFind(u, "UK100") >= 0 ||
      StringFind(u, "DE30") >= 0 || StringFind(u, "JP225") >= 0 ||
      StringFind(u, "NDX") >= 0 || StringFind(u, "DJI") >= 0)
      return "stock";

   return "forex";
}

//+------------------------------------------------------------------+
string AfbResolveCategory(const string symbol)
{
   if(InpTradeChartSymbol && symbol == _Symbol && InpChartAssetCategory != AFB_ASSET_AUTO)
      return AfbCategoryFromEnum(InpChartAssetCategory);

   if(InpUsePerSymbolCategory || InpTradeChartSymbol)
      return AfbDetectCategory(symbol);

   if(InpChartAssetCategory != AFB_ASSET_AUTO)
      return AfbCategoryFromEnum(InpChartAssetCategory);

   return AfbDetectCategory(symbol);
}

//+------------------------------------------------------------------+
AfbAssetProfile AfbProfileForCategory(const string category)
{
   AfbAssetProfile p;
   p.category = category;
   p.use_price_point_pip = false;
   p.tp_pips = InpTpPips;
   p.sl_pips = InpSlPips;
   p.max_spread = InpMaxSpreadPips;
   p.min_move = InpMinMovePips;
   p.trail_start = InpTrailStartPips;
   p.trail_pips = InpTrailPips;
   p.trail_tp_mult = InpTrailTpMultiplier;
   p.session_start = InpSessionStartUtc;
   p.session_end = InpSessionEndUtc;
   p.cooldown_minutes = InpCooldownMinutes;
   p.adx_floor = InpAdxMinFloor;
   p.score_ref = InpScoreSignalRefPips;

   if(category == "crypto")
   {
      p.use_price_point_pip = true;
      p.tp_pips = 200;
      p.sl_pips = 100;
      p.max_spread = 50.0;
      p.min_move = 25.0;
      p.trail_start = 50;
      p.trail_pips = 50;
      p.trail_tp_mult = 1.0;
      p.session_start = 0;
      p.session_end = 23;
      p.cooldown_minutes = 28;
      p.adx_floor = 18.0;
      p.score_ref = 120.0;
      return p;
   }

   if(category == "commodity")
   {
      p.use_price_point_pip = true;
      p.tp_pips = 80;
      p.sl_pips = 40;
      p.max_spread = 15.0;
      p.min_move = 12.0;
      p.trail_start = 30;
      p.trail_pips = 15;
      p.trail_tp_mult = 2.5;
      p.session_start = 6;
      p.session_end = 20;
      p.cooldown_minutes = 30;
      p.adx_floor = 22.0;
      p.score_ref = 50.0;
      return p;
   }

   if(category == "stock")
   {
      p.use_price_point_pip = true;
      p.tp_pips = 160;
      p.sl_pips = 80;
      p.max_spread = 40.0;
      p.min_move = 25.0;
      p.trail_start = 50;
      p.trail_pips = 25;
      p.trail_tp_mult = 3.0;
      p.session_start = 14;
      p.session_end = 21;
      p.cooldown_minutes = 30;
      p.adx_floor = 22.0;
      p.score_ref = 30.0;
      return p;
   }

   // forex (default)
   p.tp_pips = 25;
   p.sl_pips = 15;
   p.max_spread = 2.5;
   p.min_move = 3.0;
   p.trail_start = 10;
   p.trail_pips = 8;
   p.trail_tp_mult = 2.0;
   p.session_start = 6;
   p.session_end = 20;
   p.cooldown_minutes = 30;
   p.adx_floor = 22.0;
   p.score_ref = 10.0;
   return p;
}

//+------------------------------------------------------------------+
AfbAssetProfile AfbResolveProfile(const string symbol)
{
   if(!InpUseCategoryRiskDefaults)
   {
      AfbAssetProfile p;
      p.category = InpScoreCategory;
      p.tp_pips = InpTpPips;
      p.sl_pips = InpSlPips;
      p.max_spread = InpMaxSpreadPips;
      p.min_move = InpMinMovePips;
      p.trail_start = InpTrailStartPips;
      p.trail_pips = InpTrailPips;
      p.trail_tp_mult = InpTrailTpMultiplier;
      p.session_start = InpSessionStartUtc;
      p.session_end = InpSessionEndUtc;
      p.cooldown_minutes = InpCooldownMinutes;
      p.adx_floor = InpAdxMinFloor;
      p.score_ref = InpScoreSignalRefPips;
#ifdef AFB_CRYPTO_BOT
      p.use_price_point_pip = true;
#elif defined AFB_PIP_IS_PRICE_POINT
      p.use_price_point_pip = true;
#else
      p.use_price_point_pip = false;
#endif
      return p;
   }

   string category = AfbResolveCategory(symbol);
   return AfbProfileForCategory(category);
}

#endif // AUTO_FOREX_BOT_ASSET_MQH
