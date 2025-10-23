<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OwnerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('view_owners', Owner::class);

        $owners = Owner::query()
            ->with('user')
            ->filter(request(['search']))
            ->distinct(['name', 'email', 'phone'])
            ->orderBy('name', 'ASC')
            ->paginate(100)
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

    public function destroy(Owner $owner)
    {
        $owner->delete();

        return redirect()->back();
    }

    public function bulkdelete(Request $request)
    {
        Owner::whereIn('id', $request->ownersId)->delete();

        return redirect()->back();

    }
}
