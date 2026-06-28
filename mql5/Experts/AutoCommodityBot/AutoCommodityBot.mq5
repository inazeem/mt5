//+------------------------------------------------------------------+
//| AutoCommodityBot.mq5                                             |
//| Commodities EA — console.php category defaults (commodity).      |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.03"
#property description "Commodities — console.php commodity category defaults"

#define AFB_PIP_IS_PRICE_POINT
#define AFB_PRESET_COMMODITY

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
