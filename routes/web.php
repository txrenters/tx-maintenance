<?php

use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationLogsController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\InspectionVisitController;
use App\Http\Controllers\InvoiceController as ControllersInvoiceController;
use App\Http\Controllers\JobberAuthController;
use App\Http\Controllers\JobberDiagnosticController;
use App\Http\Controllers\JobberTextMessageController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\TaskController as ControllersTaskController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TenantsController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorNotesController;
use App\Http\Controllers\WOCNumbersController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkOrderNotesController;
use App\Models\JobberToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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
    Route::post('/users/store', [UserController::class, 'store'])->name('users.store_');

    Route::resource('/twilio_numbers', TwilioPhoneNumberController::class);
    Route::get('/twilio_numbers/import/numbers', ImportTwilioNumberController::class)->name('import_twilio_numbers');

    Route::resource('/woc_numbers', WOCNumbersController::class);

    Route::resource('/vendors', VendorController::class);
    Route::put('/vendors/change_status/{vendor}', [VendorController::class, 'update_status'])->name('vendors.change_status');
    Route::post('/vendors_import', [VendorController::class, 'import'])->name('vendors.import');

    Route::resource('/owners', OwnerController::class);
    Route::post('/owners/bulkdelete', [OwnerController::class, 'bulkdelete'])->name('owners.bulkdelete');

    Route::resource('/tenants', TenantsController::class);
    Route::post('/tenants/bulkdelete', [TenantsController::class, 'bulkdelete'])->name('tenants.bulkdelete');

    Route::resource('/service_status', ServiceStatusController::class);

    Route::resource('/work_orders', WorkOrderController::class);
    Route::get('/work_orders/closed/done', [WorkOrderController::class, 'closed_work_orders'])->name('work_orders.closed_work_orders');
    Route::get('/work_orders/{workOrder}/details', [WorkOrderController::class, 'details'])->name('work_orders.details');
    Route::get('/work_orders/{workOrder}/report', [WorkOrderController::class, 'report'])->name('work_orders.report');
    Route::put('/work_orders/{workOrder}/close', [WorkOrderController::class, 'close'])->name('work_orders.close');
    Route::put('/work_orders/{workOrder}/open', [WorkOrderController::class, 'open'])->name('work_orders.open');
    Route::put('/work_orders/{workOrder}/emergency', [WorkOrderController::class, 'emergency_change'])->name('work_orders.emergency.change');
    Route::put('/work_orders/{workOrder}/vendors', [WorkOrderController::class, 'vendor_change'])->name('work_orders.vendor.change');
    Route::post('/work_orders/import', [WorkOrderController::class, 'import'])->name('work_orders.import');
    Route::get('/work_orders/export/all', [WorkOrderController::class, 'export'])->name('work_orders.export');

    Route::get('/work_orders/coordinators/all', [CoordinatorController::class, 'index'])->name('work_orders.coordinators');
    Route::patch('/work_orders/coordinators/{workOrder}/change', [CoordinatorController::class, 'update'])->name('work_orders.coordinators.change');

    Route::resource('/inspections', InspectionController::class);
    Route::get('/jobber-connect', [InspectionController::class, 'redirectToJobber'])->name('jobber.connect');
    Route::get('/visits', [InspectionVisitController::class, 'index'])->name('visits.index');
    Route::get('/search-client', [InspectionController::class, 'searchClient'])->name('jobber.searchClient');
    Route::post('/save-client', [InspectionController::class, 'saveClient'])->name('jobber.saveClient');
    Route::get('/inspections/{job}/details', [InspectionController::class, 'jobDetails'])->name('jobber.jobDetails');
    Route::get('/inspections/text/messages', [InspectionController::class, 'messages'])->name('jobber.messages');

    Route::resource('/jobber-text-messages', JobberTextMessageController::class);

    Route::get('/conversation-logs', [ConversationLogsController::class, 'index'])->name('conversation_logs.index');

    Route::resource('/task_templates', TaskTemplateController::class);

    Route::get('/scheduled_service', [CalendarController::class, 'index'])->name('scheduled_service');

    Route::resource('/tasks', ControllersTaskController::class);

    Route::get('/tasks/{workOrder}/work_order_task', [TaskController::class, 'tasks'])->name('api.work_order.tasks');

    Route::get('/attachments/{workOrder}', [AttachmentsController::class, 'show'])->name('api.attachments.show');

    Route::get('/invoices/{workOrder}', [InvoiceController::class, 'index'])->name('api.invoices.index');

    Route::post('/attachments', [AttachmentsController::class, 'store'])->name('api.attachments.store');
    Route::post('/attachments/multiple', [AttachmentsController::class, 'multiple_store'])->name('api.attachments.multiple_store');

    Route::delete('/attachments/{attachment}', [AttachmentsController::class, 'destroy'])->name('api.attachments.destroy');

    Route::get('/work_order/invoices', [ControllersInvoiceController::class, 'index'])->name('invoices.index');

    Route::post('/invoices', [InvoiceController::class, 'store'])->name('api.invoices.store');
    Route::post('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('api.invoices.update');

    Route::get('/notes/{workOrder}/show', [WorkOrderNotesController::class, 'getNotes'])->name('api.work_order_notes.show');
    Route::post('/notes', [WorkOrderNotesController::class, 'store'])->name('api.work_order_notes.store');
    Route::delete('/notes/{note}/', [WorkOrderNotesController::class, 'destroy'])->name('api.work_order_notes.destroy');

    Route::post('/vendor_work_order_details', [VendorNotesController::class, 'update'])->name('api.vendor_work_order_details.update');

    // Guide routes
    Route::get('/guide/vendor', function () {
        $user = auth()->user();
        if (! $user->hasRole('vendor')) {
            abort(403, 'Unauthorized');
        }

        return inertia('Guide/VendorGuide');
    })->name('guide.vendor');

    Route::get('/guide/woc', function () {
        $user = auth()->user();
        if (! $user->hasRole('woc')) {
            abort(403, 'Unauthorized');
        }

        return inertia('Guide/WocGuide');
    })->name('guide.woc');

    Route::get('/guide/admin', function () {
        $user = auth()->user();
        if (! $user->hasRole('admin')) {
            abort(403, 'Unauthorized');
        }

        return inertia('Guide/AdminGuide');
    })->name('guide.admin');
});

Route::get('/onboarding/building', [BuildingController::class, 'create'])->name('building.create');

Route::get('/conversations/{workOrder}', [ConversationController::class, 'show'])->name('conversation.show');

Route::get('/jobber/callback', [JobberAuthController::class, 'handleCallback'])->name('jobber.callback');
Route::get('/jobber/reconnect', [JobberAuthController::class, 'refreshAccessToken'])->name('jobber.reconnect');

// Jobber diagnostic routes
Route::get('/jobber/diagnose', [JobberDiagnosticController::class, 'diagnose'])->name('jobber.diagnose');
Route::post('/jobber/clear-tokens', [JobberDiagnosticController::class, 'clearTokens'])->name('jobber.clearTokens');

Route::fallback(function () {
    return inertia('Error', ['status' => 404])
        ->toResponse(request())
        ->setStatusCode(404);
});

Route::get('jobber', function () {
    $existingToken = JobberToken::find(1);

    $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $existingToken->refresh_token,
        'client_id' => env('JOBBER_CLIENT_ID'),
        'client_secret' => env('JOBBER_SECRET'),
    ]);

    $newData = $response->json();

    $existingToken->update([
        'access_token' => $newData['access_token'],
        'refresh_token' => $newData['refresh_token'], // Jobber usually gives a new one
    ]);
});

Route::get('lofty', function () {

    // $response = Http::withHeaders([
    //     'Authorization' => 'token eyJhbGciOiJIUzI1NiJ9.eyJleHQiOjMzMTg2Nzg1Mjk2OTMsInVzZXJfaWQiOjg0NDc2NzIwMDg5NjI3OCwic2NvcGUiOiI1IiwiaWF0IjoxNzQxODc4NTI5NjkzfQ.CwelU10RiIOmcd3NaRX2r83oMuKMBurfx6wwKV2XIYM',
    //     'Content-Type'  => 'application/json',
    // ])->get('https://api.lofty.com/v1.0/webhooks');

    // $response = Http::withHeaders([
    //     'Authorization' => 'token eyJhbGciOiJIUzI1NiJ9.eyJleHQiOjMzMzE5MTcwMDk4MTQsInVzZXJfaWQiOjg0NDc2OTk4NjczMzMyOSwic2NvcGUiOiI1IiwiaWF0IjoxNzU1MTE3MDA5ODE0fQ.tth0iTRGjkcpOXVN1KYXk7oHjWqJ8Y1dn_NqLaoBosI',
    //     'Content-Type'  => 'application/json',
    // ])->post('https://api.lofty.com/v1.0/webhook', [
    //     "listId" => 2,
    //     "callbackUrl" => "https://n8n.srv902502.hstgr.cloud/webhook-test/42e817d2-a5e6-47e3-a893-45742f4650e7",
    //     "limit" =>  100
    // ]);

    // $response = Http::withHeaders([
    //     'Authorization' => 'token eyJhbGciOiJIUzI1NiJ9.eyJleHQiOjMzMTg2Nzg1Mjk2OTMsInVzZXJfaWQiOjg0NDc2NzIwMDg5NjI3OCwic2NvcGUiOiI1IiwiaWF0IjoxNzQxODc4NTI5NjkzfQ.CwelU10RiIOmcd3NaRX2r83oMuKMBurfx6wwKV2XIYM',
    //     'Content-Type'  => 'application/json',
    // ])->post('https://api.lofty.com/v1.0/webhook', [
    //     "listId" => 3,
    //     "callbackUrl" => "https://n8n.srv902502.hstgr.cloud/webhook-test/42e817d2-a5e6-47e3-a893-45742f4650e7",
    //     "limit" =>  100
    // ]);

    // $response = Http::withHeaders([
    //     'Authorization' => 'token eyJhbGciOiJIUzI1NiJ9.eyJleHQiOjMzMTg2Nzg1Mjk2OTMsInVzZXJfaWQiOjg0NDc2NzIwMDg5NjI3OCwic2NvcGUiOiI1IiwiaWF0IjoxNzQxODc4NTI5NjkzfQ.CwelU10RiIOmcd3NaRX2r83oMuKMBurfx6wwKV2XIYM',
    //     'Content-Type'  => 'application/json',
    // ])->post('https://api.lofty.com/v1.0/webhook', [
    //     "listId" =>1,
    //     "callbackUrl" => "https://n8n.srv902502.hstgr.cloud/webhook-test/42e817d2-a5e6-47e3-a893-45742f4650e7",
    //     "limit" =>  100
    // ]);

    $response = Http::withHeaders([
        'Authorization' => 'token eyJhbGciOiJIUzI1NiJ9.eyJleHQiOjMzMTg2Nzg1Mjk2OTMsInVzZXJfaWQiOjg0NDc2NzIwMDg5NjI3OCwic2NvcGUiOiI1IiwiaWF0IjoxNzQxODc4NTI5NjkzfQ.CwelU10RiIOmcd3NaRX2r83oMuKMBurfx6wwKV2XIYM',
        'Content-Type'  => 'application/json',
    ])->post('https://api.lofty.com/v1.0/leads', [
            'firstName' => 'Test 2',
            'lastName' => 'Li',
            'emails' => [
                'sample@gmail.com',
                'sample@gmail.com'
            ],
            'phones' => [
                '123456789',
                '987654321'
            ],
            'leadTypes' => [
                1,
                2
            ],
            'streetAddress' => 'The White House,1600 Pennsylvania Avenue NW',
            'city' => 'Washington DC',
            'state' => 'Washington DC',
            'zipCode' => '20500',
            'referredBy' => 'Jeremy Kelly',
            'stage' => 'Pending',
  
            'property' => [
                'price' => 100000,
                'state' => 'California',
                'city' => 'New York',
                'streetAddress' => '22348 Regnart RD',
                'zipCode' => '25401',
                'propertyType' => 'Single Family Home',
                'bedrooms' => 3,
                'bathrooms' => 2,
                'squareFeet' => 100,
                'lotSize' => 26.33,
                'parkingSpace' => 1,
                'floors' => 1,
                'priceMax' => 10000000,
                'priceMin' => 100000
            ],
            
            ]);

    $response->json();

    dd($response->json());
});

Route::get('register-asana', function (){
            $token = env('ASANA_ACCESS_TOKEN');

    // $response = Http::withToken($token)
    //         ->withHeaders([
    //             'Asana-Enable' => 'new_goal_memberships',
    //             'Content-Type' => 'application/json',
    //         ])
    //         ->post('https://app.asana.com/api/1.0/webhooks', [
    //             'data' => [
    //                 'resource' => '1207374645683175',
    //                 'target' => 'https://n8n.srv902502.hstgr.cloud/webhook/3cb77602-8f95-4d44-97d4-f8bb5faed435/webhook',
    //             ],
    //         ]);
    //     if (!$response->successful()) {
    //          echo('Webhook registered for project');
    //     }   

    //     $res = Http::withToken($token)
    //         ->withHeaders([
    //             'Asana-Enable' => 'new_goal_memberships',
    //             'Content-Type' => 'application/json',
    //         ])
    //         ->post('https://app.asana.com/api/1.0/webhooks', [
    //             'data' => [
    //                 'resource' => '1207535257037786',
    //                 'target' => 'https://n8n.srv902502.hstgr.cloud/webhook/349863ae-5576-407a-9cc7-4d70ad6ffa66/webhook',
    //             ],
    //         ]);

    // if (!$res->successful()) {
    //          echo('Webhook registered for project');
    //     }   

    $response = Http::withToken($token)->get('https://app.asana.com/api/1.0/webhooks', [
        'resource' => '1207374645683175',
        'workspace' => env('ASANA_WORKSPACE_ID')

    ]);
     print_r($response->json());
    $res = Http::withToken($token)->get('https://app.asana.com/api/1.0/webhooks', [
        'resource' => '1207535257037786',
        'workspace' => env('ASANA_WORKSPACE_ID')
    ]);

   
    print_r($res->json());

});

Route::get('/webhook/asana/register', function () {
    Artisan::call('asana:register-webhook');
});
