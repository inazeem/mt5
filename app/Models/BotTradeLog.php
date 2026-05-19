<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotTradeLog extends Model
{
    protected $fillable = [
        'bot_key',
        'bot_name',
        'event_type',
        'status',
        'symbol',
        'side',
        'lot_size',
        'entry_price',
        'take_profit',
        'stop_loss',
        'spread_pips',
        'signal_delta_pips',
        'ai_provider',
        'ai_decision',
        'ai_confidence',
        'ai_summary',
        'message',
        'error_message',
        'meta_payload',
        'meta_response',
    ];

    protected function casts(): array
    {
        return [
            'bot_key' => 'string',
            'bot_name' => 'string',
            'lot_size' => 'float',
            'entry_price' => 'float',
            'take_profit' => 'float',
            'stop_loss' => 'float',
            'spread_pips' => 'float',
            'signal_delta_pips' => 'float',
            'ai_confidence' => 'integer',
            'meta_payload' => 'array',
            'meta_response' => 'array',
        ];
    }
}
