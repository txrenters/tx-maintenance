<?php

use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\InvoiceController as ControllersInvoiceController;
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
use App\Services\PropertyWareService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    Route::get('/work_orders/{workOrder}/report', [WorkOrderController::class, 'report'])->name('work_orders.report');
    Route::put('/work_orders/{workOrder}/close', [WorkOrderController::class, 'close'])->name('work_orders.close');
    Route::put('/work_orders/{workOrder}/open', [WorkOrderController::class, 'open'])->name('work_orders.open');
    Route::put('/work_orders/{workOrder}/emergency', [WorkOrderController::class, 'emergency_change'])->name('work_orders.emergency.change');
    Route::put('/work_orders/{workOrder}/vendors', [WorkOrderController::class, 'vendor_change'])->name('work_orders.vendor.change');
    Route::post('/work_orders/import', [WorkOrderController::class, 'import'])->name('work_orders.import');
    Route::get('/work_orders/export/all', [WorkOrderController::class, 'export'])->name('work_orders.export');

    Route::get('/work_orders/coordinators/all', [CoordinatorController::class, 'index'])->name('work_orders.coordinators');
    Route::patch('/work_orders/coordinators/{workOrder}/change', [CoordinatorController::class, 'update'])->name('work_orders.coordinators.change');

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
});

Route::get('/conversations/{workOrder}', [ConversationController::class, 'show'])->name('conversation.show');

Route::fallback(function () {
    return inertia('Error', ['status' => 404])
        ->toResponse(request())
        ->setStatusCode(404);
});


Route::get('/workOrder', function () {
    try {
        // API headers from environment variables
        $headers = [
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
        ];
        // Validate environment variables
        if (empty($headers['x-propertyware-client-id']) || empty($headers['x-propertyware-client-secret']) || empty($headers['x-propertyware-system-id'])) {
            throw new \Exception('Missing Propertyware API credentials in environment variables.');
        }

        $absolutePath = public_path('logo.png');
        if (!file_exists($absolutePath)) {
            throw new \Exception('File does not exist: ' . $absolutePath);
        }

        $fileName = 'logo434343.png';
        $formFields = [
            'entityId' => 7156957207, 
            'entityType' => 'Work Order',
            'publishToOwnerPortal' => false,
            'publishToTenantPortal' => true,
        ];

        $fileContents = file_get_contents($absolutePath);

        $response = Http::withHeaders($headers)
            ->attach('file', $fileContents, $fileName)
            ->post('https://api.propertyware.com/pw/api/rest/v1/docs', $formFields);

        // Handle the response
        if ($response->successful()) {

            $postData = $response->json();

            $res = Http::withHeaders($headers)
            ->put('https://api.propertyware.com/pw/api/rest/v1/docs/'.$postData['id'],[
                'fileName' => $fileName,
                'description' => 'TBP',
                'publishToOwnerPortal' => true,
                'publishToTenantPortal' => false,
            ]);
            return response()->json($response->json(), 200);
        }

        // Log error for debugging
        Log::error('Propertyware API request failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'error' => $response->json(),
        ]);

        return response()->json([
            'status' => $response->status(),
            'body' => $response->body(),
            'error' => $response->json() ?? 'Unknown error occurred',
        ], $response->status());

    } catch (\Exception $e) {
        // Log any exceptions
        Log::error('Error in workOrder route: ' . $e->getMessage());

        return response()->json([
            'error' => 'An error occurred: ' . $e->getMessage(),
        ], 500);
    }
});

Route::get('/webhook/asana/register', function () {
    Artisan::call('asana:register-webhook');
});
