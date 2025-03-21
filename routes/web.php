<?php

use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\InvoiceController as ControllersInvoiceController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TenantsController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorNotesController;
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
    Route::put('/work_orders/{workOrder}/vendors', [WorkOrderController::class, 'vendor_change'])->name('work_orders.vendor.change');

    
    Route::resource('/task_templates', TaskTemplateController::class);

    Route::get('/scheduled_service', [CalendarController::class, 'index'])->name('scheduled_service');

    Route::get('/tasks/{workOrder}/work_order_task',[TaskController::class, 'tasks'])->name('api.work_order.tasks');

    Route::get('/attachments/{workOrder}',[AttachmentsController::class, 'show'])->name('api.attachments.show');

    Route::get('/invoices/{workOrder}',[InvoiceController::class, 'index'])->name('api.invoices.index');

    Route::post('/attachments',[AttachmentsController::class, 'store'])->name('api.attachments.store');
    Route::delete('/attachments/{attachment}',[AttachmentsController::class, 'destroy'])->name('api.attachments.destroy');

    Route::get('/work_order/invoices',[ControllersInvoiceController::class,'index'])->name('invoices.index');

    Route::post('/invoices',[InvoiceController::class, 'store'])->name('api.invoices.store');
    Route::post('/invoices/{invoice}',[InvoiceController::class, 'update'])->name('api.invoices.update');

    Route::get('/vendor_notes/{workOrder}/show',[VendorNotesController::class, 'getNotes'])->name('api.vendor_notes.show');
    Route::post('/vendor_notes',[VendorNotesController::class, 'store'])->name('api.vendor_notes.store');
    Route::delete('/vendor_notes/{vendorNotes}/',[VendorNotesController::class, 'destroy'])->name('api.vendor_notes.destroy');

});


// Route::prefix('admin/', function(){
//     Route::
// });