<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwilioPhoneNumber extends Model
{
    protected $table = 'twilio_phone_numbers';

    protected $guarded = [];

    public function scopeFilter($query, array $filter): void
    {
        if(!empty($filter['search'])){
            $search = $filter['search'];

            $query
            ->whereAny([
                'name',
                'phone_number',
                ], 'LIKE', "%{$search}%");
        }
    }
}
