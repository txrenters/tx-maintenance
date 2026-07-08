<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderRecommendation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'matched_work_orders' => 'array',
            'alternate_vendors' => 'array',
            'classification' => 'array',
            'raw_response' => 'array',
            'generated_at' => 'datetime',
            'needs_human_review' => 'boolean',
            'is_emergency' => 'boolean',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function recommendedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'recommended_vendor_id');
    }
}
