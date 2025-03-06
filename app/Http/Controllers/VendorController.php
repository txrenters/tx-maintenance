<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use App\Models\Vendor;
use Illuminate\Http\Request;

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
                    'name_on_check' => $vendor->name_on_check,
                    'company' => $vendor->company,
                    'vendor_type' => $vendor->vendor_type,
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
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Vendor $vendor)
    {
        $request->validate([
            'twilio_number' => 'required'
        ]);

        $vendor->update(['twilio_number' => $request->twilio_number]);

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
