<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Archived invoices stay out of every normal read.
 *
 * Kept separate from InvoiceScope so the Invoices page can show archived
 * rows to the office with withoutGlobalScope(NotArchivedScope::class) while
 * every role restriction in InvoiceScope stays applied — lifting one filter
 * must never lift the other.
 */
class NotArchivedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.archived_at');
    }
}
