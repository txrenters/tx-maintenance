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

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * True once the office has posted this invoice to the accounting system.
     * Independent of `status`, which is the vendor-facing approve/decline.
     */
    public function isPosted(): bool
    {
        return $this->posted_at !== null;
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

            // Grouped so the OR clauses can't escape the role restrictions that
            // InvoiceScope applies (a vendor must never match another's invoice).
            $query->where(function ($query) use ($search) {
                $query
                    ->whereAny([
                        'title',
                        'filename',
                        'status',
                        'amount',
                    ], 'LIKE', "%{$search}%")
                    ->orWhereHas('vendor', function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('work_order', function ($query) use ($search) {
                        $query->where('work_order_no', 'LIKE', "%{$search}%");
                    });
            });
        }
    }
}
