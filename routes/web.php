<?php

use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\BuildingController;
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
use Illuminate\Support\Facades\Artisan;
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

Route::get('/onboarding/building', [BuildingController::class, 'create'])->name('building.create');

Route::get('/sample-pdf', function () {
    // Sample data for PDF generation
    $sampleData = [
        'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
        'formData' => [
            'paint' => 'Yes',
            'paintColor' => '#ffffff',
            'goingOnTheMarketLawnCare' => 'Management',
            'goingOnTheMarketCleaning' => 'Management',
            'goingOnTheMarketDebrisRemoval' => 'Owner',
            'goingOnTheMarketPaint' => 'Management',
            'goingOnTheMarketCarpetCleaning' => 'Management',
            'goingOnTheMarketCarpetReplacement' => 'Owner',
            'goingOnTheMarketUtilities' => 'Owner',
            'homeOnTheMarketLawnCare' => 'Management',
            'homeOnTheMarketCleaning' => 'Management',
            'homeOnTheMarketUtilities' => 'Owner',
            'beforeTenantMoveInLawnCare' => 'Management',
            'beforeTenantMoveInPestControl' => 'Management',
            'afterTenantMoveInLawnCare' => 'Tenant',
            'reKey' => 'Management',
            'tenantServiceRequest' => 'Use discretion on $75 co-payment',
            'dogsAllowed' => 'Yes',
            'dogsMaxWeight' => '50',
            'catsAllowed' => 'Yes',
            'catRestrictions' => 'No declawing required',
            'otherPetsRestriction' => 'No exotic pets',
            'swimmingPool' => 'Yes',
            'poolService' => 'Yes',
            'poolServiceName' => 'Crystal Clear Pool Service',
            'poolServiceNumber' => '(555) 123-4567',
            'alarmSystem' => 'Yes',
            'alarmSystemIncludedInPrice' => 'No',
            'alarmSystemUnderContract' => 'Yes',
            'alarmSystemCode' => '1234',
            'alarmSystemBeArmDuringMarketing' => 'Yes',
            'communityPool' => 'Yes',
            'park' => 'Yes',
            'playGround' => 'Yes',
            'tennisCourt' => 'No',
            'refrigerator' => 'Yes',
            'microwave' => 'Yes',
            'washingMachine' => 'No',
            'dryer' => 'No',
            'waterSoftener' => 'No',
            'hvacModelYear' => '2018',
            'garageDoorOpener' => 'Yes',
            'garageDoorRemote' => '2',
            'mailboxKeyNo' => '2',
            'mailboxLocation' => 'Front of house',
            'hvacVendorName' => 'ABC HVAC Services',
            'hvacVendorNumber' => '(555) 234-5678',
            'electricVendorName' => 'Electric Pro',
            'electricVendorNumber' => '(555) 345-6789',
            'plumbingVendorName' => 'Plumber Plus',
            'plumbingVendorNumber' => '(555) 456-7890',
            'pestControlVendorName' => 'Pest Away',
            'pestControlVenodrNumber' => '(555) 567-8901',
            'lawnCareVendorName' => 'Green Lawn Care',
            'lawnCareVendorNumber' => '(555) 678-9012',
            'waterProvider' => 'City Water',
            'gasProvider' => 'Texas Gas Co',
            'trashProvider' => 'Waste Management',
            'trashPickupDays' => 'Monday & Thursday',
            'hvacMaintenancePlan' => 'Yes',
            'installFloatSwitch' => 'Yes',
            'homeWarranty' => 'Yes',
            'homeWarrantyCompanyName' => 'Home Shield',
            'floodedProperty' => 'No',
            'floodedPropertyDate' => '',
            'otherComments' => 'The property has recently been updated with new flooring throughout the main living areas. All appliances are in excellent working condition. The HVAC system was serviced last month and is running efficiently.'
        ],
        'buildingData' => [
            'name' => 'Sample Property - 123 Main Street',
            'id' => '12345',
            'address' => [
                'address' => '123 Main Street',
                'addressCont' => 'Unit A',
                'city' => 'Dallas',
                'stateRegion' => 'TX',
                'postalCode' => '75201'
            ]
        ],
        'propertywareData' => [
            'entityId' => 12345,
            'fieldSetDTOS' => [
                ['name' => 'paint', 'value' => 'Yes'],
                ['name' => 'paintColor', 'value' => '#ffffff'],
                ['name' => 'dogsAllowed', 'value' => 'Yes']
            ]
        ],
        'ownerName' => 'John Smith',
                'generated_at' => now()->tz('America/Chicago')->format('Y-m-d h:i A')
    ];

    try {
        // Generate PDF
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('onboarding_process', $sampleData);
        
        // Return PDF as download
        return $pdf->download('sample_property_onboarding_' . date('YmdHis') . '.pdf');
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to generate PDF',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
})->name('sample.pdf');

Route::get('/conversations/{workOrder}', [ConversationController::class, 'show'])->name('conversation.show');

Route::fallback(function () {
    return inertia('Error', ['status' => 404])
        ->toResponse(request())
        ->setStatusCode(404);
});

Route::get('/webhook/asana/register', function () {
    Artisan::call('asana:register-webhook');
});
