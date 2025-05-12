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

    Route::get('/maintenance/chatbot', function () {

        return inertia('ChatBot/Index', [
            'title' => 'Maintenance Chatbot',
        ]);

    })->name('maintenance.chatbot');

});

Route::get('/conversations/{workOrder}', [ConversationController::class, 'show'])->name('conversation.show');

Route::fallback(function () {
    return inertia('Error', ['status' => 404])
        ->toResponse(request())
        ->setStatusCode(404);
});

Route::get('/functionssss', function () {

    $propertyware = new PropertyWareService;

    $client = $propertyware->initiate();

    dd($client->__getFunctions());
});


Route::get('/updateWorkOrder', function () {
    
    $response = Http::withHeaders([
        'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
        'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
        'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
        'Content-Type' => 'application/json',
    ])->put('https://api.propertyware.com/pw/api/rest/v1/workorders/customfields', [
        "entityId" => 7156957207,
        "fieldSetDTOS" => [
            [
                "name" => "Management Plan",
                "value" => "string"
            ],
             [
                "name" => "Additional work needed- Reschedule",
                "value" => "string"
             ],
              [
                "name" => "Zone",
                "value" => "string"
            ]
        ]
    ]);

        //                         xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
        //                         <customFields xsi:type="urn:CustomField">
        //                             <fieldName xsi:type="xsd:string">Management Plan</fieldName>
        //                             <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->management_plan ?? '', ENT_XML1, 'UTF-8').'</value>
        //                         </customFields>
        //                          <customFields xsi:type="urn:CustomField">
        //                             <fieldName xsi:type="xsd:string">Additional work needed- Reschedule</fieldName>
        //                             <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->additional_work_needed_reschedule ?? '', ENT_XML1, 'UTF-8').'</value>
        //                         </customFields>
        //                          <customFields xsi:type="urn:CustomField">
        //                             <fieldName xsi:type="xsd:string">Zone</fieldName>
        //                             <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->zone ?? '', ENT_XML1, 'UTF-8').'</value>
        //                         </customFields>
        //                          <customFields xsi:type="urn:CustomField">
        //                             <fieldName xsi:type="xsd:string">closing comment</fieldName>
        //                             <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</value>
        //                         </customFields>
        //                     </customFields>

    // dd($response);

     if ($response->status() == 200) {
             Log::error('Success in updating work order', [
                'error_details' => [
                    'status_code' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers(),
                ]
            ]);

            return true;
        } else {
            Log::error('Error updating Work Order', [
                'error' => 'Unable to update work order',
                'error_details' => [
                    'status_code' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers(),
                ]
            ]);

            return false;
        }
});
