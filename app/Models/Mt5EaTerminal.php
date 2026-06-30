<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mt5EaTerminal extends Model
{
    protected $fillable = [
        'account_login',
        'server',
        'terminal_name',
        'broker_company',
        'balance',
        'equity',
        'margin',
        'free_margin',
        'currency',
        'trade_allowed',
        'positions',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'account_login' => 'integer',
            'balance' => 'float',
            'equity' => 'float',
            'margin' => 'float',
            'free_margin' => 'float',
            'trade_allowed' => 'boolean',
            'positions' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(Mt5EaCommand::class);
    }

    public function isOnline(int $seconds = 10): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->greaterThan(now()->subSeconds($seconds));
    }
}
