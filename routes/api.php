<?php

use App\Http\Controllers\API\ConversationController as APIConversationController;
use App\Http\Controllers\API\ServiceScheduleController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ClientContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\JobberTextMessageController;
use App\Http\Controllers\JobberWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ResendTwilioMessageController;
use App\Http\Controllers\TwilioWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/work_orders/{task}/task/change', [TaskController::class, 'update'])->name('api.work_order.task.change');
Route::post('/work_orders/{workOrder}/service_status/change', [TaskController::class, 'service_status_change'])->name('api.work_order.service_status_change');
Route::post('/work_orders/{workOrder}/generate_tasks', [TaskController::class, 'generate_tasks'])->name('api.work_order.generate_tasks');
Route::post('/work_orders/tasks/{task}/undo', [TaskController::class, 'undo'])->name('api.task.undo');
Route::post('/work_orders/{workOrder}/tasks/bulk-complete', [TaskController::class, 'bulkComplete'])->name('api.work_order.tasks.bulk_complete');

Route::get('/work_orders/{workOrder}/conversation/vendors', [ConversationController::class, 'get_vendor_conversation'])->name('work_order.vendor_conversation');
Route::get('/work_orders/{workOrder}/conversation/vendor_tenant', [ConversationController::class, 'get_vendor_tenant_conversation'])->name('work_order.vendor_tenant_conversation');
Route::get('/work_orders/{workOrder}/conversation/vendors_owner', [ConversationController::class, 'get_vendor_owner_conversation'])->name('work_order.vendor_owner_conversation');
Route::get('/work_orders/{workOrder}/conversation/tenants', [ConversationController::class, 'get_tenant_conversation'])->name('work_order.tenant_conversation');
Route::get('/work_orders/{workOrder}/conversation/owners', [ConversationController::class, 'get_owner_conversation'])->name('work_order.owner_conversation');

Route::delete('/work_orders/convesation/{conversation}', [ConversationController::class, 'delete'])->name('workorder.message.delete');

Route::get('/service_schedule/{workOrder}/vendor', [ServiceScheduleController::class, 'get_schedules'])->name('work_order.service_schedules');
Route::post('/service_schedule/submit', [ServiceScheduleController::class, 'store'])->name('work_order.service_schedule.create');
Route::put('/service_schedule/{serviceSchedule}', [ServiceScheduleController::class, 'update'])->name('work_order.service_schedule.update');
Route::post('/service_schedule/{serviceSchedule}/complete', [ServiceScheduleController::class, 'update_status'])->name('service_schedule.status.completed');
Route::delete('/service_schedule/{serviceSchedule}', [ServiceScheduleController::class, 'destroy'])->name('service_schedule.destroy');

Route::post('/twilio/webhook', [TwilioWebhookController::class, 'handle'])
    ->middleware('throttle:60,1'); // 60 requests per minute

Route::post('/twilio/status-callback', [TwilioWebhookController::class, 'statusCallback'])
    ->name('twilio.status_callback')
    ->middleware('throttle:120,1');

Route::post('/jobber/webhook', [JobberWebhookController::class, 'handle'])
    ->middleware('throttle:60,1'); // 60 requests per minute;

Route::put('/notifications/{activity}/mark-as-read', [NotificationController::class, 'markAsRead']);
Route::put('/notifications/{activity}/mark-as-unread', [NotificationController::class, 'markAsUnread']);

Route::post('/conversations/{conversation}/resend', [ResendTwilioMessageController::class, 'conversation'])
    ->name('api.conversations.resend');
Route::post('/jobber-text-messages/{jobberTextMessage}/resend', [ResendTwilioMessageController::class, 'jobberTextMessage'])
    ->name('api.jobber-text-messages.resend');

Route::post('/search-building', [BuildingController::class, 'searchBuilding']);
Route::post('/buildings/{buildingId}/update-custom-fields', [BuildingController::class, 'updateCustomFields']);

// Client contacts routes
Route::get('/jobbers/{jobber}/client-contacts', [ClientContactController::class, 'index'])->name('client-contacts.index');
Route::post('/jobbers/{jobber}/client-contacts', [ClientContactController::class, 'store'])->name('client-contacts.store');
Route::delete('/client-contacts/{clientContact}', [ClientContactController::class, 'destroy'])->name('client-contacts.destroy');

Route::post('/notification/messages', [ConversationController::class, 'get_conversation']);
Route::post('/notification/jobber/messages', [JobberTextMessageController::class, 'get_conversation']);

// TEX App API Routes
Route::prefix('v1')->group(function () {
    // Get conversations by phone number
    Route::get('/conversations', [APIConversationController::class, 'index']);

    Route::post('/tex/webhook', [TwilioWebhookController::class, 'handle'])
        ->middleware('throttle:60,1'); // 60 requests per minute
});
