<?php

use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationLogsController;
use App\Http\Controllers\ConversationMediaController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FallbackVendorController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\InspectionVisitController;
use App\Http\Controllers\InvoiceController as ControllersInvoiceController;
use App\Http\Controllers\JobberAuthController;
use App\Http\Controllers\JobberDiagnosticController;
use App\Http\Controllers\JobberTextMessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\TaskController as ControllersTaskController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TenantsController;
use App\Http\Controllers\TwilioMessageSearchController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorNotesController;
use App\Http\Controllers\VendorPortalController;
use App\Http\Controllers\WOCNumbersController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkOrderNotesController;
use App\Http\Controllers\WorkOrderRecommendationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
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

    Route::resource('/fallback_vendors', FallbackVendorController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::resource('/vendors', VendorController::class);
    Route::put('/vendors/change_status/{vendor}', [VendorController::class, 'update_status'])->name('vendors.change_status');
    Route::post('/vendors_import', [VendorController::class, 'import'])->name('vendors.import');
    Route::post('/vendors/{vendor}/sync', [VendorController::class, 'sync'])->name('vendors.sync');

    Route::resource('/owners', OwnerController::class);
    Route::post('/owners/bulkdelete', [OwnerController::class, 'bulkdelete'])->name('owners.bulkdelete');

    Route::resource('/tenants', TenantsController::class);
    Route::post('/tenants/bulkdelete', [TenantsController::class, 'bulkdelete'])->name('tenants.bulkdelete');

    Route::resource('/service_status', ServiceStatusController::class);

    Route::resource('/work_orders', WorkOrderController::class);
    Route::get('/work_orders/closed/done', [WorkOrderController::class, 'closed_work_orders'])->name('work_orders.closed_work_orders');
    Route::get('/work_orders/waiting_on_payment/all', [WorkOrderController::class, 'waiting_on_payment_work_orders'])->name('work_orders.waiting_on_payment');
    Route::get('/work_orders/paid/all', [WorkOrderController::class, 'paid_work_orders'])->name('work_orders.paid');
    Route::get('/work_orders/inspections/all', [WorkOrderController::class, 'inspections_work_orders'])->name('work_orders.inspections');
    Route::get('/work_orders/lawn_service/all', [WorkOrderController::class, 'lawn_service_work_orders'])->name('work_orders.lawn_service');
    Route::get('/work_orders/turnovers/all', [WorkOrderController::class, 'turnover_work_orders'])->name('work_orders.turnovers');
    Route::get('/work_orders/{workOrder}/details', [WorkOrderController::class, 'details'])->name('work_orders.details');
    Route::get('/work_orders/{workOrder}/report', [WorkOrderController::class, 'report'])->name('work_orders.report');
    Route::get('/work_orders/{workOrder}/recommendation', [WorkOrderRecommendationController::class, 'show'])->name('work_orders.recommendation.show');
    Route::post('/work_orders/{workOrder}/recommendation/generate', [WorkOrderRecommendationController::class, 'generate'])->name('work_orders.recommendation.generate');
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
    Route::post('/jobber-sync', [InspectionController::class, 'manualSyncJobber'])->name('jobber.sync');
    Route::get('/visits', [InspectionVisitController::class, 'index'])->name('visits.index');
    Route::get('/visits/{visit}/details', [InspectionVisitController::class, 'visitDetails'])->name('visits.details');
    Route::get('/search-client', [InspectionController::class, 'searchClient'])->name('jobber.searchClient');
    Route::post('/save-client', [InspectionController::class, 'saveClient'])->name('jobber.saveClient');
    Route::get('/inspections/{job}/details', [InspectionController::class, 'jobDetails'])->name('jobber.jobDetails');
    Route::get('/inspections/text/messages', [InspectionController::class, 'messages'])->name('jobber.messages');

    Route::resource('/jobber-text-messages', JobberTextMessageController::class);

    Route::get('/reports/unresolved-7-days', [ReportController::class, 'unresolvedWithin7Days'])->name('reports.unresolved_7_days');
    Route::get('/reports/not-scheduled-3-days', [ReportController::class, 'notScheduledWithin3Days'])->name('reports.not_scheduled_3_days');
    Route::get('/reports/tasks-on-time', [ReportController::class, 'tasksCompletedOnTime'])->name('reports.tasks_on_time');
    Route::get('/reports/open-over-30-days', [ReportController::class, 'openOver30Days'])->name('reports.open_over_30_days');

    Route::get('/conversation-logs', [ConversationLogsController::class, 'index'])->name('conversation_logs.index');
    Route::post('/work_orders/conversation/send', [ConversationController::class, 'SendMessage'])->name('work_order.conversation.send');

    Route::get('/twilio-messages/search', [TwilioMessageSearchController::class, 'index'])->name('twilio_messages.search');
    Route::post('/twilio-messages/sync-status', [TwilioMessageSearchController::class, 'syncStatus'])->name('twilio_messages.sync_status');

    Route::resource('/task_templates', TaskTemplateController::class);

    Route::get('/scheduled_service', [CalendarController::class, 'index'])->name('scheduled_service');

    Route::resource('/tasks', ControllersTaskController::class);

    Route::get('/tasks/{workOrder}/work_order_task', [TaskController::class, 'tasks'])->name('api.work_order.tasks');
    Route::get('/tasks/{workOrder}/incomplete', [ControllersTaskController::class, 'incompleteTasks'])->name('tasks.incomplete');
    Route::post('/tasks/{workOrder}/bulk-complete', [ControllersTaskController::class, 'bulkComplete'])->name('tasks.bulk_complete');
    Route::post('/tasks/closed/bulk-complete-all', [ControllersTaskController::class, 'bulkCompleteAllClosed'])->name('tasks.bulk_complete_all_closed');
    Route::delete('/tasks/{task}/destroy', [TaskController::class, 'destroy'])->name('api.task.destroy');

    Route::get('/attachments/{workOrder}', [AttachmentsController::class, 'show'])->name('api.attachments.show');

    Route::get('/invoices/{workOrder}', [InvoiceController::class, 'index'])->name('api.invoices.index');

    Route::post('/attachments', [AttachmentsController::class, 'store'])->name('api.attachments.store');
    Route::post('/attachments/multiple', [AttachmentsController::class, 'multiple_store'])->name('api.attachments.multiple_store');

    Route::delete('/attachments/{attachment}', [AttachmentsController::class, 'destroy'])->name('api.attachments.destroy');

    Route::get('/work-order-documents/{workOrderDocument}/download', [AttachmentsController::class, 'downloadDocument'])->name('api.work_order_documents.download');

    Route::get('/work_order/invoices', [ControllersInvoiceController::class, 'index'])->name('invoices.index');

    Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
    Route::get('/buildings/{building}', [BuildingController::class, 'show'])->name('buildings.show');

    Route::get('/search', [SearchController::class, 'search'])->name('search');
    Route::get('/search/buildings', [SearchController::class, 'buildings'])->name('search.buildings');

    Route::post('/invoices', [InvoiceController::class, 'store'])->name('api.invoices.store');
    Route::post('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('api.invoices.update');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('api.invoices.destroy');

    Route::get('/notes/{workOrder}/show', [WorkOrderNotesController::class, 'getNotes'])->name('api.work_order_notes.show');
    Route::post('/notes', [WorkOrderNotesController::class, 'store'])->name('api.work_order_notes.store');
    Route::delete('/notes/{note}/', [WorkOrderNotesController::class, 'destroy'])->name('api.work_order_notes.destroy');

    Route::post('/vendor_work_order_details', [VendorNotesController::class, 'update'])->name('api.vendor_work_order_details.update');

    Route::get('/notifications', [NotificationController::class, 'fetchNotification']);

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

// Public, no-login vendor dashboard listing all of one vendor's active work orders.
Route::middleware('vendor.account')->get('/vendor/{vendorToken}', [VendorPortalController::class, 'dashboard'])
    ->name('vendor.portal.dashboard');

// Public, no-login vendor portal. Access is gated entirely by the magic-link token.
Route::middleware('vendor.portal')->prefix('vendor-portal/{token}')->group(function () {
    Route::get('/', [VendorPortalController::class, 'show'])->name('vendor.portal.show');
    Route::post('/estimate', [VendorPortalController::class, 'updateEstimate'])->name('vendor.portal.estimate');
    Route::post('/attachments', [VendorPortalController::class, 'uploadAttachments'])->name('vendor.portal.attachments');
    Route::post('/invoice', [VendorPortalController::class, 'uploadInvoice'])->name('vendor.portal.invoice');
    Route::post('/schedule', [VendorPortalController::class, 'storeSchedule'])->name('vendor.portal.schedule');
    Route::post('/message', [VendorPortalController::class, 'sendMessage'])->name('vendor.portal.message');
    Route::post('/messages/read', [VendorPortalController::class, 'markMessagesRead'])->name('vendor.portal.messages.read');
    Route::post('/tasks/{task}/complete', [VendorPortalController::class, 'completeTask'])->name('vendor.portal.tasks.complete');
});

Route::get('/onboarding/building', [BuildingController::class, 'create'])->name('building.create');

Route::get('/conversations/{workOrder}', [ConversationController::class, 'show'])->name('conversation.show');
Route::get('/conversation-media/{media}', [ConversationMediaController::class, 'show'])
    ->name('conversation.media.show')
    ->middleware('signed');

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
