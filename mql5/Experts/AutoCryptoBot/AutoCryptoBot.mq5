//+------------------------------------------------------------------+
//| AutoCryptoBot.mq5                                                |
//| Crypto EA — percent-based TP/SL scales with each coin's price.   |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "1.04"
#property description "Crypto — percent TP/SL (scales BTC vs ETH automatically)"

#define AFB_PIP_IS_PRICE_POINT
#define AFB_PRESET_CRYPTO

#include <AutoForexBot/AutoForexBotInputs.mqh>
#include <AutoForexBot/AutoForexBotScore.mqh>
#include <AutoForexBot/AutoForexBotCore.mqh>
