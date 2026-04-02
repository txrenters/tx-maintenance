<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FallbackVendor extends Model
{
    protected $fillable = [
        'name',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
