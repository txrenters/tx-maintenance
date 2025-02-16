<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
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
                    'role_id' => optional($user->roles->first())->id,
                    'role' => $user->getRoleNames()->first(),
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
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        // Gate::authorize('create_user', User::class);        
        $request->validated();

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'company' => $request->company,
            'website' => $request->website,
            'address' => $request->address,
            'email_verified_at' => now(),
            'password' => bcrypt($request->email),
        ];

        DB::transaction(function() use ($data, $request) {
            $user = User::create($data);

            $role = Role::find($request->role_id);
            if ($role) {
                $user->assignRole($role);
            }        
        
        });

        return redirect()->back();
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        // Gate::authorize('update_user', User::class);

        $request->validated();

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'company' => $request->company,
            'website' => $request->website,
            'address' => $request->address,
        ];

        DB::transaction(function() use ($data, $user, $request) {
            $user->update($data);
            $role = Role::find($request->role_id);
            if ($role) {
                $user->syncRoles($role);
            }      
        });

    
        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->back();
    }
}
