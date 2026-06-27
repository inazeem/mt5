//+------------------------------------------------------------------+
//| AutoCryptoBot.mq5                                                |
//| BTC / crypto EA — ticker pip model (matches Laravel tickers DB). |
//| TP/SL = pips x pip_size (BTCUSD: TP 1000, SL 500, spread 100).   |
//+------------------------------------------------------------------+
#property copyright "mt5 project"
#property version   "2.01"
#property description "BTC/crypto — matches Laravel crypto bot profile (pips x pip_size)"

#define AFB_CRYPTO_BOT

#include <AutoCryptoBot/AutoCryptoBotInputs.mqh>
#include <AutoForexBot/AutoForexBotScore.mqh>
#include <AutoForexBot/AutoForexBotCore.mqh>
