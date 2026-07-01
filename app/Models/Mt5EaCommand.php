<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mt5EaCommand extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const ALLOWED_ACTIONS = ['BUY', 'SELL', 'CLOSE', 'CLOSE_ALL', 'MODIFY'];

    protected $fillable = [
        'mt5_ea_terminal_id',
        'account_login',
        'mt5_instance_key',
        'bot_trade_log_id',
        'bot_key',
        'action',
        'symbol',
        'lot',
        'sl',
        'tp',
        'ticket',
        'status',
        'error_message',
        'result_payload',
        'queued_at',
        'sent_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'account_login' => 'integer',
            'lot' => 'float',
            'sl' => 'float',
            'tp' => 'float',
            'ticket' => 'integer',
            'result_payload' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Mt5EaTerminal::class, 'mt5_ea_terminal_id');
    }

    public function toEaPayload(): array
    {
        return array_filter([
            'id' => $this->id,
            'action' => strtoupper($this->action),
            'symbol' => $this->symbol ? strtoupper($this->symbol) : null,
            'lot' => $this->lot,
            'sl' => $this->sl,
            'tp' => $this->tp,
            'ticket' => $this->ticket,
        ], static fn ($value) => $value !== null);
    }
}
