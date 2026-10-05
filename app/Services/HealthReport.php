<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * What "healthy" means for this application: the checks /up must fail on.
 *
 * On the VPS the containers reach the host's MySQL through a bind-mounted
 * socket directory. When mysqld restarts, that mount can go stale and every
 * request fails, yet a health check that only proves PHP-FPM answers stays
 * green. Adapted from tx-chatbot, where exactly that hid a 41-hour outage.
 */
class HealthReport
{
    /**
     * Checks that decide up or down. A failure means the app cannot serve a
     * request, so /up errors and the container health check goes red.
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function critical(): array
    {
        return [
            'database' => $this->check(function (): string {
                DB::connection()->getPdo();

                return (string) config('database.default');
            }),
            'cache' => $this->check(function (): string {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $value = Cache::get($key);
                Cache::forget($key);

                if ($value !== 'ok') {
                    throw new RuntimeException('cache did not return what was written');
                }

                return (string) config('cache.default');
            }),
        ];
    }

    /**
     * Runs one probe. The failure detail can name internal paths (the mysqld
     * socket, for one), so it is for logs only, never for the response body.
     *
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
