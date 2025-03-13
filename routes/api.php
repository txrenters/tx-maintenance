<?php

use App\Http\Controllers\API\WorkOrderAPIController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/work_orders/{workOrder}/tasks',[WorkOrderAPIController::class, 'tasks'])->name('work_order.tasks');
Route::put('/work_orders/{task}/task/change',[WorkOrderAPIController::class, 'task_change'])->name('work_order.task.change');
