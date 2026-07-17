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

    public function show(Request $request, Vendor $vendor)
    {
        Gate::authorize('view_vendors', Vendor::class);

        $vendor->load('user');

        $workOrders = $vendor->workOrders()
            ->with('service_status')
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($workOrder) => [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'description' => $workOrder->description,
                'location' => $workOrder->location,
                'priority' => $workOrder->priority,
                'status' => $workOrder->status,
                'service_status' => $workOrder->service_status?->name,
                'created_date' => $workOrder->created_date,
                'completed_date' => $workOrder->completed_date,
                'cost_estimate' => $workOrder->pivot->cost_estimate,
                'scheduled_end_date' => $workOrder->pivot->scheduled_end_date,
            ]);

        $emailHistory = $vendor->emailMessages()
            ->with('work_order:id,work_order_no')
            ->latest('emailed_at')
            ->latest('id')
            ->get()
            ->map(fn ($email) => [
                'id' => $email->id,
                'direction' => $email->direction,
                'subject' => $email->subject,
                'body_text' => $email->body_text,
                'from_email' => $email->from_email,
                'to_email' => $email->to_email,
                'cc' => $email->cc ?? [],
                'emailed_at' => $email->emailed_at?->toIso8601String(),
                'work_order' => $email->work_order ? [
                    'id' => $email->work_order->id,
                    'work_order_no' => $email->work_order->work_order_no,
                ] : null,
            ]);

        return inertia('Vendor/Show', [
            'title' => $vendor->name,
            'senderEmail' => (string) $request->user()->email,
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'email' => $vendor->email,
                'phone' => $vendor->user?->phone,
                'company' => $vendor->user?->company,
                'address' => $vendor->user?->address,
                'vendor_type' => $vendor->vendor_type,
                'name_on_check' => $vendor->name_on_check,
                'twilio_number' => $vendor->twilio_number,
                'is_active' => (bool) $vendor->is_active,
                'propertyware_id' => $vendor->propertyware_id,
                'zones' => $vendor->zones ?? [],
            ],
            'workOrders' => $workOrders,
            'emailHistory' => $emailHistory,
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

        // This app uses email-as-password (as noted in the edit dialog), so keep
        // the password in sync whenever the email changes.
        $userData['password'] = bcrypt($userData['email']);

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

        $propertyWare = new PropertyWareService;

        // Find the vendor (and its PropertyWare ID) by name.
        $match = $propertyWare->getVendorsByName($vendorName);

        if (is_array($match) && isset($match[0])) {
            $match = $match[0];
        }

        $pwId = $match['ID'] ?? $match['id'] ?? null;

        if (! $pwId) {
            return response()->json([
                'status' => false,
                'message' => 'No vendors found.',
            ], 422);
        }

        // Pull the full, fresh record from the single-vendor REST endpoint so the
        // imported/synced data is complete and current.
        $vendorData = $propertyWare->getVendor($pwId) ?? $match;

        $vendor = (new VendorService)->handle($vendorData);

        if (! $vendor) {
            return response()->json([
                'status' => false,
                'message' => 'Could not import the vendor. Please try again.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Vendor synced successfully.',
        ], 200);
    }

    /**
     * Re-sync a single existing vendor's data from PropertyWare using its stored ID.
     */
    public function sync(Vendor $vendor)
    {
        Gate::authorize('update_vendor', Vendor::class);

        if (! $vendor->propertyware_id) {
            return response()->json([
                'status' => false,
                'message' => 'This vendor has no PropertyWare ID to sync.',
            ], 422);
        }

        $vendorData = (new PropertyWareService)->getVendor($vendor->propertyware_id);

        if (! $vendorData) {
            return response()->json([
                'status' => false,
                'message' => 'Could not fetch this vendor from PropertyWare.',
            ], 422);
        }

        $synced = (new VendorService)->handle($vendorData);

        if (! $synced) {
            return response()->json([
                'status' => false,
                'message' => 'Could not sync the vendor. Please try again.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Vendor synced from PropertyWare.',
        ], 200);
    }
}
