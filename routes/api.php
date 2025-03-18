<?php

use App\Http\Controllers\API\WorkOrderAPIController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\TaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/work_orders/{workOrder}/tasks',[TaskController::class, 'show'])->name('work_order.tasks');
Route::patch('/work_orders/{task}/task/change',[TaskController::class, 'update'])->name('work_order.task.change');
Route::patch('/work_orders/{workOrder}/service_status/change',[TaskController::class, 'service_status_change'])->name('work_order.service_status_change');

Route::get('/work_orders/{workOrder}/conversation/vendors',[ConversationController::class, 'get_vendor_conversation'])->name('work_order.vendor_conversation');
Route::post('/work_orders/convesation/send',[ConversationController::class, 'vendor_conversation'])->name('work_order.vendor.conversation.send');

Route::get('/work_orders/{workOrder}/conversation/tenants',[ConversationController::class, 'get_tenant_conversation'])->name('work_order.tenant_conversation');
Route::get('/work_orders/{workOrder}/conversation/owners',[ConversationController::class, 'get_owner_conversation'])->name('work_order.owner_conversation');
