<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\Lease;
use App\Services\PropertyWareLeaseReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Imports lease status from PropertyWare's saved-report export so the invoice
 * list can show whether a property is still tenanted.
 *
 * The REST API denies /leases for our key, so the report feed is the source.
 * It has no building id, so each row is matched to a building by normalized
 * address here, once per sync -- the invoice page then does a single indexed
 * lookup instead of fuzzy-matching on every page load.
 *
 * Rows whose address matches no building are counted and reported rather than
 * stored: a property we cannot identify has nothing to show against.
 */
class SyncLeasesCommand extends Command
{
    protected $signature = 'sync:leases
        {--dry-run : Fetch and report the match rate without writing anything}
        {--show-unmatched=15 : How many unmatched addresses to list}';

    protected $description = 'Import lease status from the PropertyWare report feed';

    public function handle(PropertyWareLeaseReport $report): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Fetching the PropertyWare lease report (dry run)...' : 'Importing leases from the PropertyWare report...');
        Log::info('Lease sync started.', ['dry_run' => $dryRun]);

        $rows = $report->fetch();

        if ($rows === null) {
            $this->error('Could not read the PropertyWare lease report.');
            Log::error('Lease sync aborted: the lease report could not be read.');

            return Command::FAILURE;
        }

        if ($rows === []) {
            $this->warn('The lease report returned no rows; leaving existing leases untouched.');
            Log::warning('Lease sync found no rows.');

            return Command::SUCCESS;
        }

        $buildingsByAddress = $this->buildingsByAddressKey($report);

        $matched = 0;
        $created = 0;
        $updated = 0;
        $unmatched = [];
        $syncedAt = now();

        foreach ($rows as $row) {
            $buildingId = $buildingsByAddress[$row['address_key']] ?? null;

            if ($buildingId === null) {
                // The report may spell the property without its street suffix.
                $withoutSuffix = $report->withoutStreetSuffix($row['address_key']);
                $buildingId = $withoutSuffix !== '' ? ($buildingsByAddress[$withoutSuffix] ?? null) : null;
            }

            if ($buildingId === null) {
                $unmatched[] = $row['address'];

                continue;
            }

            $matched++;

            if ($dryRun) {
                continue;
            }

            // The report has no lease id, so a building's lease row is keyed by
            // the building itself: the feed carries the current lease per
            // property, which is exactly what the column shows.
            $lease = Lease::updateOrCreate(
                ['building_id' => $buildingId],
                [
                    'status' => $row['status'],
                    'tenant_name' => $row['tenant_name'] ?: null,
                    'address' => $row['address'],
                    'synced_at' => $syncedAt,
                ],
            );

            $lease->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->reportOutcome($rows, $matched, $created, $updated, $unmatched, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Every building keyed by its normalized address, so a lease row resolves
     * to a building_id with an array lookup rather than a query each.
     *
     * Buildings are keyed by PropertyWare id because that is what
     * work_orders.building_id holds. A duplicate key keeps the first building
     * seen, so the mapping stays deterministic between runs.
     *
     * Both the address and the name are indexed, and the street suffix is
     * dropped from each as a third key. PropertyWare stores a property's
     * address and its name inconsistently -- building 1122 is named "1122
     * Cascade Creek" but addressed "1122 Cascade Creek Dr", and the lease
     * report may carry either -- so matching on one alone loses a large share
     * of the roster.
     *
     * @return array<string, int>
     */
    private function buildingsByAddressKey(PropertyWareLeaseReport $report): array
    {
        $byAddress = [];

        Building::query()
            ->whereNotNull('propertyware_id')
            ->select(['propertyware_id', 'address', 'name'])
            ->orderBy('propertyware_id')
            ->chunk(500, function ($buildings) use (&$byAddress, $report): void {
                foreach ($buildings as $building) {
                    $propertywareId = (int) $building->propertyware_id;

                    foreach ([$building->address, $building->name] as $source) {
                        $key = $report->addressKey((string) $source);

                        if ($key === '') {
                            continue;
                        }

                        $byAddress[$key] ??= $propertywareId;

                        $withoutSuffix = $report->withoutStreetSuffix($key);

                        if ($withoutSuffix !== '' && $withoutSuffix !== $key) {
                            $byAddress[$withoutSuffix] ??= $propertywareId;
                        }
                    }
                }
            });

        return $byAddress;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, string>  $unmatched
     */
    private function reportOutcome(array $rows, int $matched, int $created, int $updated, array $unmatched, bool $dryRun): void
    {
        $total = count($rows);
        $rate = $total > 0 ? round($matched / $total * 100, 1) : 0.0;

        $this->info(sprintf(
            '%s %d of %d lease rows matched a building (%s%%).',
            $dryRun ? 'Dry run:' : 'Done.',
            $matched,
            $total,
            $rate,
        ));

        if (! $dryRun) {
            $this->info("Created: {$created}, Updated: {$updated}.");
        }

        $showUnmatched = max(0, (int) $this->option('show-unmatched'));

        if ($unmatched !== [] && $showUnmatched > 0) {
            $this->warn(count($unmatched).' row(s) matched no building. First '.min($showUnmatched, count($unmatched)).':');

            foreach (array_slice($unmatched, 0, $showUnmatched) as $address) {
                $this->line('  '.$address);
            }
        }

        Log::info('Lease sync finished.', [
            'total' => $total,
            'matched' => $matched,
            'unmatched' => count($unmatched),
            'match_rate' => $rate,
            'created' => $created,
            'updated' => $updated,
            'dry_run' => $dryRun,
        ]);
    }
}
