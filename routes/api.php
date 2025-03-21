<?php

use App\Http\Controllers\API\ServiceScheduleController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\API\AttachmentsController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\ConversationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route::get('/tasks/{workOrder}/work_order_task',[TaskController::class, 'tasks'])->name('api.work_order.tasks');
Route::post('/work_orders/{task}/task/change',[TaskController::class, 'update'])->name('api.work_order.task.change');
Route::post('/work_orders/{workOrder}/service_status/change',[TaskController::class, 'service_status_change'])->name('api.work_order.service_status_change');

Route::get('/work_orders/{workOrder}/conversation/vendors',[ConversationController::class, 'get_vendor_conversation'])->name('work_order.vendor_conversation');
Route::post('/work_orders/convesation/send',[ConversationController::class, 'vendor_conversation'])->name('work_order.vendor.conversation.send');

Route::get('/work_orders/{workOrder}/conversation/tenants',[ConversationController::class, 'get_tenant_conversation'])->name('work_order.tenant_conversation');
Route::get('/work_orders/{workOrder}/conversation/owners',[ConversationController::class, 'get_owner_conversation'])->name('work_order.owner_conversation');

Route::get('/service_schedule/{workOrder}/vendor',[ServiceScheduleController::class, 'get_schedules'])->name('work_order.service_schedules');
Route::post('/service_schedule/submit',[ServiceScheduleController::class, 'store'])->name('work_order.service_schedule.create');
Route::post('/service_schedule/{serviceSchedule}/complete',[ServiceScheduleController::class, 'update_status'])->name('service_schedule.status.completed');

// Route::get('/attachments/{workOrder}',[AttachmentsController::class, 'show'])->name('api.attachments.show');

// Route::get('/invoices/{workOrder}',[InvoiceController::class, 'index'])->name('api.invoices.index');

