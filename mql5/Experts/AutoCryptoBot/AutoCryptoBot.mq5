//+------------------------------------------------------------------+
//| AutoCryptoBot.mq5                                                |
//| BTC / crypto EA — ticker pip model (matches Laravel crypto profile). |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "2.02"
#property description "BTC/crypto — pips x pip_size (default pip 1.0 = $1 on BTC)"

#define AFB_CRYPTO_BOT

#include <AutoCryptoBot/AutoCryptoBotInputs.mqh>
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
