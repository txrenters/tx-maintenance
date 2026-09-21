<?php

use App\Http\Controllers\AiInsightController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\JobberAttachmentsController;
use App\Http\Controllers\API\JobberInvoiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\AutomatedMessageLogController;
use App\Http\Controllers\AutomatedMessageTemplatesController;
use App\Http\Controllers\BoardSummaryController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClientContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationLogsController;
use App\Http\Controllers\ConversationMediaController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FallbackVendorController;
use App\Http\Controllers\FeatureUpdatesController;
use App\Http\Controllers\HoaPhotoGalleryController;
use App\Http\Controllers\HoaViolationController;
use App\Http\Controllers\ImportTwilioNumberController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InboxSummaryController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\InspectionVisitController;
use App\Http\Controllers\IntakeNotificationController;
use App\Http\Controllers\InvoiceController as ControllersInvoiceController;
use App\Http\Controllers\InvoiceNumberController;
use App\Http\Controllers\InvoicePostedController;
use App\Http\Controllers\ItToolsController;
use App\Http\Controllers\JobberAuthController;
use App\Http\Controllers\JobberAutomationSettingsController;
use App\Http\Controllers\JobberDiagnosticController;
use App\Http\Controllers\JobberJobCloseController;
use App\Http\Controllers\JobberJobNoteController;
use App\Http\Controllers\JobberTextMessageController;
use App\Http\Controllers\JobberVendorController;
use App\Http\Controllers\JobberVendorPortalController;
use App\Http\Controllers\MessageAlertController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\OwnerEmailController;
use App\Http\Controllers\OwnerPortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceStatusController;
use App\Http\Controllers\Settings\DesktopNotificationController;
use App\Http\Controllers\TaskController as ControllersTaskController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TbpVisitNoticeController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TenantEmailController;
use App\Http\Controllers\TenantPortalController;
use App\Http\Controllers\TenantsController;
use App\Http\Controllers\TwilioMessageSearchController;
use App\Http\Controllers\TwilioPhoneNumberController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorAssignmentNotificationController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorNotesController;
use App\Http\Controllers\VendorPortalController;
use App\Http\Controllers\WOCNumbersController;
use App\Http\Controllers\WorkOrderAutomationController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkOrderEmailController;
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

    Route::get('/user/desktop-notifications', [DesktopNotificationController::class, 'edit'])->name('settings.desktop-notifications.edit');
    Route::post('/user/desktop-notifications/token', [DesktopNotificationController::class, 'store'])->name('settings.desktop-notifications.store');
    Route::delete('/user/desktop-notifications/token', [DesktopNotificationController::class, 'destroy'])->name('settings.desktop-notifications.destroy');
    Route::post('/user/desktop-notifications/test', [DesktopNotificationController::class, 'test'])->name('settings.desktop-notifications.test');

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
    Route::get('/owners/{owner}/emails', [OwnerEmailController::class, 'index'])->name('owner.email.index');
    Route::post('/owners/{owner}/emails', [OwnerEmailController::class, 'store'])->name('owner.email.send');
    Route::get('/work_orders/{workOrder}/owner-emails', [OwnerEmailController::class, 'workOrderIndex'])->name('work_order.owner_email.index');
    Route::post('/work_orders/{workOrder}/owner-emails', [OwnerEmailController::class, 'workOrderStore'])->name('work_order.owner_email.send');
    Route::get('/owner-email-attachments/{attachment}/download', [OwnerEmailController::class, 'download'])->name('owner.email.attachment');

    Route::resource('/tenants', TenantsController::class);
    Route::post('/tenants/bulkdelete', [TenantsController::class, 'bulkdelete'])->name('tenants.bulkdelete');
    Route::get('/tenants/{tenant}/emails', [TenantEmailController::class, 'index'])->name('tenant.email.index');
    Route::post('/tenants/{tenant}/emails', [TenantEmailController::class, 'store'])->name('tenant.email.send');
    Route::get('/tenant-email-attachments/{attachment}/download', [TenantEmailController::class, 'download'])->name('tenant.email.attachment');

    Route::resource('/service_status', ServiceStatusController::class);

    Route::get('/work_orders/{workOrder}/data', [WorkOrderController::class, 'data'])->name('work_orders.data');
    // Two segments so Route::resource's work_orders/{workOrder} cannot swallow it,
    // the same reason closed/done and paid/all are shaped this way.
    Route::get('/work_orders/summary/board', BoardSummaryController::class)->name('work_orders.summary');
    Route::resource('/work_orders', WorkOrderController::class);
    Route::get('/work_orders/closed/done', [WorkOrderController::class, 'closed_work_orders'])->name('work_orders.closed_work_orders');
    Route::get('/work_orders/waiting_on_payment/all', [WorkOrderController::class, 'waiting_on_payment_work_orders'])->name('work_orders.waiting_on_payment');
    Route::get('/work_orders/paid/all', [WorkOrderController::class, 'paid_work_orders'])->name('work_orders.paid');
    Route::get('/work_orders/inspections/all', [WorkOrderController::class, 'inspections_work_orders'])->name('work_orders.inspections');
    Route::get('/work_orders/lawn_service/all', [WorkOrderController::class, 'lawn_service_work_orders'])->name('work_orders.lawn_service');
    Route::get('/work_orders/turnovers/all', [WorkOrderController::class, 'turnover_work_orders'])->name('work_orders.turnovers');
    Route::get('/work_orders/hvac/all', [WorkOrderController::class, 'hvac_work_orders'])->name('work_orders.hvac');
    Route::post('/work_orders/hvac/seen', [WorkOrderController::class, 'hvac_mark_seen'])->name('work_orders.hvac.seen');
    Route::get('/work_orders/hvac/activity', [WorkOrderController::class, 'hvac_activity'])->name('work_orders.hvac.activity');
    Route::get('/work_orders/vendor/all', [WorkOrderController::class, 'vendorWorkOrders'])->name('work_orders.vendor');
    Route::get('/work_orders/{workOrder}/details', [WorkOrderController::class, 'details'])->name('work_orders.details');
    Route::get('/work_orders/{workOrder}/report', [WorkOrderController::class, 'report'])->name('work_orders.report');
    Route::get('/work_orders/{workOrder}/recommendation', [WorkOrderRecommendationController::class, 'show'])->name('work_orders.recommendation.show');
    Route::post('/work_orders/{workOrder}/recommendation/generate', [WorkOrderRecommendationController::class, 'generate'])->name('work_orders.recommendation.generate');
    Route::put('/work_orders/{workOrder}/close', [WorkOrderController::class, 'close'])->name('work_orders.close');
    Route::put('/work_orders/{workOrder}/open', [WorkOrderController::class, 'open'])->name('work_orders.open');
    Route::put('/work_orders/{workOrder}/emergency', [WorkOrderController::class, 'emergency_change'])->name('work_orders.emergency.change');
    Route::put('/work_orders/{workOrder}/vendors', [WorkOrderController::class, 'vendor_change'])->name('work_orders.vendor.change');
    Route::get('/work_orders/hoa/all', [HoaViolationController::class, 'index'])->name('work_orders.hoa');
    Route::post('/work_orders/hoa/detect', [HoaViolationController::class, 'detect'])->name('work_orders.hoa.detect');
    Route::get('/work_orders/hoa/contacts', [HoaViolationController::class, 'contacts'])->name('work_orders.hoa.contacts');
    Route::post('/work_orders/hoa/store', [HoaViolationController::class, 'store'])->name('work_orders.hoa.store');
    Route::post('/work_orders/import', [WorkOrderController::class, 'import'])->name('work_orders.import');
    Route::get('/work_orders/export/all', [WorkOrderController::class, 'export'])->name('work_orders.export');

    Route::get('/work_orders/coordinators/all', [CoordinatorController::class, 'index'])->name('work_orders.coordinators');
    Route::patch('/work_orders/coordinators/{workOrder}/change', [CoordinatorController::class, 'update'])->name('work_orders.coordinators.change');

    Route::resource('/inspections', InspectionController::class);
    Route::get('/jobber-connect', [InspectionController::class, 'redirectToJobber'])->name('jobber.connect');
    Route::post('/jobber-sync', [InspectionController::class, 'manualSyncJobber'])->name('jobber.sync');

    // Jobber diagnostics + IT tools. These used to sit outside the auth group;
    // /jobber/diagnose even burned the live (single-use) refresh token on every
    // hit, so any crawler could kill the Jobber connection. Admin-only now,
    // enforced in the controllers.
    Route::get('/jobber/diagnose', [JobberDiagnosticController::class, 'diagnose'])->name('jobber.diagnose');
    Route::post('/jobber/clear-tokens', [JobberDiagnosticController::class, 'clearTokens'])->name('jobber.clearTokens');
    Route::get('/it-tools/jobber', [ItToolsController::class, 'jobber'])->name('it-tools.jobber');
    Route::post('/it-tools/jobber/failed-jobs/{uuid}/retry', [ItToolsController::class, 'retryFailedJob'])->name('it-tools.jobber.retry');
    Route::delete('/it-tools/jobber/failed-jobs/{uuid}', [ItToolsController::class, 'forgetFailedJob'])->name('it-tools.jobber.forget');
    Route::get('/it-tools/automated-messages', [AutomatedMessageLogController::class, 'index'])->name('it-tools.automated-messages');

    // Canned-message editor on the Automated Messages page. Admin + WOC,
    // enforced in the controller.
    Route::get('/it-tools/automated-messages/templates', [AutomatedMessageTemplatesController::class, 'index'])->name('it-tools.automated-messages.templates');
    Route::put('/it-tools/automated-messages/templates/{key}', [AutomatedMessageTemplatesController::class, 'update'])->name('it-tools.automated-messages.templates.update');
    Route::delete('/it-tools/automated-messages/templates/{key}', [AutomatedMessageTemplatesController::class, 'reset'])->name('it-tools.automated-messages.templates.reset');

    // Read-only AI insights: a work order's open schedule suggestions, and
    // resolving one (accept/dismiss). Admin + WOC, enforced in the controller.
    Route::get('/work_orders/{workOrder}/schedule-suggestions', [AiInsightController::class, 'scheduleSuggestions'])->name('work_orders.schedule_suggestions');
    Route::patch('/ai-insights/{aiInsight}/status', [AiInsightController::class, 'updateStatus'])->name('ai-insights.status');

    // Runtime AI provider/model switch + UI-entered API keys. Admin-only,
    // enforced in the controller.
    Route::get('/it-tools/ai-settings', [AiSettingsController::class, 'show'])->name('it-tools.ai-settings');
    Route::put('/it-tools/ai-settings', [AiSettingsController::class, 'update'])->name('it-tools.ai-settings.update');
    Route::put('/it-tools/ai-settings/keys', [AiSettingsController::class, 'storeKey'])->name('it-tools.ai-settings.keys');
    Route::delete('/it-tools/ai-settings/keys/{provider}', [AiSettingsController::class, 'destroyKey'])->name('it-tools.ai-settings.keys.destroy');
    Route::post('/it-tools/ai-settings/test', [AiSettingsController::class, 'test'])->name('it-tools.ai-settings.test');

    // App-wide Jobber automation kill-switch (header toggle on the Jobber
    // pages). Admin + WOC, enforced in the controller.
    Route::get('/jobber/automation-settings', [JobberAutomationSettingsController::class, 'show'])->name('jobber.automation.show');
    Route::patch('/jobber/automation-settings', [JobberAutomationSettingsController::class, 'update'])->name('jobber.automation.toggle');

    // Staff changelog of every update shipped to the system (admin + woc).
    Route::get('/whats-new', [FeatureUpdatesController::class, 'index'])->name('whats-new');
    Route::get('/visits', [InspectionVisitController::class, 'index'])->name('visits.index');
    Route::get('/visits/{visit}/details', [InspectionVisitController::class, 'visitDetails'])->name('visits.details');

    // The "Send notification" button on a TBP visit: the canned visit notice
    // with the date filled in plus suggested recipients. Admin + WOC,
    // enforced in the controller. The send itself goes through
    // jobber-text-messages.store below.
    Route::get('/visits/{visit}/tbp-notice', [TbpVisitNoticeController::class, 'show'])->name('visits.tbp_notice');

    Route::get('/search-client', [InspectionController::class, 'searchClient'])->name('jobber.searchClient');
    Route::post('/save-client', [InspectionController::class, 'saveClient'])->name('jobber.saveClient');
    Route::get('/inspections/{job}/details', [InspectionController::class, 'jobDetails'])->name('jobber.jobDetails');
    Route::get('/inspections/text/messages', [InspectionController::class, 'messages'])->name('jobber.messages');

    // Vendor assignment and documentation on Jobber jobs (non-TexasRenters
    // client properties). These jobs never become work orders, so they need
    // their own assignment, photo and invoice endpoints.
    Route::get('/inspections/vendor/jobs', [InspectionController::class, 'vendorJobs'])->name('jobber.vendor_jobs');
    Route::put('/inspections/{job}/vendors', [JobberVendorController::class, 'update'])->name('jobber.vendors.change');
    Route::post('/inspections/{job}/close', [JobberJobCloseController::class, 'store'])->name('jobber.close');
    Route::delete('/inspections/{job}/close', [JobberJobCloseController::class, 'destroy'])->name('jobber.reopen');
    Route::post('/inspections/{job}/attachments', [JobberAttachmentsController::class, 'store'])->name('jobber.attachments.store');
    Route::delete('/jobber-attachments/{attachment}', [JobberAttachmentsController::class, 'destroy'])->name('jobber.attachments.destroy');
    Route::post('/inspections/{job}/invoices', [JobberInvoiceController::class, 'store'])->name('jobber.invoices.store');
    Route::patch('/jobber-invoices/{invoice}', [JobberInvoiceController::class, 'update'])->name('jobber.invoices.update');
    // destroy archives rather than deletes; restore puts it back.
    Route::delete('/jobber-invoices/{invoice}', [JobberInvoiceController::class, 'destroy'])->name('jobber.invoices.destroy');
    Route::post('/jobber-invoices/{invoice}/restore', [JobberInvoiceController::class, 'restore'])->name('jobber.invoices.restore');
    Route::post('/inspections/{job}/notes', [JobberJobNoteController::class, 'store'])->name('jobber.notes.store');
    Route::delete('/jobber-notes/{note}', [JobberJobNoteController::class, 'destroy'])->name('jobber.notes.destroy');

    // Moved out of routes/api.php, where they sat outside every auth middleware:
    // any guest could read and replace an outside client's contact list.
    Route::get('/jobbers/{jobber}/client-contacts', [ClientContactController::class, 'index'])->name('client-contacts.index');
    Route::post('/jobbers/{jobber}/client-contacts', [ClientContactController::class, 'store'])->name('client-contacts.store');
    Route::delete('/client-contacts/{clientContact}', [ClientContactController::class, 'destroy'])->name('client-contacts.destroy');

    Route::resource('/jobber-text-messages', JobberTextMessageController::class);

    Route::get('/reports/unresolved-7-days', [ReportController::class, 'unresolvedWithin7Days'])->name('reports.unresolved_7_days');
    Route::get('/reports/not-scheduled-3-days', [ReportController::class, 'notScheduledWithin3Days'])->name('reports.not_scheduled_3_days');
    Route::get('/reports/tasks-on-time', [ReportController::class, 'tasksCompletedOnTime'])->name('reports.tasks_on_time');
    Route::get('/reports/open-over-30-days', [ReportController::class, 'openOver30Days'])->name('reports.open_over_30_days');
    Route::post('/reports/open-over-30-days/analyze', [ReportController::class, 'analyzeStaleWorkOrder'])->name('reports.open_over_30_days.analyze');

    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('/inbox/threads', [InboxController::class, 'more'])->name('inbox.threads.more');
    Route::get('/inbox/thread', [InboxController::class, 'thread'])->name('inbox.thread');
    Route::get('/inbox/summary', InboxSummaryController::class)->name('inbox.summary');
    Route::get('/message-alerts', MessageAlertController::class)->name('message_alerts');
    Route::get('/conversation-logs', [ConversationLogsController::class, 'index'])->name('conversation_logs.index');
    Route::post('/work_orders/conversation/send', [ConversationController::class, 'SendMessage'])->name('work_order.conversation.send');
    Route::get('/work_orders/{workOrder}/emails', [WorkOrderEmailController::class, 'index'])->name('work_order.email.index');
    Route::get('/work_orders/{workOrder}/email-notifications', [WorkOrderEmailController::class, 'notifications'])->name('work_order.email.notifications');
    Route::post('/work_orders/{workOrder}/emails', [WorkOrderEmailController::class, 'store'])->name('work_order.email.send');
    Route::post('/vendors/{vendor}/emails', [WorkOrderEmailController::class, 'vendorStore'])->name('vendor.email.send');
    Route::get('/email-attachments/{attachment}/download', [WorkOrderEmailController::class, 'download'])->name('work_order.email.attachment');

    // Conversation read endpoints: authenticated (session) so ConversationScope
    // resolves the current user and isolates each vendor's thread.
    Route::get('/work_orders/{workOrder}/conversation/vendors', [ConversationController::class, 'get_vendor_conversation'])->name('work_order.vendor_conversation');
    Route::get('/work_orders/{workOrder}/conversation/vendor_tenant', [ConversationController::class, 'get_vendor_tenant_conversation'])->name('work_order.vendor_tenant_conversation');
    Route::get('/work_orders/{workOrder}/conversation/vendors_owner', [ConversationController::class, 'get_vendor_owner_conversation'])->name('work_order.vendor_owner_conversation');
    Route::get('/work_orders/{workOrder}/conversation/tenants', [ConversationController::class, 'get_tenant_conversation'])->name('work_order.tenant_conversation');
    Route::get('/work_orders/{workOrder}/conversation/owners', [ConversationController::class, 'get_owner_conversation'])->name('work_order.owner_conversation');
    Route::post('/notification/messages', [ConversationController::class, 'get_conversation'])->name('work_order.notification_messages');
    Route::get('/work_orders/{workOrder}/automation', [WorkOrderAutomationController::class, 'show'])->name('work_order.automation.show');
    Route::patch('/work_orders/{workOrder}/automation', [WorkOrderAutomationController::class, 'update'])->name('work_order.automation.toggle');
    Route::post('/work_orders/{workOrder}/vendors/{vendor}/notify-assignment', [VendorAssignmentNotificationController::class, 'store'])->name('work_orders.vendor.notify_assignment');
    Route::post('/work_orders/{workOrder}/notify-intake/{audience}', [IntakeNotificationController::class, 'store'])->name('work_orders.notify_intake');

    Route::get('/twilio-messages/search', [TwilioMessageSearchController::class, 'index'])->name('twilio_messages.search');
    Route::post('/twilio-messages/sync-status', [TwilioMessageSearchController::class, 'syncStatus'])->name('twilio_messages.sync_status');

    Route::resource('/task_templates', TaskTemplateController::class);

    // Technician roster (profiles + the photo the tenant appointment text
    // attaches). Admin + WOC via TechnicianPolicy. Options and photo routes
    // are declared first so the resource's technicians/{technician} cannot
    // swallow them.
    Route::get('/technicians/options', [TechnicianController::class, 'options'])->name('technicians.options');
    Route::get('/technicians/{technician}/photo', [TechnicianController::class, 'showPhoto'])->name('technicians.photo.show');
    Route::post('/technicians/{technician}/photo', [TechnicianController::class, 'updatePhoto'])->name('technicians.photo.update');
    Route::delete('/technicians/{technician}/photo', [TechnicianController::class, 'destroyPhoto'])->name('technicians.photo.destroy');
    Route::resource('/technicians', TechnicianController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('/scheduled_service', [CalendarController::class, 'index'])->name('scheduled_service');

    Route::resource('/tasks', ControllersTaskController::class);

    Route::get('/tasks/{workOrder}/work_order_task', [TaskController::class, 'tasks'])->name('api.work_order.tasks');
    Route::get('/work-order-modal/meta', [WorkOrderController::class, 'modalMeta'])->name('api.work_order_modal.meta');
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
    Route::post('/work_order/invoices/{invoice}/posted', [InvoicePostedController::class, 'store'])->name('invoices.posted.store');
    Route::delete('/work_order/invoices/{invoice}/posted', [InvoicePostedController::class, 'destroy'])->name('invoices.posted.destroy');
    Route::patch('/work_order/invoices/{invoice}/number', [InvoiceNumberController::class, 'update'])->name('invoices.number.update');

    Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
    Route::get('/buildings/{building}', [BuildingController::class, 'show'])->name('buildings.show');

    Route::get('/search', [SearchController::class, 'search'])->name('search');
    Route::get('/search/buildings', [SearchController::class, 'buildings'])->name('search.buildings');

    Route::post('/invoices', [InvoiceController::class, 'store'])->name('api.invoices.store');
    Route::post('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('api.invoices.update');
    // destroy archives rather than deletes; restore is office-only.
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('api.invoices.destroy');
    Route::post('/invoices/{invoice}/restore', [InvoiceController::class, 'restore'])->name('api.invoices.restore');

    Route::get('/notes/{workOrder}/show', [WorkOrderNotesController::class, 'getNotes'])->name('api.work_order_notes.show');
    Route::post('/notes', [WorkOrderNotesController::class, 'store'])->name('api.work_order_notes.store');
    Route::put('/notes/{note}', [WorkOrderNotesController::class, 'update'])->name('api.work_order_notes.update');
    Route::delete('/notes/{note}/', [WorkOrderNotesController::class, 'destroy'])->name('api.work_order_notes.destroy');
    Route::post('/notes/{note}/push', [WorkOrderNotesController::class, 'push'])->name('api.work_order_notes.push');

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

    // TEMPORARY diagnostic for the 2026-07-22 500 incident: staff-only,
    // read-only tail of the newest application log. Remove once resolved.
    Route::get('/debug/log-tail', function () {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'woc']), 403);

        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        rsort($files);
        $path = $files[0] ?? null;

        abort_unless($path && is_readable($path), 404);

        $size = filesize($path);
        $handle = fopen($path, 'r');
        fseek($handle, max(0, $size - 120000));
        $tail = fread($handle, 120000) ?: '';
        fclose($handle);

        return response($tail, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->name('debug.log_tail');
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

// Public, no-login portal for a vendor assigned to a Jobber job. Gated entirely
// by the magic-link token, like the vendor portal above.
Route::middleware(['jobber.portal', 'throttle:60,1'])->prefix('jobber-portal/{token}')->group(function () {
    Route::get('/', [JobberVendorPortalController::class, 'show'])->name('jobber.portal.show');
    Route::post('/attachments', [JobberVendorPortalController::class, 'uploadAttachments'])->name('jobber.portal.attachments');
    Route::post('/invoice', [JobberVendorPortalController::class, 'uploadInvoice'])->name('jobber.portal.invoice');
});

// Public, no-login tenant portal: the tenant<->WOC message thread, the
// appointment, and photos for one work order. Access is gated entirely by the
// magic-link token, like the owner and vendor portals.
Route::middleware(['tenant.portal', 'throttle:60,1'])->prefix('tenant-portal/{token}')->group(function () {
    Route::get('/', [TenantPortalController::class, 'show'])->name('tenant.portal.show');
    Route::post('/message', [TenantPortalController::class, 'sendMessage'])->name('tenant.portal.message');
    Route::post('/attachments', [TenantPortalController::class, 'uploadAttachments'])->name('tenant.portal.attachments');
    Route::post('/messages/read', [TenantPortalController::class, 'markMessagesRead'])->name('tenant.portal.messages.read');
    // Opening a new work order costs several PropertyWare round trips, so this
    // one is throttled harder than the rest of the portal.
    Route::post('/request', [TenantPortalController::class, 'storeRequest'])
        ->middleware('throttle:5,60')
        ->name('tenant.portal.request.store');
    Route::post('/complete', [TenantPortalController::class, 'complete'])->name('tenant.portal.complete');
});

// Public, no-login owner portal: the owner<->WOC message thread and photos for
// one work order. Access is gated entirely by the magic-link token, like the
// tenant and vendor portals.
Route::middleware(['owner.portal', 'throttle:60,1'])->prefix('owner-portal/{token}')->group(function () {
    Route::get('/', [OwnerPortalController::class, 'show'])->name('owner.portal.show');
    Route::post('/message', [OwnerPortalController::class, 'sendMessage'])->name('owner.portal.message');
    Route::post('/attachments', [OwnerPortalController::class, 'uploadAttachments'])->name('owner.portal.attachments');
    Route::post('/messages/read', [OwnerPortalController::class, 'markMessagesRead'])->name('owner.portal.messages.read');
    Route::post('/approval', [OwnerPortalController::class, 'submitApproval'])->name('owner.portal.approval');
});

Route::get('/onboarding/building', [BuildingController::class, 'create'])->name('building.create');

Route::get('/conversations/{workOrder}', [ConversationController::class, 'show'])->name('conversation.show');
Route::get('/conversation-media/{media}', [ConversationMediaController::class, 'show'])
    ->name('conversation.media.show')
    ->middleware('signed');

// Public, no-login before/after photo gallery for a corrected HOA violation,
// linked from the tenant + owner confirmation email. Gated by a signed URL.
Route::get('/hoa/photos/{workOrder}', [HoaPhotoGalleryController::class, 'show'])
    ->name('hoa.photos.show')
    ->middleware('signed');

// Public by necessity (Jobber redirects here), but the handler validates the
// OAuth state stored in the admin's session before exchanging the code.
Route::get('/jobber/callback', [JobberAuthController::class, 'handleCallback'])->name('jobber.callback');

// TEMP dev-only email preview — remove before shipping.
if (app()->environment('local')) {
    Route::get('/dev/email-preview/{template?}', function (?string $template = null) {
        $building = (object) [
            'name' => 'Maple Court', 'address' => '12 Maple St',
            'city' => 'Houston', 'state_region' => 'TX', 'postal_code' => '77007',
        ];
        $workOrder = (object) [
            'id' => 5, 'work_order_no' => '43334', 'type' => 'Turnover', 'category' => 'HVAC',
            'status' => 'New', 'service_status' => (object) ['name' => 'New'],
            'description' => 'AC not cooling', 'building' => $building,
        ];
        $owner = (object) ['name' => 'SDM Home Services LLC', 'first_name' => 'SDM', 'last_name' => 'Home'];
        $vendor = (object) ['name' => 'Cool Air Co', 'phone' => '555-1212'];
        $invoice = (object) ['title' => 'AC Repair Invoice', 'invoice_number' => 'INV-5087', 'amount' => 450.5, 'created_at' => now()];

        $templates = [
            'vendor-service-request' => ['emails.vendor-service-request', [
                'workOrderNo' => $workOrder->work_order_no, 'vendorName' => $vendor->name, 'portalUrl' => 'https://x.test/portal',
            ]],
            'owner-vendor-assignment' => ['emails.owner-vendor-assignment', [
                'owner' => $owner, 'vendor' => $vendor, 'workOrder' => $workOrder,
                'propertyAddress' => '12 Maple St', 'includeTenantLine' => true,
            ]],
            'hoa-violation-corrected' => ['emails.hoa-violation-corrected', [
                'workOrder' => $workOrder, 'property' => '12 Maple St', 'galleryUrl' => 'https://x.test/g/abc',
            ]],
            'operation-accounting-invoice' => ['emails.operation-accounting-invoice', [
                'workOrder' => $workOrder, 'vendor' => $vendor, 'invoice' => $invoice,
                'attached' => true, 'workOrderUrl' => 'https://x.test/wo/5',
            ]],
        ];

        if ($template && isset($templates[$template])) {
            [$view, $data] = $templates[$template];

            return view($view, $data);
        }

        $links = collect($templates)->keys()
            ->map(fn ($key) => '<li style="margin:8px 0;"><a href="/dev/email-preview/'.$key.'" style="color:#2563EA;">'.$key.'</a></li>')
            ->implode('');

        return '<div style="font-family:Arial;padding:32px;"><h2>Email previews</h2><ul style="list-style:none;padding:0;">'.$links.'</ul></div>';
    })->name('dev.email.preview');
}

Route::fallback(function () {
    return inertia('Error', ['status' => 404])
        ->toResponse(request())
        ->setStatusCode(404);
});
