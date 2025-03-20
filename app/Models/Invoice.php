<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $guarded = [];

    protected $appends = [
        'invoice_url',
    ];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function getInvoiceUrlAttribute()
    {
        return asset('storage/' . $this->filename);
    }
}
