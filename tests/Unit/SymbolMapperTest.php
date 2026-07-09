<?php

namespace Tests\Unit;

use App\Models\Mt5EaTerminal;
use App\Services\SymbolMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SymbolMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_spread_bet_suffix_maps_six_letter_pairs(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'pepper-demo',
            'display_name' => 'Pepperstone Demo',
            'symbol_suffix' => SymbolMapper::SUFFIX_SPREAD_BET,
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('GBPUSD_SB', $mapper->toBrokerSymbol($terminal, 'GBPUSD'));
        $this->assertSame('EURAUD_SB', $mapper->toBrokerSymbol($terminal, 'EURAUD'));
        $this->assertSame('XAUUSD_SB', $mapper->toBrokerSymbol($terminal, 'XAUUSD'));
    }

    public function test_plain_suffix_keeps_canonical_symbol(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'ic-demo',
            'display_name' => 'IC Markets Demo',
            'symbol_suffix' => SymbolMapper::SUFFIX_NONE,
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('GBPUSD', $mapper->toBrokerSymbol($terminal, 'GBPUSD'));
    }

    public function test_explicit_symbol_map_overrides_suffix_policy(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'custom',
            'display_name' => 'Custom',
            'symbol_suffix' => SymbolMapper::SUFFIX_NONE,
            'symbol_map' => ['GBPUSD' => 'GBPUSD.pro'],
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('GBPUSD.PRO', $mapper->toBrokerSymbol($terminal, 'GBPUSD'));
    }

    public function test_auto_detects_spread_bet_from_quote_cache(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'auto-sb',
            'display_name' => 'Auto SB',
            'symbol_suffix' => SymbolMapper::SUFFIX_AUTO,
            'market_quotes' => [
                'GBPUSD_SB' => ['bid' => 1.1, 'ask' => 1.2],
            ],
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('GBPUSD_SB', $mapper->toBrokerSymbol($terminal, 'GBPUSD'));
    }

    public function test_raw_trading_company_maps_to_plain_symbols(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'icmarketdemo',
            'display_name' => 'ICMARKET DEMO',
            'broker_company' => 'Raw Trading Ltd',
            'symbol_suffix' => SymbolMapper::SUFFIX_AUTO,
            'market_quotes' => [
                'AUDUSD' => ['bid' => 0.69, 'ask' => 0.6901],
                'AUDUSD_SB' => ['bid' => 0.69, 'ask' => 0.6901],
            ],
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('AUDUSD', $mapper->toBrokerSymbol($terminal, 'AUDUSD'));
        $this->assertSame(['AUDUSD'], array_values(array_intersect(
            $mapper->brokerSymbolCandidates($terminal, 'AUDUSD'),
            ['AUDUSD']
        )));
        $this->assertFalse(in_array('AUDUSD_SB', $mapper->brokerSymbolCandidates($terminal, 'AUDUSD'), true));
    }

    public function test_plain_mode_ignores_stale_spread_bet_quote_keys(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'ic-plain',
            'display_name' => 'IC Plain',
            'broker_company' => 'Raw Trading Ltd',
            'symbol_suffix' => SymbolMapper::SUFFIX_NONE,
            'market_quotes' => [
                'AUDUSD_SB' => ['bid' => 0.69, 'ask' => 0.6901],
                'AUDUSD' => ['bid' => 0.69, 'ask' => 0.6901],
            ],
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('AUDUSD', $mapper->toBrokerSymbol($terminal, 'AUDUSD'));
    }

    public function test_us_equity_suffix_normalizes_to_root_symbol(): void
    {
        $terminal = Mt5EaTerminal::query()->create([
            'instance_key' => 'ic-demo',
            'display_name' => 'IC Markets Demo',
            'symbol_suffix' => SymbolMapper::SUFFIX_NONE,
            'market_quotes' => [
                'GOOGL' => ['bid' => 170.1, 'ask' => 170.2],
            ],
            'enabled' => true,
            'is_demo' => true,
        ]);

        $mapper = app(SymbolMapper::class);

        $this->assertSame('GOOGL', $mapper->normalizeCanonical('GOOGL.US'));
        $this->assertSame('GOOGL', $mapper->toBrokerSymbol($terminal, 'GOOGL.US'));
        $this->assertContains('GOOGL', $mapper->brokerSymbolCandidates($terminal, 'GOOGL.US'));
        $this->assertContains('GOOGL.US', $mapper->brokerSymbolCandidates($terminal, 'GOOGL'));
    }

    public function test_parse_map_input(): void
    {
        $map = SymbolMapper::parseMapInput("GBPUSD=GBPUSD_SB\n# comment\nEURAUD:EURAUD_SB\n");

        $this->assertSame([
            'GBPUSD' => 'GBPUSD_SB',
            'EURAUD' => 'EURAUD_SB',
        ], $map);
    }
}
