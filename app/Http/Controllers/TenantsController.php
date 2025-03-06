<?php

namespace App\Http\Controllers;

use App\Models\Tenants;
use App\Http\Requests\StoreTenantsRequest;
use App\Http\Requests\UpdateTenantsRequest;
use Illuminate\Http\Request;

class TenantsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
         // Gate::authorize('view_user', User::class);
         $perPage = $request->per_page
         ? ($request->per_page == 'All' ? Tenants::count() : $request->per_page)
         : 10;
 
         $tenants = Tenants::query()
             ->with('user')
             ->filter(request(['search']))
             ->orderBy('first_name','ASC')
             ->paginate($perPage)
             ->withQueryString()
             ->through(function ($tenant) {
                 return [
                     'id' => $tenant->id,
                     'name' => $tenant->first_name.' '.$tenant->last_name,
                     'email' => $tenant->email,
                     'mobile_phone' => $tenant->mobile_phone,
                     'home_phone' => $tenant->home_phone,
                     'company' => $tenant->company,
                     'address' => $tenant->user->address,
                 ];
             });
 
         return inertia('Tenant/Index', [
             'title' => 'Tenants',
             'tenants' => $tenants,
             'filter' => $request->only(['search','per_page']),
         ]);
    }
}
