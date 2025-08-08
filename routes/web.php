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

Route::get('/webhook/asana/register', function () {
    Artisan::call('asana:register-webhook');
});
