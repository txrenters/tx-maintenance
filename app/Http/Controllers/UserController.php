<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Gate::authorize('view_user', User::class);

        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? User::count() : $request->per_page)
        : 10;
        $users = User::query()
            ->filter(request(['search']))
            ->latest()
            ->whereNot('id', auth()->id())
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'company' => $user->company,
                    'website' => $user->website,
                    'address' => $user->address,
                    'profile_photo_url' => $user->profile_photo_url,
                    'role_id' => $user->roles->first()->id ?? null,
                    'role' => $user->getRoleNames()[0],
                ];
            });

        $roles = Role::select('id', 'name')->get();

        return inertia('User/Index', [
            'title' => 'Users',
            'users' => $users,
            'roles' => $roles,
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
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
