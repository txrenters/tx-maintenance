<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FallbackVendor extends Model
{
    protected $fillable = [
        'vendor_id',
        'contacts',
        'notes',
        'issue_types',
        'keywords',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'contacts' => 'array',
            'issue_types' => 'array',
            'keywords' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
