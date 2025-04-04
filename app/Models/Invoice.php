<?php

namespace App\Models;

use App\Models\Scopes\InvoiceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([InvoiceScope::class])]
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
        return asset('storage/'.$this->filename);
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'filename',
                    'status',
                    'amount',
                ], 'LIKE', "%{$search}%");
        }
    }
}
