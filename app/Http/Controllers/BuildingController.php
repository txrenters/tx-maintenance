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
        $response = Http::get('https://app.propertyware.com/pw/00a/3615817729/JSON?0SurHks&shardKey=182255624');

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
                str_contains(strtolower($record[6] ?? ''), $propertyName) &&
                str_contains(strtolower($record[1] ?? ''), $fullName) &&
                (
                    str_contains($record[2] ?? '', $formattedPhone) ||
                    str_contains($record[3] ?? '', $formattedPhone)
                );
        })->values();

        // Format for front-end
        if ($filtered->isEmpty()) {
            Log::error('No matching building found');

            return response()->json(['error' => 'No matching building found'], 404);
        }

        // Get building ID from index 5
        $buildingId = $filtered->first()[5];

        // Fetch building details

        $response = $this->getBuilding($buildingId);

        if($response['success']){
            return response()->json($response['building'], 200);
        }

        return response()->json(['error' => 'Failed to fetch building details'], 500);
       
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
            'signature' => 'required|string'
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

            // Make API call to Propertyware
            $response = $this->updatePropertywareCustomFields($propertywareData);

            if ($response['success']) {
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
