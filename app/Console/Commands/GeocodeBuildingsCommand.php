<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Services\GeocodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GeocodeBuildingsCommand extends Command
{
    protected $signature = 'geocode:buildings
        {--force : Re-geocode every active building}
        {--dry-run : Report what would be geocoded without calling the Census API or writing}';

    /**
     * Steady state touches only new or changed addresses, so a nightly run
     * makes near-zero API calls. Idempotent; safe to re-run. Note that an
     * address edited in PropertyWare only reaches the local buildings row via
     * sync:building-details (which skips already-synced rows unless --force),
     * so a PW-side correction may need a forced details sync first.
     */
    protected $description = 'Resolve building street addresses to coordinates via the free US Census geocoder';

    private const RETRY_MISSES_AFTER_DAYS = 30;

    /**
     * Consecutive request failures before the run gives up. The Census WAF
     * rate-limits by IP; once it starts blocking, every further call only
     * prolongs the block, so stop and let the next run pick up the rest.
     */
    private const ABORT_AFTER_CONSECUTIVE_FAILURES = 10;

    public function handle(GeocodeService $geocoder): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $query = Building::query()->where('active', true);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No active buildings.');

            return Command::SUCCESS;
        }

        $geocoded = 0;
        $noMatch = 0;
        $failed = 0;
        $skipped = 0;
        $unmappable = 0;
        $wouldGeocode = 0;
        $consecutiveFailures = 0;

        $bar = $dryRun ? null : $this->output->createProgressBar($total);
        $bar?->start();

        $query->chunkById(50, function ($buildings) use ($geocoder, $force, $dryRun, $bar, &$geocoded, &$noMatch, &$failed, &$skipped, &$unmappable, &$wouldGeocode, &$consecutiveFailures) {
            foreach ($buildings as $building) {
                if ($consecutiveFailures >= self::ABORT_AFTER_CONSECUTIVE_FAILURES) {
                    return false;
                }
                $input = GeocodeService::assembleAddress($building);

                if ($input === null) {
                    if (! $dryRun && ($building->geocoded_at === null || $building->latitude !== null)) {
                        $building->update([
                            'latitude' => null,
                            'longitude' => null,
                            'geocoded_address' => null,
                            'geocoded_at' => now(),
                        ]);
                    }
                    $unmappable++;
                    $bar?->advance();

                    continue;
                }

                $freshMiss = $building->latitude === null
                    && $building->geocoded_at !== null
                    && $building->geocoded_at->gt(now()->subDays(self::RETRY_MISSES_AFTER_DAYS));

                if (! $force
                    && $building->geocoded_at !== null
                    && $building->geocoded_address === $input
                    && ($building->latitude !== null || $freshMiss)) {
                    $skipped++;
                    $bar?->advance();

                    continue;
                }

                if ($dryRun) {
                    $this->line("would geocode {$building->name} ({$input})");
                    $wouldGeocode++;

                    continue;
                }

                $parts = GeocodeService::addressParts($building);
                $result = $geocoder->geocode(
                    $parts['street'],
                    $parts['city'],
                    $parts['state'],
                    $parts['zip'],
                );

                if ($result === null) {
                    $failed++;
                    $consecutiveFailures++;
                } elseif (! $result['found']) {
                    $consecutiveFailures = 0;
                    $building->update([
                        'latitude' => null,
                        'longitude' => null,
                        'geocoded_address' => $input,
                        'geocoded_at' => now(),
                    ]);
                    $noMatch++;
                } else {
                    $consecutiveFailures = 0;
                    $building->update([
                        'latitude' => $result['lat'],
                        'longitude' => $result['lng'],
                        'geocoded_address' => $input,
                        'geocoded_at' => now(),
                    ]);
                    $geocoded++;
                }

                // Politeness pause: the Census WAF velocity-bans IPs that
                // request too quickly, so take a full second between calls.
                if (! app()->runningUnitTests()) {
                    sleep(1);
                }
                $bar?->advance();
            }
        });

        if ($dryRun) {
            $this->info("Would geocode {$wouldGeocode} building(s). Skipped: {$skipped}, Unmappable: {$unmappable}.");

            return Command::SUCCESS;
        }

        $bar?->finish();
        $this->newLine();

        if ($consecutiveFailures >= self::ABORT_AFTER_CONSECUTIVE_FAILURES) {
            $this->warn('Aborted early: the Census API is refusing requests (rate limited?). The next run resumes where this one stopped.');
        }

        $this->info("Geocoded: {$geocoded}, No match: {$noMatch}, Failed: {$failed}, Skipped: {$skipped}, Unmappable: {$unmappable}");
        Log::info('GeocodeBuildings completed', [
            'geocoded' => $geocoded,
            'no_match' => $noMatch,
            'failed' => $failed,
            'skipped' => $skipped,
            'unmappable' => $unmappable,
        ]);

        return Command::SUCCESS;
    }
}
