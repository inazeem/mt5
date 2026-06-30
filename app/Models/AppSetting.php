<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'owner_email',
        'demo_only',
        'mt5_volume_multiplier',
        'ai_provider',
        'claude_api_key',
        'claude_model',
        'perplexity_api_key',
        'perplexity_model',
        'metaapi_token',
        'metaapi_account_id',
        'metaapi_region',
        'ea_bridge_token',
        'alpaca_api_key_id',
        'alpaca_api_secret',
        'alpaca_paper',
        'bot_lot',
        'bot_tp_pips',
        'bot_sl_pips',
        'bot_trail_start_pips',
        'bot_trail_pips',
        'bot_trail_tp_multiplier',
        'bot_min_move_pips',
        'bot_max_spread_pips',
        'bot_cooldown_minutes',
        'bot_session_start_utc',
        'bot_session_end_utc',
        'bot_max_trades_per_day',
        'bot_max_daily_loss_percent',
        'bot_ai_confirm',
        'bot_max_symbols',
        'bot_ai_min_confidence',
        'bot_strategies',
        'bot_strategy',
        'bot_strategy_params',
        'bot_signal_timeframes',
        'bot_entry_timeframe',
        'bot_profiles',
    ];

    protected function casts(): array
    {
        return [
            'demo_only' => 'boolean',
            'mt5_volume_multiplier' => 'integer',
            'claude_api_key' => 'encrypted',
            'perplexity_api_key' => 'encrypted',
            'metaapi_token' => 'encrypted',
            'ea_bridge_token' => 'encrypted',
            'alpaca_api_secret' => 'encrypted',
            'alpaca_paper' => 'boolean',
            'bot_lot' => 'float',
            'bot_tp_pips' => 'float',
            'bot_sl_pips' => 'float',
            'bot_trail_start_pips' => 'float',
            'bot_trail_pips' => 'float',
            'bot_trail_tp_multiplier' => 'float',
            'bot_min_move_pips' => 'float',
            'bot_max_spread_pips' => 'float',
            'bot_cooldown_minutes' => 'integer',
            'bot_session_start_utc' => 'integer',
            'bot_session_end_utc' => 'integer',
            'bot_max_trades_per_day' => 'integer',
            'bot_max_daily_loss_percent' => 'float',
            'bot_ai_confirm' => 'boolean',
            'bot_max_symbols' => 'integer',
            'bot_ai_min_confidence' => 'integer',
            'bot_strategies' => 'array',
            'bot_strategy_params' => 'array',
            'bot_signal_timeframes' => 'array',
            'bot_entry_timeframe' => 'string',
            'bot_profiles' => 'array',
        ];
    }

    public static function singleton(): self
    {
        $setting = static::query()->first();

        if ($setting) {
            if ((string) env('APP_OWNER_EMAIL', '') !== '' && empty($setting->owner_email)) {
                $setting->owner_email = (string) env('APP_OWNER_EMAIL');
                $setting->save();
            }

            return $setting;
        }

        return static::query()->create([
            'owner_email' => (string) env('APP_OWNER_EMAIL', ''),
            'demo_only' => true,
            'mt5_volume_multiplier' => 1,
            'bot_trail_tp_multiplier' => 2,
            'ai_provider' => 'claude',
            'claude_model' => 'claude-3-5-sonnet-latest',
            'perplexity_model' => 'sonar-pro',
        ]);
    }
}
