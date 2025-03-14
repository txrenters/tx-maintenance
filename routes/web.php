<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TenantsController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WOCNumbersController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;

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
    Route::get('/twilio_numbers/import/numbers', ImportTwilioNumberController::class)->name('import_twilio_numbers');

    Route::resource('/woc_numbers', WOCNumbersController::class);

    Route::resource('/vendors', VendorController::class);
    Route::put('/vendors/change_status/{vendor}', [VendorController::class, 'update_status'])->name('vendors.change_status');

    Route::resource('/owners', OwnerController::class);

    Route::resource('/tenants', TenantsController::class);

    Route::resource('/service_status', ServiceStatusController::class);
    Route::resource('/work_orders', WorkOrderController::class);
    Route::get('/work_orders/closed/done', [WorkOrderController::class, 'closed_work_orders'])->name('work_orders.closed_work_orders');
    Route::put('/work_orders/{workOrder}/close', [WorkOrderController::class, 'close'])->name('work_orders.close');
    Route::put('/work_orders/{workOrder}/open', [WorkOrderController::class, 'open'])->name('work_orders.open');
    Route::put('/work_orders/{workOrder}/emergency', [WorkOrderController::class, 'emergency_change'])->name('work_orders.emergency.change');
    Route::resource('/task_templates', TaskTemplateController::class);


    Route::get('/convo', function(){

        $account_sid  = env('TWILIO_SID');
        $auth_token  = env('TWILIO_AUTH_TOKEN');

        $client = new Client($account_sid, $auth_token);

        $messages = $client->messages->read([], 100);

        foreach ($messages as $message) {
            echo "From: " . $message->from . " - Message: " . $message->body . "\n";
        }

    });

});


// Route::prefix('admin/', function(){
//     Route::
// });