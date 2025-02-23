<?php

namespace App\Http\Controllers;

use App\Models\WOCNumbers;
use App\Http\Requests\StoreWOCNumbersRequest;
use App\Http\Requests\UpdateWOCNumbersRequest;
use App\Models\TwilioPhoneNumber;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WOCNumbersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
         Gate::authorize('view_woc_user', WOCNumbers::class);

         $perPage = $request->per_page
         ? ($request->per_page == 'All' ? WOCNumbers::count() : $request->per_page)
         : 10;

        $woc_numbers = WOCNumbers::query()
                    ->filter(request(['search']))
                    ->with(['user','twilioPhoneNumber'])
                    ->latest()
                    ->paginate($perPage)
                    ->withQueryString()
                    ->through(function ($number) {
                        return [
                            'id' => $number->id,
                            'woc_name' => $number->user->name,
                            'user_id' => $number->user->id,
                            'twilio_phone_number' => $number->twilioPhoneNumber->phone_number,
                            'twilio_phone_number_id' => $number->twilioPhoneNumber->id,
                        ];
                    });

        $woc_users = User::role('woc')->get();
        $twilio_numbers = TwilioPhoneNumber::select('id','name','phone_number')->get();

        return inertia('WOCNumber/Index', [
            'title' => 'Twilio Numbers',
            'woc_numbers' => $woc_numbers,
            'woc_users' => $woc_users,
            'twilio_numbers' => $twilio_numbers,
            'filter' => $request->only(['search','per_page']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWOCNumbersRequest $request)
    {
        Gate::authorize('create_woc_user', WOCNumbers::class);

        WOCNumbers::create($request->validated());

        return redirect()->route('woc_numbers.index');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWOCNumbersRequest $request, WOCNumbers $wOCNumbers)
    {
        Gate::authorize('update_woc_user', WOCNumbers::class);

        $wOCNumbers->update($request->validated());

        return redirect()->route('woc_numbers.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WOCNumbers $wOCNumbers)
    {
        Gate::authorize('delete_woc_user', WOCNumbers::class);

        $wOCNumbers->delete();

        return redirect()->route('woc_numbers.index');
    }
}
