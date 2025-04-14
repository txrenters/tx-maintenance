<?php

use App\Console\Commands\RegisterAsanaWebhook;
use App\Http\Controllers\API\AsanaWebhookController;
use App\Http\Controllers\API\ServiceScheduleController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\TwilioWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/work_orders/{task}/task/change', [TaskController::class, 'update'])->name('api.work_order.task.change');
Route::post('/work_orders/{workOrder}/service_status/change', [TaskController::class, 'service_status_change'])->name('api.work_order.service_status_change');
Route::post('/work_orders/tasks/{task}/undo', [TaskController::class, 'undo'])->name('api.task.undo');

Route::get('/work_orders/{workOrder}/conversation/vendor_tenant', [ConversationController::class, 'get_vendor_tenant_conversation'])->name('work_order.vendor_tenant_conversation');
Route::get('/work_orders/{workOrder}/conversation/vendors', [ConversationController::class, 'get_vendor_conversation'])->name('work_order.vendor_conversation');
Route::get('/work_orders/{workOrder}/conversation/tenants', [ConversationController::class, 'get_tenant_conversation'])->name('work_order.tenant_conversation');
Route::get('/work_orders/{workOrder}/conversation/owners', [ConversationController::class, 'get_owner_conversation'])->name('work_order.owner_conversation');
Route::post('/work_orders/convesation/send', [ConversationController::class, 'SendMessage'])->name('work_order.vendor.conversation.send');
Route::post('/work_orders/convesation/{conversation}/delete', [ConversationController::class, 'delete'])->name('workorder.message.delete');

Route::get('/service_schedule/{workOrder}/vendor', [ServiceScheduleController::class, 'get_schedules'])->name('work_order.service_schedules');
Route::post('/service_schedule/submit', [ServiceScheduleController::class, 'store'])->name('work_order.service_schedule.create');
Route::post('/service_schedule/{serviceSchedule}/complete', [ServiceScheduleController::class, 'update_status'])->name('service_schedule.status.completed');

Route::post('/twilio/webhook', [TwilioWebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:60,1'); // 60 requests per minute

Route::post('/asana/webhook', [AsanaWebhookController::class, 'handleWebhook']);
