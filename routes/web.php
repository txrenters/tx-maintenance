<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/user/settings', ProfileController::class)->name('profile.settings');

    Route::resource('/users', UserController::class);
    Route::post('/users/store', [UserController::class,'store'])->name('users.store_');

    Route::resource('/twilio_numbers', TwilioPhoneNumberController::class);
    Route::get('/twilio_numbers/import/twilio_numbers', ImportTwilioNumberController::class)->name('import_twilio_numbers');

});


// Route::prefix('admin/', function(){
//     Route::
// });