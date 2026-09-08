<?php

namespace App\Console\Commands;

use App\Exceptions\PropertyWareAccessDeniedException;
use App\Models\Lease;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Imports PropertyWare leases so the app can show a property's real lease
 * status rather than inferring occupancy from the job type.
 *
 * Paging, retries and backoff mirror ImportAllVendorsCommand: a page that
 * 5xxes is retried three times, and a page that still fails is skipped rather
 * than abandoning the run, so one bad page cannot cost a whole sync.
 */
class SyncLeasesCommand extends Command
{
    protected $signature = 'sync:leases
        {--limit= : Stop after this many leases (for a first run or local testing)}
        {--dry-run : Fetch and report without writing anything}';

    protected $description = 'Import and update leases from PropertyWare';

    private const BATCH_SIZE = 500;

    public function handle(PropertyWareService $propertyWare): int
    {
        $maxToImport = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = $maxToImport !== null ? min(self::BATCH_SIZE, $maxToImport) : self::BATCH_SIZE;

        $offset = 0;
        $totalProcessed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        Log::info('Lease sync started.', ['limit' => $maxToImport, 'dry_run' => $dryRun]);
        $this->info($dryRun ? 'Fetching leases from PropertyWare (dry run)...' : 'Importing leases from PropertyWare...');

        try {
            while (true) {
                $leases = $this->fetchBatch($propertyWare, $batchSize, $offset);

                if ($leases === null) {
                    $this->warn("Skipping the page at offset {$offset} after repeated failures.");
                    $offset += $batchSize;

                    continue;
                }

                if ($leases === []) {
                    break;
                }

                if ($maxToImport !== null && $totalProcessed + count($leases) > $maxToImport) {
                    $leases = array_slice($leases, 0, $maxToImport - $totalProcessed);
                }

                foreach ($leases as $leaseData) {
                    $attributes = $this->mapLease((array) $leaseData);

                    if ($attributes === null) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  PW %s | building %s | %s | %s to %s',
                            $attributes['propertyware_id'],
                            $attributes['building_id'] ?? '-',
                            $attributes['status'] ?? '-',
                            $attributes['start_date'] ?? '-',
                            $attributes['end_date'] ?? '-',
                        ));

                        continue;
                    }

                    $lease = Lease::updateOrCreate(
                        ['propertyware_id' => $attributes['propertyware_id']],
                        $attributes + ['synced_at' => now()],
                    );

                    $lease->wasRecentlyCreated ? $created++ : $updated++;
                }

                $totalProcessed += count($leases);
                $offset += $batchSize;

                $this->info("Processed {$totalProcessed} leases so far...");

                if ($maxToImport !== null && $totalProcessed >= $maxToImport) {
                    break;
                }

                if (count($leases) < $batchSize) {
                    break;
                }
            }
        } catch (PropertyWareAccessDeniedException $e) {
            Log::error('Lease sync aborted: '.$e->getMessage());
            $this->error($e->getMessage());
            $this->line('Ask PropertyWare to grant the API key read access to Leases, then re-run this command.');

            return Command::FAILURE;
        } catch (\Throwable $e) {
            Log::error('Lease sync failed: '.$e->getMessage());
            $this->error('Lease sync failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $summary = $dryRun
            ? "Dry run complete. Fetched: {$totalProcessed}, unusable: {$skipped}."
            : "Done. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}, Total processed: {$totalProcessed}";

        $this->info($summary);
        Log::info('Lease sync finished.', [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $totalProcessed,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return array<int, mixed>|null Null when the page could not be fetched.
     */
    private function fetchBatch(PropertyWareService $propertyWare, int $limit, int $offset): ?array
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $leases = $propertyWare->getLeases($limit, $offset);

            if ($leases !== null) {
                return $leases;
            }

            if ($attempt < $maxRetries) {
                Log::warning('PropertyWare lease fetch failed, retrying', [
                    'offset' => $offset,
                    'attempt' => $attempt,
                ]);
                sleep(5);
            }
        }

        return null;
    }

    /**
     * Normalizes one PropertyWare lease payload.
     *
     * PropertyWare is inconsistent about key casing between endpoints, and the
     * building reference arrives either flat or nested, so each field is read
     * from the spellings the API is known to use. A row without an id cannot be
     * upserted, so it is skipped rather than guessed at.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function mapLease(array $data): ?array
    {
        $propertywareId = $this->firstValue($data, ['ID', 'id', 'leaseID', 'leaseId']);

        if ($propertywareId === null || ! is_numeric($propertywareId)) {
            return null;
        }

        $buildingId = $this->firstValue($data, ['buildingID', 'buildingId', 'building_id']);

        if ($buildingId === null && isset($data['building']) && is_array($data['building'])) {
            $buildingId = $this->firstValue($data['building'], ['ID', 'id']);
        }

        $unitId = $this->firstValue($data, ['unitID', 'unitId', 'unit_id']);

        return [
            'propertyware_id' => (int) $propertywareId,
            'building_id' => is_numeric($buildingId) ? (int) $buildingId : null,
            'unit_id' => is_numeric($unitId) ? (int) $unitId : null,
            'status' => $this->stringOrNull($this->firstValue($data, ['status', 'leaseStatus', 'lease_status'])),
            'start_date' => $this->dateOrNull($this->firstValue($data, ['startDate', 'start_date', 'moveInDate'])),
            'end_date' => $this->dateOrNull($this->firstValue($data, ['endDate', 'end_date', 'moveOutDate'])),
            'tenant_name' => $this->stringOrNull($this->firstValue($data, ['tenantName', 'tenant_name', 'primaryContactName'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * PropertyWare returns dates in several shapes across endpoints, so an
     * unparseable value is stored as null rather than failing the whole sync.
     */
    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
