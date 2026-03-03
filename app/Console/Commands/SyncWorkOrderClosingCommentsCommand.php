<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncWorkOrderClosingCommentsCommand extends Command
{
    protected $signature = 'sync:work-order-closing-comments {--overwrite : Replace existing non-empty local closing comments}';

    protected $description = 'Sync closing comments from PropertyWare onto existing local work orders';

    public function handle(): int
    {
        $headers = [
            'x-propertyware-client-id' => config('services.propertyware.client_id'),
            'x-propertyware-client-secret' => config('services.propertyware.client_secret_key'),
            'x-propertyware-system-id' => config('services.propertyware.system_id'),
        ];

        $limit = 500;
        $offset = 0;
        $overwrite = (bool) $this->option('overwrite');

        $matched = 0;
        $updated = 0;
        $unchanged = 0;
        $missingLocal = 0;
        $missingClosingComment = 0;
        $processed = 0;

        Log::info('Work order closing comment sync started.', [
            'overwrite' => $overwrite,
        ]);

        try {
            while (true) {
                $workOrders = $this->fetchBatch($headers, $limit, $offset);

                if ($workOrders === null) {
                    $offset += $limit;

                    continue;
                }

                if (empty($workOrders)) {
                    break;
                }

                [$batchMatched, $batchUpdated, $batchUnchanged, $batchMissingLocal, $batchMissingClosingComment] = $this->processBatch($workOrders, $overwrite);

                $matched += $batchMatched;
                $updated += $batchUpdated;
                $unchanged += $batchUnchanged;
                $missingLocal += $batchMissingLocal;
                $missingClosingComment += $batchMissingClosingComment;
                $processed += count($workOrders);
                $offset += $limit;

                if (count($workOrders) < $limit) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Work order closing comment sync failed: '.$e->getMessage());
            $this->error('Closing comment sync failed.');

            return self::FAILURE;
        }

        Log::info('Work order closing comment sync completed.', [
            'processed' => $processed,
            'matched' => $matched,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'missing_local' => $missingLocal,
            'missing_closing_comment' => $missingClosingComment,
            'overwrite' => $overwrite,
        ]);

        $this->info("Processed: {$processed}");
        $this->info("Matched: {$matched}");
        $this->info("Updated: {$updated}");
        $this->info("Unchanged: {$unchanged}");
        $this->info("Missing local: {$missingLocal}");
        $this->info("Missing closing comment: {$missingClosingComment}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<int, mixed>|null
     */
    private function fetchBatch(array $headers, int $limit, int $offset): ?array
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::withHeaders($headers)->get('https://api.propertyware.com/pw/api/rest/v1/workorders', [
                'includeCustomFields' => 'true',
                'orderby' => 'createddate DESC',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if ($response->successful()) {
                Log::info('Fetched work orders batch for closing comment sync', [
                    'offset' => $offset,
                    'count' => count($response->json()),
                ]);

                return $response->json();
            }

            if ($response->serverError() && $attempt < $maxRetries) {
                Log::warning('PropertyWare 5xx error during closing comment sync, retrying batch', [
                    'offset' => $offset,
                    'status_code' => $response->status(),
                    'attempt' => $attempt,
                ]);
                sleep(5);

                continue;
            }

            Log::error('Error retrieving work orders for closing comment sync', [
                'status_code' => $response->status(),
                'body' => $response->body(),
                'offset' => $offset,
                'attempt' => $attempt,
            ]);

            return null;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $workOrders
     * @return array{0:int,1:int,2:int,3:int,4:int}
     */
    private function processBatch(array $workOrders, bool $overwrite): array
    {
        $matched = 0;
        $updated = 0;
        $unchanged = 0;
        $missingLocal = 0;
        $missingClosingComment = 0;

        $propertywareIds = array_values(array_filter(
            array_map(fn ($order) => is_array($order) ? ($order['id'] ?? null) : null, $workOrders)
        ));

        $localWorkOrders = WorkOrder::whereIn('propertyware_id', $propertywareIds)
            ->get()
            ->keyBy('propertyware_id');

        foreach ($workOrders as $order) {
            $data = (array) $order;
            $propertywareId = $data['id'] ?? null;

            if (! $propertywareId) {
                continue;
            }

            $workOrder = $localWorkOrders->get($propertywareId);

            if (! $workOrder) {
                $missingLocal++;

                continue;
            }

            $matched++;

            $closingComment = $this->extractClosingComment($data['customFields'] ?? []);

            if ($closingComment === null || $closingComment === '') {
                $missingClosingComment++;

                continue;
            }

            $newValue = $overwrite
                ? $closingComment
                : (empty($workOrder->closing_comments) ? $closingComment : $workOrder->closing_comments);

            if ($newValue === $workOrder->closing_comments) {
                $unchanged++;

                continue;
            }

            $workOrder->update([
                'closing_comments' => $newValue,
            ]);

            $updated++;
        }

        return [$matched, $updated, $unchanged, $missingLocal, $missingClosingComment];
    }

    /**
     * @param  array<int, mixed>  $customFields
     */
    private function extractClosingComment(array $customFields): ?string
    {
        foreach ($customFields as $customField) {
            if (($customField['fieldName'] ?? null) !== 'closing comment') {
                continue;
            }

            $value = $customField['value'] ?? null;

            return is_string($value) ? trim($value) : $value;
        }

        return null;
    }
}
