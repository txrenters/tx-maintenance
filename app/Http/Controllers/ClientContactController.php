<?php

namespace App\Http\Controllers;

use App\Models\ClientContact;
use App\Models\Jobber;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClientContactController extends Controller
{
    /**
     * Get all client contacts for a specific job
     */
    public function index(Jobber $jobber): JsonResponse
    {
        $contacts = $jobber->clientContacts()
            ->orderBy('is_primary', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($contacts);
    }

    /**
     * Store multiple client contacts
     */
    public function store(Request $request, Jobber $jobber): JsonResponse
    {
        $request->validate([
            'contacts' => 'required|array',
            'contacts.*.name' => 'required|string|max:255',
            'contacts.*.phone' => 'required|string|max:50',
        ]);

        // Delete existing contacts for this job
        $jobber->clientContacts()->delete();

        // Create new contacts
        $contacts = collect($request->contacts)->map(function ($contact, $index) use ($jobber) {
            return $jobber->clientContacts()->create([
                'name' => $contact['name'],
                'phone' => $contact['phone'],
                'is_primary' => $index === 0, // First contact is primary
            ]);
        });

        return response()->json([
            'success' => true,
            'contacts' => $contacts,
        ]);
    }

    /**
     * Delete a specific contact
     */
    public function destroy(ClientContact $clientContact): JsonResponse
    {
        $clientContact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully',
        ]);
    }
}