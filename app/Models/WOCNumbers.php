<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WOCNumbers extends Model
{
    /** @use HasFactory<\Database\Factories\WOCNumbersFactory> */
    use HasFactory;

    protected $table = 'woc_numbers';

    protected $guarded = [];

    public function twilioPhoneNumber() : BelongsTo
    {
        return $this->belongsTo(TwilioPhoneNumber::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('user', function ($query) use ($search) {
                        $query->where('name', 'like', '%'.$search.'%');
                    })
                    ->orWherehas('twilioPhoneNumber', function ($query) use ($search) {
                        $query->where('phone_number', 'like', '%'.$search.'%');
                    });
            });
        });
    }
}
