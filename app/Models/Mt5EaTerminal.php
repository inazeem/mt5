<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Mt5EaTerminal extends Model
{
    protected $fillable = [
        'instance_key',
        'display_name',
        'enabled',
        'is_demo',
        'api_token',
        'api_token_hash',
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
        'market_quotes',
        'market_candles',
        'last_seen_at',
    ];

    protected $hidden = [
        'api_token',
        'api_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_demo' => 'boolean',
            'api_token' => 'encrypted',
            'account_login' => 'integer',
            'balance' => 'float',
            'equity' => 'float',
            'margin' => 'float',
            'free_margin' => 'float',
            'trade_allowed' => 'boolean',
            'positions' => 'array',
            'market_quotes' => 'array',
            'market_candles' => 'array',
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

    public function isBound(): bool
    {
        return $this->account_login !== null && $this->account_login > 0;
    }

    public function environmentLabel(): string
    {
        return $this->is_demo ? 'Demo' : 'Live';
    }

    public function label(): string
    {
        if ($this->display_name) {
            return $this->display_name;
        }

        if ($this->instance_key) {
            return $this->instance_key;
        }

        return $this->isBound() ? (string) $this->account_login : 'Unnamed instance';
    }

    public static function slugifyInstanceKey(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $value)));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'terminal';
    }

    public static function makeUniqueInstanceKey(string $base): string
    {
        $candidate = self::slugifyInstanceKey($base);
        $suffix = 1;

        while (self::query()->where('instance_key', $candidate)->exists()) {
            $candidate = self::slugifyInstanceKey($base).'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
