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
     * sends at most one small batch request. Idempotent; safe to re-run.
     * Note that an address edited in PropertyWare only reaches the local
     * buildings row via sync:building-details (which skips already-synced
     * rows unless --force), so a PW-side correction may need a forced
     * details sync first.
     */
    protected $description = 'Resolve building street addresses to coordinates via the free US Census batch geocoder';

    private const RETRY_MISSES_AFTER_DAYS = 30;

    /**
     * The Census batch endpoint takes up to 10k rows; stay well under it so
     * one request never carries more than a few minutes of server-side work.
     */
    private const BATCH_SIZE = 5000;

    public function handle(GeocodeService $geocoder): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $skipped = 0;
        $unmappable = 0;

        /** @var array<int, array{building: Building, parts: array{street: string, city: string|null, state: string, zip: string|null}, input: string}> $candidates */
        $candidates = [];

        Building::query()
            ->where('active', true)
            ->chunkById(50, function ($buildings) use ($force, $dryRun, &$candidates, &$skipped, &$unmappable) {
                foreach ($buildings as $building) {
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

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("would geocode {$building->name} ({$input})");
                    }

                    $candidates[$building->id] = [
                        'building' => $building,
                        'parts' => GeocodeService::addressParts($building),
                        'input' => $input,
                    ];
                }
            });

        if ($dryRun) {
            $count = count($candidates);
            $this->info("Would geocode {$count} building(s). Skipped: {$skipped}, Unmappable: {$unmappable}.");

            return Command::SUCCESS;
        }

        $geocoded = 0;
        $noMatch = 0;
        $failed = 0;

        if ($candidates !== []) {
            $this->info('Geocoding '.count($candidates).' building(s) via the Census batch endpoint...');
        }

        foreach (array_chunk($candidates, self::BATCH_SIZE, preserve_keys: true) as $chunk) {
            $rows = array_map(fn (array $candidate) => $candidate['parts'], $chunk);
            $results = $geocoder->geocodeBatch($rows);

            if ($results === null) {
                // The whole request failed (WAF block, outage). Leave every
                // row untouched so the next run retries, and stop instead of
                // sending more batches into a firewall that is refusing us.
                $failed += count($chunk);
                $this->warn('Census batch request failed (rate limited?). The next run retries these buildings.');
                break;
            }

            foreach ($chunk as $id => $candidate) {
                $result = $results[$id] ?? null;

                if ($result === null) {
                    $failed++;
                } elseif (! $result['found']) {
                    $candidate['building']->update([
                        'latitude' => null,
                        'longitude' => null,
                        'geocoded_address' => $candidate['input'],
                        'geocoded_at' => now(),
                    ]);
                    $noMatch++;
                } else {
                    $candidate['building']->update([
                        'latitude' => $result['lat'],
                        'longitude' => $result['lng'],
                        'geocoded_address' => $candidate['input'],
                        'geocoded_at' => now(),
                    ]);
                    $geocoded++;
                }
            }
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
