<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
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

    Route::resource('/vendors', VendorController::class);

    Route::resource('/service_status', ServiceStatusController::class);


    Route::get('/work', function(){

        try {
            $client = new \SoapClient(config('services.propertyware.url'), [
                'cache_wsdl' => 0,
                'trace' => 1,
                'login' => config('services.propertyware.username'),
                'password' => config('services.propertyware.password'),
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ])
            ]);
        
            $page = 1;
            $allWorkOrders = [];
        
            do {
                $params = [
                    'pageNumber' => $page,
                    'orderByNewestFirst' => 1,
                ];
        
                $response = $client->getWorkOrders($params);
                
                // Adjust based on response structure
                $workOrders = isset($response->workOrders->WorkOrder) ? (array)$response->workOrders->WorkOrder : [];
        
                // Merge all work orders
                $allWorkOrders = array_merge($allWorkOrders, $workOrders);
        
                // If no work orders, stop the loop
                $hasMorePages = count($workOrders) > 0;
                $page++;
        
            } while ($hasMorePages);
        
            dd($allWorkOrders); // Debug output
        
        } catch (\SoapFault $e) {
            dd('SOAP Error: ' . $e->getMessage());
        }
        


    });


    Route::get('/convo', function(){

        $account_sid  = env('TWILIO_SID');
        $auth_token  = env('TWILIO_AUTH_TOKEN');

        $client = new Client($account_sid, $auth_token);

        $messages = $client->messages->read([], 100);

        foreach ($messages as $message) {
            echo "From: " . $message->from . " - Message: " . $message->body . "\n";
        }

    });
    Route::get('/getvendors', function(){

        $url = config('services.propertyware.url');
            $userName = config('services.propertyware.username');
            $password = config('services.propertyware.password');
            $params = array(
                'pageNumber' => 1,
                'orderByNewestFirst' => 1,
            );
            $options = array(
                'cache_wsdl' => 0,
                'trace' => 1,
                'login' => $userName,
                'password' => $password,
                'stream_context' => stream_context_create(array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                ))
            );

            $client = new \SoapClient($url, $options);
            $response = $client->getWorkOrders($params);

            $orders = (array)$response;

            dd($orders);

    });

});


// Route::prefix('admin/', function(){
//     Route::
// });