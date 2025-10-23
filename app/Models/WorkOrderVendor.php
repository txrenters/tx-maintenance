<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkOrderVendor extends Pivot
{
    protected $table = 'work_order_vendors';

    protected $guarded = [];
}
