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
            ['symbol' => 'EURUSD', 'description' => 'Euro / US Dollar',           'category' => 'Major'],
            ['symbol' => 'GBPUSD', 'description' => 'British Pound / US Dollar',  'category' => 'Major'],
            ['symbol' => 'USDJPY', 'description' => 'US Dollar / Japanese Yen',   'category' => 'Major'],
            ['symbol' => 'USDCHF', 'description' => 'US Dollar / Swiss Franc',    'category' => 'Major'],
            ['symbol' => 'USDCAD', 'description' => 'US Dollar / Canadian Dollar','category' => 'Major'],
            ['symbol' => 'AUDUSD', 'description' => 'Australian Dollar / US Dollar','category' => 'Major'],
            ['symbol' => 'NZDUSD', 'description' => 'New Zealand Dollar / US Dollar','category' => 'Major'],

            // ── Euro Crosses ───────────────────────────────────────────────────────
            ['symbol' => 'EURGBP', 'description' => 'Euro / British Pound',        'category' => 'Euro Cross'],
            ['symbol' => 'EURJPY', 'description' => 'Euro / Japanese Yen',         'category' => 'Euro Cross'],
            ['symbol' => 'EURCHF', 'description' => 'Euro / Swiss Franc',          'category' => 'Euro Cross'],
            ['symbol' => 'EURCAD', 'description' => 'Euro / Canadian Dollar',      'category' => 'Euro Cross'],
            ['symbol' => 'EURAUD', 'description' => 'Euro / Australian Dollar',    'category' => 'Euro Cross'],
            ['symbol' => 'EURNZD', 'description' => 'Euro / New Zealand Dollar',   'category' => 'Euro Cross'],

            // ── GBP Crosses ───────────────────────────────────────────────────────
            ['symbol' => 'GBPJPY', 'description' => 'British Pound / Japanese Yen',    'category' => 'GBP Cross'],
            ['symbol' => 'GBPCHF', 'description' => 'British Pound / Swiss Franc',     'category' => 'GBP Cross'],
            ['symbol' => 'GBPCAD', 'description' => 'British Pound / Canadian Dollar', 'category' => 'GBP Cross'],
            ['symbol' => 'GBPAUD', 'description' => 'British Pound / Australian Dollar','category' => 'GBP Cross'],
            ['symbol' => 'GBPNZD', 'description' => 'British Pound / New Zealand Dollar','category' => 'GBP Cross'],

            // ── AUD Crosses ───────────────────────────────────────────────────────
            ['symbol' => 'AUDJPY', 'description' => 'Australian Dollar / Japanese Yen',    'category' => 'AUD Cross'],
            ['symbol' => 'AUDCHF', 'description' => 'Australian Dollar / Swiss Franc',     'category' => 'AUD Cross'],
            ['symbol' => 'AUDCAD', 'description' => 'Australian Dollar / Canadian Dollar', 'category' => 'AUD Cross'],
            ['symbol' => 'AUDNZD', 'description' => 'Australian Dollar / New Zealand Dollar','category' => 'AUD Cross'],

            // ── NZD Crosses ───────────────────────────────────────────────────────
            ['symbol' => 'NZDJPY', 'description' => 'New Zealand Dollar / Japanese Yen',    'category' => 'NZD Cross'],
            ['symbol' => 'NZDCHF', 'description' => 'New Zealand Dollar / Swiss Franc',     'category' => 'NZD Cross'],
            ['symbol' => 'NZDCAD', 'description' => 'New Zealand Dollar / Canadian Dollar', 'category' => 'NZD Cross'],

            // ── CAD Crosses ───────────────────────────────────────────────────────
            ['symbol' => 'CADJPY', 'description' => 'Canadian Dollar / Japanese Yen',  'category' => 'CAD Cross'],
            ['symbol' => 'CADCHF', 'description' => 'Canadian Dollar / Swiss Franc',   'category' => 'CAD Cross'],

            // ── CHF Crosses ───────────────────────────────────────────────────────
            ['symbol' => 'CHFJPY', 'description' => 'Swiss Franc / Japanese Yen', 'category' => 'CHF Cross'],

            // ── Scandinavian / EM ─────────────────────────────────────────────────
            ['symbol' => 'USDSEK', 'description' => 'US Dollar / Swedish Krona',   'category' => 'Scandinavian'],
            ['symbol' => 'USDNOK', 'description' => 'US Dollar / Norwegian Krone', 'category' => 'Scandinavian'],
            ['symbol' => 'USDDKK', 'description' => 'US Dollar / Danish Krone',    'category' => 'Scandinavian'],
            ['symbol' => 'EURSEK', 'description' => 'Euro / Swedish Krona',        'category' => 'Scandinavian'],
            ['symbol' => 'EURNOK', 'description' => 'Euro / Norwegian Krone',      'category' => 'Scandinavian'],
            ['symbol' => 'EURDKK', 'description' => 'Euro / Danish Krone',         'category' => 'Scandinavian'],

            // ── Emerging Market ───────────────────────────────────────────────────
            ['symbol' => 'USDMXN', 'description' => 'US Dollar / Mexican Peso',         'category' => 'Emerging Market'],
            ['symbol' => 'USDZAR', 'description' => 'US Dollar / South African Rand',   'category' => 'Emerging Market'],
            ['symbol' => 'USDTRY', 'description' => 'US Dollar / Turkish Lira',         'category' => 'Emerging Market'],
            ['symbol' => 'USDHKD', 'description' => 'US Dollar / Hong Kong Dollar',     'category' => 'Emerging Market'],
            ['symbol' => 'USDSGD', 'description' => 'US Dollar / Singapore Dollar',     'category' => 'Emerging Market'],
            ['symbol' => 'USDCNH', 'description' => 'US Dollar / Chinese Yuan Offshore','category' => 'Emerging Market'],
            ['symbol' => 'USDPLN', 'description' => 'US Dollar / Polish Zloty',         'category' => 'Emerging Market'],
            ['symbol' => 'USDHUF', 'description' => 'US Dollar / Hungarian Forint',     'category' => 'Emerging Market'],
            ['symbol' => 'USDCZK', 'description' => 'US Dollar / Czech Koruna',         'category' => 'Emerging Market'],
            ['symbol' => 'EURPLN', 'description' => 'Euro / Polish Zloty',              'category' => 'Emerging Market'],
            ['symbol' => 'EURHUF', 'description' => 'Euro / Hungarian Forint',          'category' => 'Emerging Market'],
            ['symbol' => 'EURCZK', 'description' => 'Euro / Czech Koruna',              'category' => 'Emerging Market'],
            ['symbol' => 'EURTRY', 'description' => 'Euro / Turkish Lira',              'category' => 'Emerging Market'],
            ['symbol' => 'EURZAR', 'description' => 'Euro / South African Rand',        'category' => 'Emerging Market'],
            ['symbol' => 'GBPZAR', 'description' => 'British Pound / South African Rand','category' => 'Emerging Market'],

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
