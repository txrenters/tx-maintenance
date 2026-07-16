<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBuildingCustomFieldsRequest;
use App\Jobs\GenerateOnboardingPdfJob;
use App\Jobs\GenerateW9PdfJob;
use App\Models\Building;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Response;
use mikehaertl\pdftk\Pdf;

class BuildingController extends Controller
{
    public function index(Request $request): Response
    {
        // Staff-only directory — never expose every property to a tenant/vendor/owner.
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc', 'accounting']), 403);

        $perPage = $request->per_page
            ? ($request->per_page === 'All' ? Building::count() : (int) $request->per_page)
            : 50;

        $buildings = Building::query()
            ->when($request->search, fn ($q) => $q->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('address', 'like', '%'.$request->search.'%')
                ->orWhere('city', 'like', '%'.$request->search.'%'))
            ->withCount(['workOrders as work_orders_count' => fn ($q) => $q->scoped()])
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn ($building) => [
                'id' => $building->id,
                'propertyware_id' => $building->propertyware_id,
                'name' => $building->name,
                'address' => collect([$building->address, $building->city, $building->state_region, $building->postal_code])->filter()->implode(', '),
                'active' => $building->active,
                'work_orders_count' => $building->work_orders_count,
            ]);

        return inertia('Building/Index', [
            'title' => 'Buildings',
            'buildings' => $buildings,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    public function show(Building $building): Response
    {
        abort_unless((bool) request()->user()?->hasAnyRole(['admin', 'woc', 'accounting']), 403);

        $workOrders = $building->workOrders()
            ->scoped()
            ->with('service_status')
            ->latest('created_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($wo) => [
                'id' => $wo->id,
                'work_order_no' => $wo->work_order_no,
                'description' => $wo->description,
                'status' => $wo->status,
                'service_status' => $wo->service_status?->name,
                'priority' => $wo->priority,
                'category' => $wo->category,
                'created_date' => $wo->created_date ? Carbon::parse($wo->created_date)->format('M d, Y') : null,
            ]);

        return inertia('Building/Show', [
            'title' => $building->name ?? 'Building Details',
            'building' => [
                'id' => $building->id,
                'propertyware_id' => $building->propertyware_id,
                'name' => $building->name,
                'address' => $building->address,
                'address_cont' => $building->address_cont,
                'city' => $building->city,
                'state_region' => $building->state_region,
                'postal_code' => $building->postal_code,
                'country' => $building->country,
                'active' => $building->active,
                'maintenance_notice' => $building->maintenance_notice,
                'maintenance_spending_limit_amount' => $building->maintenance_spending_limit_amount,
                'maintenance_spending_limit_time' => $building->maintenance_spending_limit_time,
                'maintenance_labor_surcharge_amount' => $building->maintenance_labor_surcharge_amount,
                'maintenance_labor_surcharge_type' => $building->maintenance_labor_surcharge_type,
                'category' => $building->category,
                'property_type' => $building->property_type,
                'custom_fields' => $building->custom_fields,
                'details_synced_at' => $building->details_synced_at?->toIso8601String(),
            ],
            'workOrders' => $workOrders,
        ]);
    }

    public function create(Request $request)
    {
        return inertia('Building/Create', [
            'title' => 'Create Building',
        ]);
    }

    public function searchBuilding(Request $request)
    {
        $response = Http::get('https://app.propertyware.com/pw/00a/4286939136/JSON?fzqyTfsI&shardKey=182255624');

        if ($response->failed()) {
            return response()->json(['error' => 'Failed to fetch data from Propertyware'], 500);
        }

        Log::info('Searching property: ', [
            'Property name' => $request->propertyName,
            'Client name' => $request->fullName,
            'Contact name' => strtolower($this->formatToUSDisplay($request->contactNumber)),
        ]);

        $records = $response->json()['records'] ?? [];

        // Filter records by building name (can also include owner match logic)
        $filtered = collect($records)->filter(function ($record) use ($request) {

            $propertyName = strtolower($request->propertyName);
            $fullName = strtolower($request->fullName);
            $formattedPhone = $this->formatToUSDisplay($request->contactNumber);

            return
                str_contains(strtolower($record[2] ?? ''), $propertyName) &&
                str_contains(strtolower($record[6] ?? ''), $fullName) &&
                (
                    str_contains($record[7] ?? '', $formattedPhone) ||
                    str_contains($record[8] ?? '', $formattedPhone) ||
                    str_contains($record[9] ?? '', $formattedPhone)
                );
        })->values();

        // Format for front-end
        if ($filtered->isEmpty()) {
            Log::error('No matching building found');

            return response()->json(['error' => 'No matching building found'], 404);
        }

        // Fetch details for all matching buildings
        $buildings = [];

        foreach ($filtered as $record) {
            $buildingId = $record[5]; // Get building ID from index 5
            Log::info('Searching Building:', ['building' => $buildingId]);

            $response = $this->getBuilding($buildingId);

            if ($response['success']) {
                $buildings[] = $response['building'];
                Log::info('Building found:', ['building' => $response['building']]);
            }
        }

        if (empty($buildings)) {
            return response()->json(['error' => 'Failed to fetch building details'], 500);
        }

        // Return all matching buildings
        return response()->json(['buildings' => $buildings, 'count' => count($buildings)], 200);

    }

    public function updateCustomFields(UpdateBuildingCustomFieldsRequest $request, $buildingId)
    {
        $validated = $request->validated();

        try {
            $propertywareData = $request->input('propertywareData');

            // Log the update attempt
            Log::info('Updating custom fields for building: '.$buildingId, [
                'fields_count' => count($propertywareData['fieldSetDTOS']),
                'fields' => array_map(function ($field) {
                    return $field['name'].' => '.$field['value'];
                }, $propertywareData['fieldSetDTOS']),
            ]);

            $formData = $request->input('formData');

            // Make API call to Propertyware for custom fields
            $response = $this->updatePropertywareCustomFields($propertywareData);

            if ($response['success']) {
                // Update maintenanceNotice field if provided
                $maintenanceNotice = $request->input('maintenanceNotice');
                if ($maintenanceNotice !== null) {
                    $maintenanceResponse = $this->updateMaintenanceNotice($buildingId, $maintenanceNotice);
                    if (! $maintenanceResponse['success']) {
                        Log::warning('Failed to update maintenance notice', [
                            'Building ID' => $buildingId,
                            'Error' => $maintenanceResponse['error'],
                        ]);
                    }
                }

                // Update native pet fields via building PUT endpoint
                $petResponse = $this->updatePetFields($buildingId, $formData);
                if (! $petResponse['success']) {
                    Log::warning('Failed to update pet fields', [
                        'Building ID' => $buildingId,
                        'Error' => $petResponse['error'],
                    ]);
                }

                $signature = $request->input('signature');
                $ownerName = $request->input('ownerName');

                // Get building details for PDF
                $buildingResponse = $this->getBuilding($buildingId);
                if ($buildingResponse['success']) {
                    $buildingData = $buildingResponse['building'];

                    // Dispatch PDF generation job
                    GenerateOnboardingPdfJob::dispatch($signature, $formData, $buildingData, $propertywareData, $ownerName)->delay(now()->addSeconds(5));

                    if (! empty($formData['w9_entity_name']) || ! empty($formData['w9_business_name'])) {
                        GenerateW9PdfJob::dispatch($signature, $formData, $buildingData, $propertywareData, $ownerName)->delay(now()->addSeconds(5));
                    }

                    Log::info('Property has been updated successfully', [
                        'Building ID' => $buildingId,
                        'Fields updated' => count($propertywareData['fieldSetDTOS']),
                    ]);

                }

                return response()->json([
                    'success' => true,
                    'message' => 'Building information updated successfully',
                    'updated_fields' => array_column($propertywareData['fieldSetDTOS'], 'name'),
                    'total_updated' => count($propertywareData['fieldSetDTOS']),
                ]);
            } else {
                Log::error('Failed to update custom fields: Propertyware API rejected the submission', [
                    'Building ID' => $buildingId,
                    'Error' => $response['error'] ?? null,
                ]);

                return response()->json([
                    'error' => 'Failed to update building information',
                    'message' => $response['message'] ?? $this->friendlyPropertywareError($response['error'] ?? null),
                ], 422);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update custom fields: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update building information',
                'message' => 'Something went wrong while saving your information. Please try again in a few minutes, and contact us if the problem continues.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function getBuilding($buildingId)
    {
        $building = Http::withHeaders([
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
        ])->get("https://api.propertyware.com/pw/api/rest/v1/buildings/{$buildingId}");

        if ($building->failed()) {
            Log::error('Failed to fetch building details', ['error' => $building->failed()]);

            return ['success' => false, 'error' => 'Failed to fetch building details'];

        }

        return ['success' => true, 'building' => $building->json()];
    }

    private function updatePropertywareCustomFields($data)
    {
        // Normalize free-text values so legacy Propertyware TEXT fields accept them.
        if (! empty($data['fieldSetDTOS']) && is_array($data['fieldSetDTOS'])) {
            $data['fieldSetDTOS'] = array_map(function ($field) {
                if (isset($field['value']) && is_string($field['value'])) {
                    $field['value'] = $this->sanitizeCustomFieldValue($field['value']);
                }

                return $field;
            }, $data['fieldSetDTOS']);
        }

        // Make API call to Propertyware
        $response = Http::withHeaders([
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
            'Content-Type' => 'application/json',
        ])->put('https://api.propertyware.com/pw/api/rest/v1/buildings/customfields', $data);

        if ($response->successful()) {

            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        Log::error('Propertyware API Error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return [
            'success' => false,
            'error' => $response->body(),
            'message' => $this->friendlyPropertywareError($response->body()),
        ];
    }

    /**
     * Normalize a free-text custom field value so Propertyware's legacy TEXT fields accept it.
     *
     * Owners frequently paste notes from Word or Google Docs, which introduces "smart"
     * punctuation (curly quotes, en/em dashes, ellipses, non-breaking spaces) and other
     * non-Latin-1 characters that Propertyware rejects with "Invalid value for field data type TEXT".
     * Common offenders are transliterated to plain ASCII; any remaining control or non-Latin-1
     * characters are dropped. Tabs and line breaks are preserved.
     */
    private function sanitizeCustomFieldValue(string $value): string
    {
        $replacements = [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
            "\u{2014}" => '-', "\u{2015}" => '-',
            "\u{2026}" => '...',
            "\u{2022}" => '-', "\u{00B7}" => '-', "\u{2043}" => '-',
            "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{2009}" => ' ', "\u{200A}" => ' ', "\u{202F}" => ' ',
            "\u{200B}" => '', "\u{200C}" => '', "\u{200D}" => '', "\u{FEFF}" => '',
        ];

        $value = strtr($value, $replacements);

        // Drop any remaining characters outside printable Latin-1, but keep tab/newline/carriage return.
        $stripped = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{00FF}]/u', '', $value);

        return $stripped ?? $value;
    }

    /**
     * Translate a Propertyware custom-fields error response into a clear, actionable message
     * for the property owner filling out the onboarding form.
     */
    private function friendlyPropertywareError(?string $body): string
    {
        $details = [];

        if ($body) {
            $decoded = json_decode($body, true);

            if (is_array($decoded) && ! empty($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $error) {
                    if (empty($error['key'])) {
                        continue;
                    }

                    $reason = $this->describeFieldError($error['message'] ?? '');
                    $details[$error['key']] = $reason ? "{$error['key']} ({$reason})" : $error['key'];
                }
            }
        }

        if (! empty($details)) {
            return "We couldn't save the following field(s): ".implode('; ', array_values($details))
                .'. Please correct the field(s) above and submit again.';
        }

        return 'We were unable to save your property information. Please try again in a few minutes, and contact us if the problem continues.';
    }

    /**
     * Turn a raw Propertyware field-level error message into a short, plain-English reason.
     */
    private function describeFieldError(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'required') || str_contains($normalized, 'mandatory') || str_contains($normalized, 'cannot be empty')) {
            return 'this field is required';
        }

        if (str_contains($normalized, 'length') || str_contains($normalized, 'too long')) {
            return 'the entry is too long — please shorten it';
        }

        if (str_contains($normalized, 'data type') || str_contains($normalized, 'invalid value')) {
            return 'the entry is too long or contains special characters/formatting — please shorten it or retype it as plain text';
        }

        return $message !== '' ? $message : 'please review this field';
    }

    private function updateMaintenanceNotice($buildingId, $maintenanceNotice)
    {
        // First get the current building data
        $buildingResponse = $this->getBuilding($buildingId);
        if (! $buildingResponse['success']) {
            return [
                'success' => false,
                'error' => 'Failed to retrieve building data',
            ];
        }

        $buildingData = $buildingResponse['building'];

        // Create building object with all required fields including address
        $updateData = [
            'abbreviation' => $buildingData['abbreviation'],
            'countUnit' => $buildingData['countUnit'],
            'name' => $buildingData['name'],
            'propertyType' => $buildingData['propertyType'],
            'rentable' => $buildingData['rentable'] ?? true,
            'type' => $buildingData['type'],
            'address' => $buildingData['address'] ?? [],
        ];

        // Ensure address has required country field
        if (! isset($updateData['address']['country']) || empty($updateData['address']['country'])) {
            $updateData['address']['country'] = 'United States';
        }

        // Try to update maintenanceNotice field directly using PUT with all required fields
        Log::info('Attempting to update maintenanceNotice field directly', [
            'Building ID' => $buildingId,
            'Maintenance Notice' => $maintenanceNotice,
        ]);

        // Add maintenanceNotice to the update data
        $updateData['maintenanceNotice'] = $maintenanceNotice;

        // Make API call to Propertyware to update the building with maintenanceNotice
        $response = Http::withHeaders([
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
            'Content-Type' => 'application/json',
        ])->put("https://api.propertyware.com/pw/api/rest/v1/buildings/{$buildingId}", $updateData);

        if ($response->successful()) {
            Log::info('Maintenance notice updated successfully in maintenanceNotice field', [
                'Building ID' => $buildingId,
                'Maintenance Notice' => $maintenanceNotice,
            ]);

            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'error' => 'Unable to update maintenanceNotice field: '.$response->body(),
        ];
    }

    private function updatePetFields(int|string $buildingId, array $formData): array
    {
        $petDogAllowed = ($formData['dogsAllowed'] ?? '') === 'Yes';
        $petCatAllowed = ($formData['catsAllowed'] ?? '') === 'Yes';
        $petOtherAllowed = ($formData['petOtherAllowed'] ?? '') === 'Yes';
        $petsAllowed = $petDogAllowed || $petCatAllowed || $petOtherAllowed;

        Log::info('Updating pet fields for building', [
            'Building ID' => $buildingId,
            'petsAllowed' => $petsAllowed,
            'petDogAllowed' => $petDogAllowed,
            'petCatAllowed' => $petCatAllowed,
            'petOtherAllowed' => $petOtherAllowed,
        ]);

        $response = Http::withHeaders([
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
            'Content-Type' => 'application/merge-patch+json',
        ])->patch("https://api.propertyware.com/pw/api/rest/v1/buildings/{$buildingId}", [
            'petsAllowed' => $petsAllowed,
            'petDogAllowed' => $petDogAllowed,
            'petCatAllowed' => $petCatAllowed,
            'petOtherAllowed' => $petOtherAllowed,
        ]);

        if ($response->successful()) {
            Log::info('Pet fields updated successfully', ['Building ID' => $buildingId]);

            return ['success' => true, 'data' => $response->json()];
        }

        Log::error('Failed to update pet fields', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return ['success' => false, 'error' => $response->body()];
    }

    private function formatToUSDisplay(string $phoneNumber): string
    {
        $numericPhoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        return sprintf(
            '(%s) %s-%s',
            substr($numericPhoneNumber, 0, 3), // Takes 3 digits starting from index 1 (e.g., '281')
            substr($numericPhoneNumber, 3, 3), // Takes 3 digits starting from index 4 (e.g., '407')
            substr($numericPhoneNumber, 6, 4)  // Takes 4 digits starting from index 7 (e.g., '3816')
        );
    }
}
