<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateOnboardingPdfJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BuildingController extends Controller
{
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
            Log::info('Searching Building:' ,['building' => $buildingId]);

            $response = $this->getBuilding($buildingId);
            

            if ($response['success']) {
                $buildings[] = $response['building'];
                Log::info('Building found:' ,['building' => $response['building']]);
            }
        }

        if (empty($buildings)) {
            return response()->json(['error' => 'Failed to fetch building details'], 500);
        }

        // Return all matching buildings
        return response()->json(['buildings' => $buildings, 'count' => count($buildings)], 200);
       
    }

    public function updateCustomFields(Request $request, $buildingId)
    {
        $request->validate([
            'propertywareData' => 'required|array',
            'propertywareData.entityId' => 'required|integer',
            'propertywareData.fieldSetDTOS' => 'required|array',
            'propertywareData.fieldSetDTOS.*.name' => 'required|string',
            'propertywareData.fieldSetDTOS.*.value' => 'required|string',
            'formData' => 'required|array',
            'signature' => 'required|string',
            'maintenanceNotice' => 'nullable|string'
        ]);

        try {
            $propertywareData = $request->input('propertywareData');
            
            // Log the update attempt
            Log::info('Updating custom fields for building: ' . $buildingId, [
                'fields_count' => count($propertywareData['fieldSetDTOS']),
                'fields' => array_map(function($field) {
                    return $field['name'] . ' => ' . $field['value'];
                }, $propertywareData['fieldSetDTOS'])
            ]);

            // Make API call to Propertyware for custom fields
            $response = $this->updatePropertywareCustomFields($propertywareData);

            if ($response['success']) {
                // Update maintenanceNotice field if provided
                $maintenanceNotice = $request->input('maintenanceNotice');
                if ($maintenanceNotice !== null) {
                    $maintenanceResponse = $this->updateMaintenanceNotice($buildingId, $maintenanceNotice);
                    if (!$maintenanceResponse['success']) {
                        Log::warning('Failed to update maintenance notice', [
                            'Building ID' => $buildingId,
                            'Error' => $maintenanceResponse['error']
                        ]);
                    }
                }
                
                $signature = $request->input('signature');
                $formData = $request->input('formData');
                $ownerName = $request->input('ownerName');
                
                // Get building details for PDF
                $buildingResponse = $this->getBuilding($buildingId);
                if ($buildingResponse['success']) {
                    $buildingData = $buildingResponse['building'];
                    
                    // Dispatch PDF generation job
                    GenerateOnboardingPdfJob::dispatch($signature, $formData, $buildingData, $propertywareData, $ownerName)->delay(now()->addSeconds(5));
                    
                    Log::info('Property has been updated successfully', [
                        'Building ID' => $buildingId,
                        'Fields updated' => count($propertywareData['fieldSetDTOS'])
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Building information updated successfully',
                    'updated_fields' => array_column($propertywareData['fieldSetDTOS'], 'name'),
                    'total_updated' => count($propertywareData['fieldSetDTOS'])
                ]);
            } else {
                throw new \Exception('Propertyware API update failed');
            }

        } catch (\Exception $e) {
            Log::error('Failed to update custom fields: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to update building information',
                'message' => $e->getMessage()
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
        // Make API call to Propertyware
        $response = Http::withHeaders([
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
            'Content-Type' => 'application/json'
        ])->put('https://api.propertyware.com/pw/api/rest/v1/buildings/customfields', $data);

        if ($response->successful()) {

            return [
                'success' => true,
                'data' => $response->json()
            ];
        }

        Log::error('Propertyware API Error', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        return [
            'success' => false,
            'error' => $response->body()
        ];
    }

    private function updateMaintenanceNotice($buildingId, $maintenanceNotice)
    {
        // First get the current building data
        $buildingResponse = $this->getBuilding($buildingId);
        if (!$buildingResponse['success']) {
            return [
                'success' => false,
                'error' => 'Failed to retrieve building data'
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
            'address' => $buildingData['address'] ?? []
        ];
        
        // Ensure address has required country field
        if (!isset($updateData['address']['country']) || empty($updateData['address']['country'])) {
            $updateData['address']['country'] = 'United States';
        }
        
        // Try to update maintenanceNotice field directly using PUT with all required fields
        Log::info('Attempting to update maintenanceNotice field directly', [
            'Building ID' => $buildingId,
            'Maintenance Notice' => $maintenanceNotice
        ]);

        // Add maintenanceNotice to the update data
        $updateData['maintenanceNotice'] = $maintenanceNotice;

        // Make API call to Propertyware to update the building with maintenanceNotice
        $response = Http::withHeaders([
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
            'Content-Type' => 'application/json'
        ])->put("https://api.propertyware.com/pw/api/rest/v1/buildings/{$buildingId}", $updateData);

        if ($response->successful()) {
            Log::info('Maintenance notice updated successfully in maintenanceNotice field', [
                'Building ID' => $buildingId,
                'Maintenance Notice' => $maintenanceNotice
            ]);
            
            return [
                'success' => true,
                'data' => $response->json()
            ];
        }

        return [
            'success' => false,
            'error' => 'Unable to update maintenanceNotice field: ' . $response->body()
        ];
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
