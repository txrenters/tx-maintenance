<?php

use App\Http\Controllers\API\ConversationController as APIConversationController;
use App\Http\Controllers\API\DesktopConnectionController;
use App\Http\Controllers\API\ServiceScheduleController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ChatbotWebhookController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\JobberTextMessageController;
use App\Http\Controllers\JobberWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ResendTwilioMessageController;
use App\Http\Controllers\TwilioWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/chatbot/events', ChatbotWebhookController::class)
    ->middleware('throttle:240,1')->name('chatbot.events');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * TexasRenters Desktop client.
 *
 * The handshake is throttled because it mints tokens. The broadcasting auth
 * route it points at (registered in AppServiceProvider) deliberately is not —
 * the client reconnects with backoff after a network drop, and throttling that
 * turns a blip into an outage.
 */
Route::middleware('auth:sanctum')->prefix('desktop')->group(function () {
    Route::post('/connect', [DesktopConnectionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.desktop.connect');

    Route::post('/test-notification', [DesktopConnectionController::class, 'testNotification'])
        ->name('api.desktop.test-notification');
});

Route::post('/work_orders/{task}/task/change', [TaskController::class, 'update'])->name('api.work_order.task.change');
Route::post('/work_orders/{workOrder}/service_status/change', [TaskController::class, 'service_status_change'])->name('api.work_order.service_status_change');
Route::post('/work_orders/{workOrder}/generate_tasks', [TaskController::class, 'generate_tasks'])->name('api.work_order.generate_tasks');
Route::post('/work_orders/tasks/{task}/undo', [TaskController::class, 'undo'])->name('api.task.undo');
Route::post('/work_orders/{workOrder}/tasks/bulk-complete', [TaskController::class, 'bulkComplete'])->name('api.work_order.tasks.bulk_complete');

// NOTE: The conversation read endpoints live in routes/web.php under the
// authenticated (session) group so auth() resolves and ConversationScope can
// isolate each role's — and each vendor's — messages. Keeping them on the
// stateless api group left auth() null, disabling all per-vendor scoping.

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
    ->middleware('web')
    ->name('api.conversations.resend');
Route::post('/conversations/{conversation}/resend-from-maintenance', [ResendTwilioMessageController::class, 'conversationFromMaintenance'])
    ->middleware('web')
    ->name('api.conversations.resend-from-maintenance');
Route::post('/jobber-text-messages/{jobberTextMessage}/resend', [ResendTwilioMessageController::class, 'jobberTextMessage'])
    ->name('api.jobber-text-messages.resend');

Route::post('/search-building', [BuildingController::class, 'searchBuilding']);
Route::post('/buildings/{buildingId}/update-custom-fields', [BuildingController::class, 'updateCustomFields']);

// Client contacts routes moved to the authenticated web group: they sat here
// outside every auth middleware, so any guest could read an outside client's
// contact names and phone numbers, and the store endpoint replaces the whole
// list. The frontend already calls them with session cookies.

// '/notification/messages' (get_conversation) moved to the authenticated web
// group so a vendor cannot read another vendor's thread via notifications.
Route::post('/notification/jobber/messages', [JobberTextMessageController::class, 'get_conversation']);

// TEX App API Routes
Route::prefix('v1')->group(function () {
    // Get conversations by phone number
    Route::get('/conversations', [APIConversationController::class, 'index']);

    Route::post('/tex/webhook', [TwilioWebhookController::class, 'handle'])
        ->middleware('throttle:60,1'); // 60 requests per minute
});
