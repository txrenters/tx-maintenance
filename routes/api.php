<?php

use App\Http\Controllers\API\WorkOrderAPIController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/work_orders/{workOrder}/tasks',[WorkOrderAPIController::class, 'tasks'])->name('work_order.tasks');
Route::patch('/work_orders/{task}/task/change',[WorkOrderAPIController::class, 'task_change'])->name('work_order.task.change');
Route::patch('/work_orders/{workOrder}/service_status/change',[WorkOrderAPIController::class, 'service_status_change'])->name('work_order.service_status_change');
