<?php

namespace App\Listeners;

use App\Services\HealthReport;
use Illuminate\Foundation\Events\DiagnosingHealth;
use RuntimeException;

/**
 * Hooks the critical checks into Laravel's health route (health: '/up' in
 * bootstrap/app.php), which the VPS container health checks call.
 *
 * Throwing is the documented way to fail that route: it answers 500, the
 * container goes unhealthy, and a lost database shows up as an unhealthy
 * container instead of a healthy one serving nothing but error pages.
 */
class VerifyDependenciesAreReachable
{
    public function __construct(private readonly HealthReport $health) {}

    public function handle(DiagnosingHealth $event): void
    {
        foreach ($this->health->critical() as $name => $result) {
            if (! $result['ok']) {
                throw new RuntimeException("Health check failed: {$name} — {$result['detail']}");
            }
        }
    }
}
