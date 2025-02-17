<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use Illuminate\Http\Request;

class TwilioPhoneNumberController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
         // Gate::authorize('view_user', User::class);

        $perPage = $request->per_page
         ? ($request->per_page == 'All' ? TwilioPhoneNumber::count() : $request->per_page)
         : 10;
        $twilio_numbers = TwilioPhoneNumber::query()
                    ->filter(request(['search']))
                    ->latest()
                    ->paginate($perPage)
                    ->withQueryString()
                    ->through(function ($twilio) {
                        return [
                            'id' => $twilio->id,
                            'name' => $twilio->name,
                            'account_sid' => $twilio->account_sid,
                            'sid' => $twilio->sid,
                            'phone_number' => $twilio->phone_number,
                            'sms_application_sid' => $twilio->sms_application_sid,
                            'capabilities' => json_decode($twilio->capabilities),
                            'twilio_status' => $twilio->twilio_status,
                        ];
                    });
        $twilios = TwilioPhoneNumber::get();

        return inertia('Twilio/Index', [
            'title' => 'Twilio Numbers',
            'twilio_numbers' => $twilio_numbers,
            'twilios' => $twilios,
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
    public function show(TwilioPhoneNumber $twilioPhoneNumber)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TwilioPhoneNumber $twilioPhoneNumber)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TwilioPhoneNumber $twilioPhoneNumber)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TwilioPhoneNumber $twilioPhoneNumber)
    {
        //
    }
}
