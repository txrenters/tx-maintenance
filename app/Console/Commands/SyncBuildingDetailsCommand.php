<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBuildingDetailsCommand extends Command
{
    protected $signature = 'sync:building-details {--force : Refresh every building, not just those missing details}';

    protected $description = 'Sync building maintenance details (maintenanceNotice, spending limits, custom fields) from PropertyWare';

    public function handle(PropertyWareService $propertyware): int
    {
        $query = Building::query();

        if (! $this->option('force')) {
            $query->whereNull('details_synced_at');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No buildings need syncing.');

            return Command::SUCCESS;
        }

        $this->info("Syncing {$total} building(s) from PropertyWare...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failed = 0;

        $query->chunkById(50, function ($buildings) use ($propertyware, &$synced, &$failed, $bar) {
            foreach ($buildings as $building) {
                $data = $propertyware->getBuilding($building->propertyware_id);

                if (! is_array($data)) {
                    $failed++;
                    $bar->advance();

                    continue;
                }

                $building->update($this->mapBuildingPayload($data));
                $synced++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Synced: {$synced}, Failed: {$failed}");
        Log::info('SyncBuildingDetails completed', ['synced' => $synced, 'failed' => $failed]);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapBuildingPayload(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'address' => $data['address']['address'] ?? null,
            'address_cont' => $data['address']['addressCont'] ?? null,
            'city' => $data['address']['city'] ?? null,
            'state_region' => $data['address']['stateRegion'] ?? null,
            'postal_code' => $data['address']['postalCode'] ?? null,
            'country' => $data['address']['country'] ?? null,
            'portfolio_id' => $data['portfolioID'] ?? null,
            'active' => $data['active'] ?? true,
            'maintenance_notice' => $data['maintenanceNotice'] ?? null,
            'maintenance_spending_limit_amount' => $data['maintenanceSpendingLimitAmount'] ?? null,
            'maintenance_spending_limit_time' => $data['maintenanceSpendingLimitTime'] ?? null,
            'maintenance_labor_surcharge_amount' => $data['maintenanceLaborSurchargeAmount'] ?? null,
            'maintenance_labor_surcharge_type' => $data['maintenanceLaborSurchargeType'] ?? null,
            'category' => $data['category'] ?? null,
            'property_type' => $data['propertyType'] ?? null,
            'custom_fields' => $data['customFields'] ?? null,
            'details_synced_at' => now(),
        ];
    }
}
