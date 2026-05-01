<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticker extends Model
{
    protected $fillable = [
        'symbol',
        'description',
        'category',
        'is_active',
        'pip_size',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pip_size'  => 'float',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
