<?php

namespace App\Http\Controllers;

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
            ->with('user')
            ->filter(request(['search']))
            ->latest()
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
                    'status' => $vendor->is_active ? "true" : false,
                ];
            });

        return inertia('Vendor/Index', [
            'title' => 'Vendors',
            'vendors' => $vendors,
            'filter' => $request->only(['search','per_page']),
        ]);


    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Vendor $vendor)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Vendor $vendor)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Vendor $vendor)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Vendor $vendor)
    {
        //
    }
}
