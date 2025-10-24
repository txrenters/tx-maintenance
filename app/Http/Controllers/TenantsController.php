<?php

namespace App\Http\Controllers;

use App\Models\Tenants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TenantsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('view_tenants', Tenants::class);

        $tenants = Tenants::query()
            ->with('user')
            ->distinct(['first_name', 'last_name', 'email'])
            ->filter(request(['search']))
            ->orderBy('first_name', 'ASC')
            ->paginate(100)
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
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    public function destroy(Tenants $tenant)
    {
        $tenant->delete();

        return redirect()->back();
    }

    public function bulkdelete(Request $request)
    {
        Tenants::whereIn('id', $request->tenantsId)->delete();

        return redirect()->back();

    }
}
