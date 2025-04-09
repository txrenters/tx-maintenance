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

class VendorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Gate::authorize('view_user', User::class);
        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? Vendor::count() : $request->per_page)
        : 10;

        $vendors = Vendor::query()
            ->with(['user'])
            ->filter(request(['search', 'status']))
            ->orderBy('is_active', 'DESC')
            ->orderBy('name', 'ASC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'email' => $vendor->user->email,
                    'phone' => $vendor->user->phone,
                    'vendor_type' => $vendor->vendor_type,
                    'name_on_check' => $vendor->name_on_check,
                    'company' => $vendor->company,
                    'address' => $vendor->user->address,
                    'twilio_number' => $vendor->twilio_number,
                    'status' => $vendor->is_active ? true : false,
                ];
            });

        $twilio_numbers = TwilioPhoneNumber::select('id', 'name', 'phone_number')->get();
        $vendorTypes = VendorTypes::select('id', 'name')->orderBy('name','ASC')->get();

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
        $vendorData = $request->validate([
            'twilio_number' => '',
            'name' => 'required',
            'vendor_type' => '',
            'name_on_check' => '',
            'email' => 'required',
        ]);

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
        $request->validate([
            'status' => 'required',
        ]);

        $vendor->update(['is_active' => $request->status ? true : false]);

        return redirect()->back();

    }

    public function import(Request $request)
    {
        $request->validate([
            'vendors_name' => 'required|array',
        ]);

       foreach($request->vendors_name as $vendorName){
            $vendorName = trim(rtrim($vendorName, ','));

            $vendorName = $this->addCommaBeforeLLC($vendorName);

            $vendorExists = Vendor::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($vendorName)])->exists();

            if ($vendorExists) {
                continue; // Skip if vendor already exists
            }

            $propertyWare = new PropertyWareService();

            $vendors = $propertyWare->getVendorsByName($vendorName);

            if($vendors){
                $vendorService = new VendorService();
                $vendorService->handle($vendors);
            }

       }


        return redirect()->back()->with('success', 'Vendors imported successfully.');
    }

    function addCommaBeforeLLC($vendorName) {
        // Trim whitespace from both ends
        $vendorName = trim($vendorName);
        
        // Remove spaces before existing commas
        $vendorName = preg_replace('/\s*,/', ',', $vendorName);
        
        // Case-insensitive check for LLC variants at the end
        if (preg_match('/\b(llc|l\.l\.c\.?)\s*$/i', $vendorName) && 
            !preg_match('/,\s*(llc|l\.l\.c\.?)\s*$/i', $vendorName)) {
            // Add comma before the suffix
            $vendorName = preg_replace('/\s*\b(llc|l\.l\.c\.?)\s*$/i', ', $1', $vendorName);
        }
        
        return $vendorName;
    }
    
    
}
