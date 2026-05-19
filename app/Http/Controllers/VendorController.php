<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTypes;
use App\Services\PropertyWareService;
use App\Services\VendorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class VendorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('view_vendors', Vendor::class);

        $vendors = Vendor::query()
            ->with(['user'])
            ->filter(request(['search', 'status']))
            ->orderBy('is_active', 'DESC')
            ->orderBy('name', 'ASC')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'email' => $vendor->user->email,
                    'phone' => $vendor->user->phone,
                    'vendor_type' => $vendor->vendor_type,
                    'name_on_check' => $vendor->name_on_check,
                    'address' => $vendor->user->address,
                    'twilio_number' => $vendor->twilio_number,
                    'zones' => $vendor->zones ?? [],
                    'status' => $vendor->is_active ? true : false,
                ];
            });

        $twilio_numbers = TwilioPhoneNumber::select('id', 'name', 'phone_number')->get();
        $vendorTypes = VendorTypes::select('id', 'name')->orderBy('name', 'ASC')->get();

        return inertia('Vendor/Index', [
            'title' => 'Vendors',
            'twilio_numbers' => $twilio_numbers,
            'vendors' => $vendors,
            'vendorTypes' => $vendorTypes,
            'filter' => $request->only(['search', 'per_page', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create_vendor', Vendor::class);

        $data = $request->validate([
            'name' => 'required',
            'email' => 'required',
            'phone' => '',
            'company' => '',
            'website' => '',
            'address' => '',
        ]);

        $data['email_verified_at'] = now();
        $data['password'] = bcrypt($request->email);

        DB::transaction(function () use ($data, $request) {

            $user = User::create($data);

            $user->assignRole('vendor');

            $vendorsData = [
                'propertyware_id' => $user->id,
                'name' => $user->name,
                'contact_name' => $request->contact_name,
                'email' => $user->email,
                'twilio_number' => $request->twilio_number,
                'user_id' => $user->id,
                'is_active' => true,
            ];

            Vendor::create($vendorsData);

        });

        return redirect()->route('vendors.index');

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Vendor $vendor)
    {
        Gate::authorize('update_vendor', Vendor::class);

        $vendorData = $request->validate([
            'twilio_number' => '',
            'name' => 'required',
            'vendor_type' => '',
            'name_on_check' => '',
            'email' => 'required',
            'zones' => 'nullable|array',
            'zones.*' => 'string|max:50',
        ]);

        $vendorData['zones'] = empty($vendorData['zones'] ?? null) ? null : array_values(array_unique($vendorData['zones']));

        $userData = $request->validate([
            'name' => 'required',
            'email' => 'required',
            'phone' => '',
            'company' => '',
            'address' => '',
        ]);

        User::find($vendor->user_id)->update($userData);
        $vendor->update($vendorData);

        return redirect()->back();
    }

    public function update_status(Request $request, Vendor $vendor)
    {
        Gate::authorize('update_vendor', Vendor::class);

        $request->validate([
            'status' => 'required',
        ]);

        $vendor->update(['is_active' => $request->status ? true : false]);

        return redirect()->back();

    }

    public function import(Request $request)
    {
        Gate::authorize('create_vendor', Vendor::class);

        $request->validate([
            'vendors_name' => 'required|string',
        ]);

        $vendorName = trim($request->vendors_name);

        $vendorExists = Vendor::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($vendorName)])->exists();

        if (! $vendorExists) {
            $propertyWare = new PropertyWareService;

            $vendors = $propertyWare->getVendorsByName($vendorName);

            if ($vendors) {
                $vendorService = new VendorService;
                $vendorService->handle($vendors);

                return response()->json([
                    'status' => true,
                    'message' => 'Vendors imported successfully.',
                ], 200);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'No vendors found.',
                ], 422);
            }
        }

        return response()->json([
            'status' => false,
            'message' => 'Vendor already exists.',
        ], 422);
    }
}
