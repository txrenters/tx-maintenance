<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

class WorkOrderVendor extends Pivot
{
    protected $table = 'work_order_vendors';

    protected $guarded = [];

    /**
     * Generate a random access token that is guaranteed unique across assignments.
     */
    public static function generateUniqueAccessToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::query()->where('access_token', $token)->exists());

        return $token;
    }
}
