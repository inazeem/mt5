//+------------------------------------------------------------------+
//| LaravelBridge.mq5                                                |
//| Polls Laravel API, reports account data, executes remote trades. |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.00"
#property description "Laravel EA bridge — polls /api/ea/poll and executes queued commands"

#include <Trade/Trade.mqh>

input string InpServerUrl     = "https://mt5.test";   // Laravel base URL (no trailing slash)
input string InpApiToken      = "";                   // Bearer token from EA Bridge page
input int    InpPollSeconds   = 1;                    // Poll interval (seconds)
input int    InpMagic         = 88001;                // Magic number for bridge orders
input bool   InpDebug         = false;                // Verbose logs

CTrade g_trade;
int    g_lastCommandId = 0;
bool   g_lastCommandOk = false;
string g_lastCommandMessage = "";
ulong  g_lastCommandTicket = 0;

//+------------------------------------------------------------------+
int OnInit()
{
   if(StringLen(InpApiToken) < 16)
   {
      Print("LaravelBridge: set InpApiToken from the Laravel EA Bridge page.");
      return INIT_PARAMETERS_INCORRECT;
   }

   g_trade.SetExpertMagicNumber(InpMagic);
   g_trade.SetDeviationInPoints(20);
   g_trade.SetTypeFillingBySymbol(_Symbol);

   EventSetTimer(MathMax(1, InpPollSeconds));
   PollServer();

   Print("LaravelBridge started. polling=", InpPollSeconds, "s url=", InpServerUrl);
   return INIT_SUCCEEDED;
}

void OnDeinit(const int reason)
{
   EventKillTimer();
}

void OnTimer()
{
   PollServer();
}

void OnTick()
{
}

//+------------------------------------------------------------------+
string JsonEscape(const string value)
{
   string out = value;
   StringReplace(out, "\\", "\\\\");
   StringReplace(out, "\"", "\\\"");
   StringReplace(out, "\r", "\\r");
   StringReplace(out, "\n", "\\n");
   return out;
}

//+------------------------------------------------------------------+
double PipSize(const string symbol)
{
   int digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double point = SymbolInfoDouble(symbol, SYMBOL_POINT);
   if(point <= 0.0)
      return 0.0001;
   if(digits == 3 || digits == 5)
      return point * 10.0;
   return point;
}

//+------------------------------------------------------------------+
double NormalizeVolume(const string symbol, double lot)
{
   double minLot = SymbolInfoDouble(symbol, SYMBOL_VOLUME_MIN);
   double maxLot = SymbolInfoDouble(symbol, SYMBOL_VOLUME_MAX);
   double step   = SymbolInfoDouble(symbol, SYMBOL_VOLUME_STEP);
   if(step <= 0.0)
      step = 0.01;

   lot = MathMax(minLot, MathMin(maxLot, lot));
   lot = MathFloor(lot / step + 0.0000001) * step;
   return lot;
}

//+------------------------------------------------------------------+
string BuildPositionsJson()
{
   string json = "[";
   bool first = true;

   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0 || !PositionSelectByTicket(ticket))
         continue;

      if(!first)
         json += ",";
      first = false;

      string symbol = PositionGetString(POSITION_SYMBOL);
      long type = PositionGetInteger(POSITION_TYPE);
      double volume = PositionGetDouble(POSITION_VOLUME);
      double priceOpen = PositionGetDouble(POSITION_PRICE_OPEN);
      double sl = PositionGetDouble(POSITION_SL);
      double tp = PositionGetDouble(POSITION_TP);
      double profit = PositionGetDouble(POSITION_PROFIT);

      json += "{";
      json += "\"ticket\":" + IntegerToString((int)ticket) + ",";
      json += "\"symbol\":\"" + JsonEscape(symbol) + "\",";
      json += "\"type\":\"" + (type == POSITION_TYPE_BUY ? "BUY" : "SELL") + "\",";
      json += "\"lot\":" + DoubleToString(volume, 2) + ",";
      json += "\"price_open\":" + DoubleToString(priceOpen, (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS)) + ",";
      json += "\"sl\":" + DoubleToString(sl, (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS)) + ",";
      json += "\"tp\":" + DoubleToString(tp, (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS)) + ",";
      json += "\"profit\":" + DoubleToString(profit, 2);
      json += "}";
   }

   json += "]";
   return json;
}

//+------------------------------------------------------------------+
string BuildPollPayload()
{
   string server = AccountInfoString(ACCOUNT_SERVER);
   string company = AccountInfoString(ACCOUNT_COMPANY);
   string terminal = TerminalInfoString(TERMINAL_NAME);
   long login = AccountInfoInteger(ACCOUNT_LOGIN);

   string json = "{";
   json += "\"login\":" + IntegerToString((int)login) + ",";
   json += "\"server\":\"" + JsonEscape(server) + "\",";
   json += "\"terminal_name\":\"" + JsonEscape(terminal) + "\",";
   json += "\"broker_company\":\"" + JsonEscape(company) + "\",";
   json += "\"balance\":" + DoubleToString(AccountInfoDouble(ACCOUNT_BALANCE), 2) + ",";
   json += "\"equity\":" + DoubleToString(AccountInfoDouble(ACCOUNT_EQUITY), 2) + ",";
   json += "\"margin\":" + DoubleToString(AccountInfoDouble(ACCOUNT_MARGIN), 2) + ",";
   json += "\"free_margin\":" + DoubleToString(AccountInfoDouble(ACCOUNT_MARGIN_FREE), 2) + ",";
   json += "\"currency\":\"" + JsonEscape(AccountInfoString(ACCOUNT_CURRENCY)) + "\",";
   json += "\"trade_allowed\":" + (AccountInfoInteger(ACCOUNT_TRADE_ALLOWED) ? "true" : "false") + ",";
   json += "\"positions\":" + BuildPositionsJson();

   if(g_lastCommandId > 0)
   {
      json += ",\"command_result\":{";
      json += "\"id\":" + IntegerToString(g_lastCommandId) + ",";
      json += "\"ok\":" + (g_lastCommandOk ? "true" : "false") + ",";
      json += "\"message\":\"" + JsonEscape(g_lastCommandMessage) + "\"";
      if(g_lastCommandTicket > 0)
         json += ",\"ticket\":" + IntegerToString((int)g_lastCommandTicket);
      json += "}";
   }

   json += "}";
   return json;
}

//+------------------------------------------------------------------+
bool JsonExtractString(const string json, const string key, string &out)
{
   string needle = "\"" + key + "\":\"";
   int start = StringFind(json, needle);
   if(start < 0)
      return false;

   start += StringLen(needle);
   int end = StringFind(json, "\"", start);
   if(end < 0)
      return false;

   out = StringSubstr(json, start, end - start);
   return true;
}

//+------------------------------------------------------------------+
bool JsonExtractNumber(const string json, const string key, double &out)
{
   string needle = "\"" + key + "\":";
   int start = StringFind(json, needle);
   if(start < 0)
      return false;

   start += StringLen(needle);
   int end = start;
   int len = StringLen(json);
   while(end < len)
   {
      ushort ch = StringGetCharacter(json, end);
      if((ch >= '0' && ch <= '9') || ch == '.' || ch == '-')
      {
         end++;
         continue;
      }
      break;
   }

   if(end <= start)
      return false;

   out = StringToDouble(StringSubstr(json, start, end - start));
   return true;
}

//+------------------------------------------------------------------+
bool JsonExtractInt(const string json, const string key, int &out)
{
   double value = 0.0;
   if(!JsonExtractNumber(json, key, value))
      return false;
   out = (int)value;
   return true;
}

//+------------------------------------------------------------------+
bool ParseCommandBlock(const string json, int &id, string &action, string &symbol, double &lot, double &sl, double &tp, int &ticket)
{
   int cmdPos = StringFind(json, "\"command\":");
   if(cmdPos < 0)
      return false;

   int nullPos = StringFind(json, "\"command\":null", cmdPos);
   if(nullPos >= 0 && nullPos == cmdPos)
      return false;

   int blockStart = StringFind(json, "{", cmdPos);
   int blockEnd = StringFind(json, "}", blockStart);
   if(blockStart < 0 || blockEnd < 0)
      return false;

   string block = StringSubstr(json, blockStart, blockEnd - blockStart + 1);

   if(!JsonExtractInt(block, "id", id))
      return false;

   JsonExtractString(block, "action", action);
   JsonExtractString(block, "symbol", symbol);
   JsonExtractNumber(block, "lot", lot);
   JsonExtractNumber(block, "sl", sl);
   JsonExtractNumber(block, "tp", tp);
   JsonExtractInt(block, "ticket", ticket);

   StringToUpper(action);
   StringTrimLeft(symbol);
   StringTrimRight(symbol);

   return action != "";
}

//+------------------------------------------------------------------+
bool ExecuteCommand(const int id, const string action, const string symbol, const double lot, const double slPips, const double tpPips, const int ticket)
{
   g_lastCommandId = id;
   g_lastCommandOk = false;
   g_lastCommandMessage = "";
   g_lastCommandTicket = 0;

   if(!TerminalInfoInteger(TERMINAL_TRADE_ALLOWED))
   {
      g_lastCommandMessage = "Terminal trading not allowed";
      return false;
   }

   if(!AccountInfoInteger(ACCOUNT_TRADE_ALLOWED))
   {
      g_lastCommandMessage = "Account trading not allowed";
      return false;
   }

   if(action == "CLOSE_ALL")
   {
      bool any = false;
      for(int i = PositionsTotal() - 1; i >= 0; i--)
      {
         ulong posTicket = PositionGetTicket(i);
         if(posTicket == 0)
            continue;
         if(g_trade.PositionClose(posTicket))
            any = true;
      }
      g_lastCommandOk = any || PositionsTotal() == 0;
      g_lastCommandMessage = g_lastCommandOk ? "Positions closed" : g_trade.ResultRetcodeDescription();
      return g_lastCommandOk;
   }

   if(action == "CLOSE")
   {
      if(ticket <= 0)
      {
         g_lastCommandMessage = "ticket required for CLOSE";
         return false;
      }
      g_lastCommandOk = g_trade.PositionClose((ulong)ticket);
      g_lastCommandMessage = g_lastCommandOk ? "Position closed" : g_trade.ResultRetcodeDescription();
      if(g_lastCommandOk)
         g_lastCommandTicket = (ulong)ticket;
      return g_lastCommandOk;
   }

   if(symbol == "")
   {
      g_lastCommandMessage = "symbol required";
      return false;
   }

   if(!SymbolSelect(symbol, true))
   {
      g_lastCommandMessage = "Symbol not available: " + symbol;
      return false;
   }

   double volume = NormalizeVolume(symbol, lot > 0.0 ? lot : 0.01);
   double pip = PipSize(symbol);
   int digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double bid = SymbolInfoDouble(symbol, SYMBOL_BID);
   double ask = SymbolInfoDouble(symbol, SYMBOL_ASK);
   double sl = 0.0;
   double tp = 0.0;
   bool ok = false;

   if(action == "BUY")
   {
      if(slPips > 0.0)
         sl = NormalizeDouble(ask - slPips * pip, digits);
      if(tpPips > 0.0)
         tp = NormalizeDouble(ask + tpPips * pip, digits);
      ok = g_trade.Buy(volume, symbol, ask, sl, tp, "LaravelBridge #" + IntegerToString(id));
   }
   else if(action == "SELL")
   {
      if(slPips > 0.0)
         sl = NormalizeDouble(bid + slPips * pip, digits);
      if(tpPips > 0.0)
         tp = NormalizeDouble(bid - tpPips * pip, digits);
      ok = g_trade.Sell(volume, symbol, bid, sl, tp, "LaravelBridge #" + IntegerToString(id));
   }
   else
   {
      g_lastCommandMessage = "Unsupported action: " + action;
      return false;
   }

   g_lastCommandOk = ok;
   g_lastCommandMessage = ok ? "Order placed" : g_trade.ResultRetcodeDescription();
   if(ok)
      g_lastCommandTicket = g_trade.ResultOrder();
   return ok;
}

//+------------------------------------------------------------------+
void PollServer()
{
   string url = InpServerUrl;
   if(StringLen(url) > 0 && StringGetCharacter(url, StringLen(url) - 1) == '/')
      url = StringSubstr(url, 0, StringLen(url) - 1);
   url += "/api/ea/poll";

   string body = BuildPollPayload();
   char post[];
   char result[];
   string resultHeaders;
   StringToCharArray(body, post, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(post, StringLen(body));

   string headers = "Content-Type: application/json\r\n";
   headers += "Accept: application/json\r\n";
   headers += "Authorization: Bearer " + InpApiToken + "\r\n";

   ResetLastError();
   int status = WebRequest("POST", url, headers, 10000, post, result, resultHeaders);
   if(status == -1)
   {
      int err = GetLastError();
      if(InpDebug)
         Print("LaravelBridge WebRequest failed err=", err, " — allow URL in MT5 options");
      return;
   }

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(InpDebug)
      Print("LaravelBridge response (", status, "): ", response);

   if(status < 200 || status >= 300)
      return;

   int commandId = 0;
   string action = "";
   string symbol = "";
   double lot = 0.0;
   double sl = 0.0;
   double tp = 0.0;
   int ticket = 0;

   if(!ParseCommandBlock(response, commandId, action, symbol, lot, sl, tp, ticket))
      return;

   if(InpDebug)
      Print("LaravelBridge executing #", commandId, " ", action, " ", symbol, " lot=", lot);

   ExecuteCommand(commandId, action, symbol, lot, sl, tp, ticket);
}

//+------------------------------------------------------------------+
