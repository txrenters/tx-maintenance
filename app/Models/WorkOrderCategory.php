<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderCategory extends Model
{
    protected $table = 'work_order_categories';

    protected $guarded = [];

    /**
     * Resolve a category to the exact stored name, ignoring case and
     * surrounding whitespace. PropertyWare's picklist values are matched
     * verbatim on their side — e.g. the real value is "HVAC " with a trailing
     * space, and sending a visually-identical "HVAC" is rejected — so anything
     * we sync must use the exact known spelling. Returns the input unchanged
     * when no match exists.
     */
    public static function canonicalName(?string $name): ?string
    {
        $trimmed = mb_strtolower(trim((string) $name));

        if ($trimmed === '') {
            return $name;
        }

        return static::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$trimmed])
            ->value('name') ?? $name;
    }
}
