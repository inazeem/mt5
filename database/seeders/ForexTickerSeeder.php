<?php

namespace Database\Seeders;

use App\Models\Ticker;
use Illuminate\Database\Seeder;

class ForexTickerSeeder extends Seeder
{
    public function run(): void
    {
        $tickers = [
            // ── Majors ────────────────────────────────────────────────────────────
            ['symbol' => 'EURUSD', 'description' => 'Euro / US Dollar',                  'category' => 'Major'],
            ['symbol' => 'GBPUSD', 'description' => 'British Pound / US Dollar',         'category' => 'Major'],
            ['symbol' => 'USDJPY', 'description' => 'US Dollar / Japanese Yen',          'category' => 'Major'],
            ['symbol' => 'USDCHF', 'description' => 'US Dollar / Swiss Franc',           'category' => 'Major'],
            ['symbol' => 'USDCAD', 'description' => 'US Dollar / Canadian Dollar',       'category' => 'Major'],
            ['symbol' => 'AUDUSD', 'description' => 'Australian Dollar / US Dollar',     'category' => 'Major'],
            ['symbol' => 'NZDUSD', 'description' => 'New Zealand Dollar / US Dollar',    'category' => 'Major'],

            // ── US Stocks ─────────────────────────────────────────────────────────
            ['symbol' => 'AAPL',  'description' => 'Apple Inc.',             'category' => 'Stock', 'pip_size' => 0.1],
            ['symbol' => 'TSLA',  'description' => 'Tesla Inc.',              'category' => 'Stock', 'pip_size' => 0.1],
            ['symbol' => 'MSFT',  'description' => 'Microsoft Corporation',   'category' => 'Stock', 'pip_size' => 0.1],
            ['symbol' => 'GOOGL', 'description' => 'Alphabet Inc. (Google)',  'category' => 'Stock', 'pip_size' => 0.1],
            ['symbol' => 'NFLX',  'description' => 'Netflix Inc.',            'category' => 'Stock', 'pip_size' => 0.1],
            ['symbol' => 'COIN',  'description' => 'Coinbase Global Inc.',    'category' => 'Stock', 'pip_size' => 0.1],
            ['symbol' => 'META',  'description' => 'Meta Platforms Inc.',     'category' => 'Stock', 'pip_size' => 0.1],
        ];

        foreach ($tickers as $data) {
            Ticker::updateOrCreate(
                ['symbol' => $data['symbol']],
                array_merge(['is_active' => true, 'pip_size' => null], $data)
            );
        }

        $this->command->info('Seeded '.count($tickers).' forex tickers.');
    }
}
