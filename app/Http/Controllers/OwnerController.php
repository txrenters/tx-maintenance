<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOwnerRequest;
use App\Http\Requests\UpdateOwnerRequest;
use App\Models\Owner;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Gate::authorize('view_user', User::class);
        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? Owner::count() : $request->per_page)
        : 10;

        $owners = Owner::query()
            ->with('user')
            ->filter(request(['search']))
            ->orderBy('name', 'ASC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($owner) {
                return [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'mobile' => $owner->mobile,
                    'phone' => $owner->phone,
                    'name_on_check' => $owner->name_on_check,
                    'company' => $owner->company,
                    'address' => $owner->user->address,
                    'status' => $owner->status,
                ];
            });

        return inertia('Owner/Index', [
            'title' => 'Owners',
            'owners' => $owners,
            'filter' => $request->only(['search', 'per_page']),
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
    public function store(StoreOwnerRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Owner $owner)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Owner $owner)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOwnerRequest $request, Owner $owner)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Owner $owner)
    {
        //
    }
}
