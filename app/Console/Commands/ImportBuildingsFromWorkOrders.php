<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\WorkOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportBuildingsFromWorkOrders extends Command
{
    protected $signature = 'import:buildings-from-work-orders';

    protected $description = 'Import missing buildings referenced by existing work orders';

    /** @var array<int, bool> */
    private array $existingBuildingIds = [];

    public function handle(): int
    {
        $headers = [
            'x-propertyware-client-id' => config('services.propertyware.client_id'),
            'x-propertyware-client-secret' => config('services.propertyware.client_secret_key'),
            'x-propertyware-system-id' => config('services.propertyware.system_id'),
        ];

        $this->existingBuildingIds = array_fill_keys(
            Building::pluck('propertyware_id')->map(fn ($id) => (int) $id)->all(),
            true
        );

        $created = 0;
        $skipped = 0;

        Log::info('Building import from work orders started.');

        WorkOrder::query()
            ->select('building_id')
            ->whereNotNull('building_id')
            ->distinct()
            ->orderBy('building_id')
            ->chunk(500, function ($rows) use ($headers, &$created, &$skipped) {
                foreach ($rows as $row) {
                    $propertywareId = (int) $row->building_id;

                    if ($propertywareId === 0 || isset($this->existingBuildingIds[$propertywareId])) {
                        $skipped++;
                        continue;
                    }

                    $buildingData = $this->fetchBuilding($propertywareId, $headers);

                    if (! $buildingData) {
                        $skipped++;
                        continue;
                    }

                    Building::create([
                        'propertyware_id' => $propertywareId,
                        'name' => $buildingData['name'] ?? null,
                        'address' => $buildingData['address']['address'] ?? null,
                        'address_cont' => $buildingData['address']['addressCont'] ?? null,
                        'city' => $buildingData['address']['city'] ?? null,
                        'state_region' => $buildingData['address']['stateRegion'] ?? null,
                        'postal_code' => $buildingData['address']['postalCode'] ?? null,
                        'country' => $buildingData['address']['country'] ?? null,
                        'portfolio_id' => $buildingData['portfolioID'] ?? null,
                        'active' => $buildingData['active'] ?? true,
                    ]);

                    $this->existingBuildingIds[$propertywareId] = true;
                    $created++;
                }
            });

        Log::info('Building import from work orders finished.', [
            'created' => $created,
            'skipped' => $skipped,
        ]);

        $this->info("Buildings created: {$created}. Skipped: {$skipped}.");

        return Command::SUCCESS;
    }

    private function fetchBuilding(int $propertywareId, array $headers): ?array
    {
        $response = Http::withHeaders($headers)
            ->get("https://api.propertyware.com/pw/api/rest/v1/buildings/{$propertywareId}");

        if (! $response->successful()) {
            Log::warning('Could not fetch building from PropertyWare', [
                'propertyware_id' => $propertywareId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }
}
