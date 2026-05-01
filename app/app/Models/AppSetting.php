<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'owner_email',
        'demo_only',
        'mt5_server',
        'mt5_port',
        'mt5_manager_login',
        'mt5_manager_password',
        'mt5_account_login',
        'mt5_action_deal',
        'mt5_volume_multiplier',
        'ai_provider',
        'claude_api_key',
        'claude_model',
        'perplexity_api_key',
        'perplexity_model',
        'metaapi_token',
        'metaapi_account_id',
        'metaapi_region',
        'bot_lot',
        'bot_tp_pips',
        'bot_sl_pips',
        'bot_trail_start_pips',
        'bot_trail_pips',
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
    ];

    protected function casts(): array
    {
        return [
            'demo_only' => 'boolean',
            'mt5_port' => 'integer',
            'mt5_action_deal' => 'integer',
            'mt5_volume_multiplier' => 'integer',
            'mt5_manager_password' => 'encrypted',
            'claude_api_key' => 'encrypted',
            'perplexity_api_key' => 'encrypted',
            'metaapi_token' => 'encrypted',
            'bot_lot' => 'float',
            'bot_tp_pips' => 'float',
            'bot_sl_pips' => 'float',
            'bot_trail_start_pips' => 'float',
            'bot_trail_pips' => 'float',
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
            'mt5_port' => 443,
            'mt5_action_deal' => 1,
            'mt5_volume_multiplier' => 10000,
            'ai_provider' => 'claude',
            'claude_model' => 'claude-3-5-sonnet-latest',
            'perplexity_model' => 'sonar-pro',
        ]);
    }
}
