<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

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
            ->filter(request(['search']))
            ->orderBy('name','ASC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'email' => $vendor->user->email,
                    'phone' => $vendor->user->phone,
                    'contact_name' => $vendor->contact_name,
                    'company' => $vendor->company,
                    'address' => $vendor->user->address,
                    'twilio_number' => $vendor->twilio_number,
                    'status' => $vendor->is_active ? true : false,
                ];
            });

        $twilio_numbers = TwilioPhoneNumber::select('id','name','phone_number')->get();
        return inertia('Vendor/Index', [
            'title' => 'Vendors',
            'twilio_numbers' => $twilio_numbers,
            'vendors' => $vendors,
            'filter' => $request->only(['search','per_page']),
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

        DB::transaction(function() use ($data, $request) {
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
            'twilio_number' => 'required',
            'name' => 'required',
            'contact_name' => 'required',
            'email' => 'required',
        ]);

        $userData = $request->validate([
            'name' => 'required',
            'email' => 'required',
            'phone' => '',
            'company' => '',
            'address' => '',           
        ]);

        User::find($vendor->user_id)->update($userData );
        $vendor->update($vendorData);

        return redirect()->back();
    }

    public function update_status(Request $request, Vendor $vendor)
    {
        $request->validate([
            'status' => 'required'
        ]);

        $vendor->update(['is_active' => $request->status ? true : false]);

        return redirect()->back();

    }
}
