<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __invoke(Request $request)
    {
        return inertia('Profile/Account', [
            'title' => 'User Profile',
            'user' => $request->user(),
        ]);
    }
}
