<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invoices-mailbox poller's ledger: one row per email it has looked at.
 */
class InvoiceEmailReply extends Model
{
    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
