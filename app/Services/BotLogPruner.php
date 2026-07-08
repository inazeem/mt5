<?php

namespace App\Services;

use App\Models\BotTradeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BotLogPruner
{
    /**
     * @return array<string, int|bool>
     */
    public function prune(
        int $signalDays = 1,
        int $tradeDays = 90,
        bool $pruneCache = true,
        bool $pruneFiles = true,
        bool $dryRun = false,
    ): array {
        $stats = [
            'dry_run' => $dryRun,
            'bot_signals_deleted' => 0,
            'bot_trades_deleted' => 0,
            'cache_expired_deleted' => 0,
            'cache_temp_deleted' => 0,
            'cache_locks_deleted' => 0,
            'log_files_deleted' => 0,
            'log_files_truncated' => 0,
        ];

        $signalQuery = BotTradeLog::query()
            ->whereIn('event_type', ['signal', 'guardrail'])
            ->where('created_at', '<', now()->subDays(max(1, $signalDays)));

        $stats['bot_signals_deleted'] = $dryRun
            ? (int) $signalQuery->count()
            : (int) $signalQuery->delete();

        $tradeQuery = BotTradeLog::query()
            ->where('event_type', 'trade_open')
            ->where('created_at', '<', now()->subDays(max(30, $tradeDays)))
            ->whereIn('trade_outcome', ['WIN', 'LOSS', 'BREAKEVEN']);

        $stats['bot_trades_deleted'] = $dryRun
            ? (int) $tradeQuery->count()
            : (int) $tradeQuery->delete();

        if ($pruneCache) {
            $stats = array_merge($stats, $this->pruneCache($dryRun));
        }

        if ($pruneFiles) {
            $stats = array_merge($stats, $this->pruneLogFiles($dryRun));
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function pruneCache(bool $dryRun): array
    {
        $stats = [
            'cache_expired_deleted' => 0,
            'cache_temp_deleted' => 0,
            'cache_locks_deleted' => 0,
        ];

        if (config('cache.default') !== 'database') {
            return $stats;
        }

        $table = (string) (config('cache.stores.database.table') ?: 'cache');
        $locksTable = (string) (config('cache.stores.database.lock_table') ?: 'cache_locks');
        $now = now()->timestamp;
        $prefix = (string) config('cache.prefix');

        $expiredQuery = DB::table($table)->where('expiration', '<', $now);
        $stats['cache_expired_deleted'] = $dryRun
            ? (int) $expiredQuery->count()
            : (int) $expiredQuery->delete();

        $tempPatterns = [
            'auto_bot_%',
            'mt5_candles:%',
            'mt5_history_%',
            'bot_analytics_%',
            'metaapi_pause_%',
        ];

        foreach ($tempPatterns as $pattern) {
            $query = DB::table($table)->where('key', 'like', $prefix.$pattern);
            $count = $dryRun ? (int) $query->count() : (int) $query->delete();
            $stats['cache_temp_deleted'] += $count;
        }

        $locksQuery = DB::table($locksTable)->where('expiration', '<', $now);
        $stats['cache_locks_deleted'] = $dryRun
            ? (int) $locksQuery->count()
            : (int) $locksQuery->delete();

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function pruneLogFiles(bool $dryRun): array
    {
        $stats = [
            'log_files_deleted' => 0,
            'log_files_truncated' => 0,
        ];

        $logPath = storage_path('logs');
        if (! is_dir($logPath)) {
            return $stats;
        }

        $cutoff = now()->subDays(7)->timestamp;
        $maxBytes = 50 * 1024 * 1024;

        foreach (File::glob($logPath.'/*.log') ?: [] as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }

            $size = (int) filesize($file);
            $mtime = (int) filemtime($file);

            if ($mtime < $cutoff) {
                $stats['log_files_deleted']++;
                if (! $dryRun) {
                    @unlink($file);
                }

                continue;
            }

            if ($size > $maxBytes) {
                $stats['log_files_truncated']++;
                if (! $dryRun) {
                    file_put_contents($file, '');
                }
            }
        }

        return $stats;
    }
}
