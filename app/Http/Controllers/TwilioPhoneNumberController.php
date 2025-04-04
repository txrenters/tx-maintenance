<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TwilioPhoneNumberController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('view_twilio_number', TwilioPhoneNumber::class);

        $perPage = $request->per_page
         ? ($request->per_page == 'All' ? TwilioPhoneNumber::count() : $request->per_page)
         : 10;
        $twilio_numbers = TwilioPhoneNumber::query()
            ->filter(request(['search']))
            ->orderBy('name')
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
        $twilios = TwilioPhoneNumber::orderBy('name')->get();

        return inertia('Twilio/Index', [
            'title' => 'Twilio Numbers',
            'twilio_numbers' => $twilio_numbers,
            'twilios' => $twilios,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }
}
