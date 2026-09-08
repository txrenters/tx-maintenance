<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Scopes\NotArchivedScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([NotArchivedScope::class])]
class JobberJobInvoice extends Model
{
    use Archivable;

    protected $table = 'jobber_job_invoices';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Jobber::class, 'jobber_job_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
