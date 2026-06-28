//+------------------------------------------------------------------+
//| AutoStockBot.mq5                                                 |
//| Stocks/indices EA — console.php category defaults (stock).       |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.03"
#property description "Stocks/indices — console.php stock category defaults"

#define AFB_PIP_IS_PRICE_POINT
#define AFB_PRESET_STOCK

#include <AutoForexBot/AutoForexBotInputs.mqh>
#include <AutoForexBot/AutoForexBotScore.mqh>
#include <AutoForexBot/AutoForexBotCore.mqh>

//+------------------------------------------------------------------+
int OnInit()
{
   return AfbOnInit();
}

void OnDeinit(const int reason)
{
   AfbOnDeinit(reason);
}

void OnTick()
{
   AfbOnTick();
}
