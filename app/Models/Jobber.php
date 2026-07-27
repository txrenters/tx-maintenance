<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jobber extends Model
{
    protected $table = 'jobber_jobs';

    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(JobberClient::class, 'jobber_client_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(JobberProperty::class, 'jobber_property_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(JobberVisit::class, 'jobber_job_id');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenants::class, 'jobber_job_tenant', 'jobber_job_id', 'tenant_id')
            ->withTimestamps();
    }

    public function textMessages(): HasMany
    {
        return $this->hasMany(JobberTextMessage::class, 'jobber_id');
    }

    public function clientContacts(): HasMany
    {
        return $this->hasMany(ClientContact::class, 'jobber_id');
    }

    /**
     * Vendors assigned to work this job. Jobber jobs are for non-TexasRenters
     * client properties, so this is the only record of who is doing the work
     * once it leaves the in-house THMP crew.
     */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'jobber_job_vendors', 'jobber_job_id', 'vendor_id')
            ->using(JobberJobVendor::class)
            ->withPivot('access_token', 'cost_estimate', 'scheduled_end_date', 'information_sent_at')
            ->withTimestamps();
    }

    public function jobAttachments(): HasMany
    {
        return $this->hasMany(JobberJobAttachment::class, 'jobber_job_id');
    }

    public function jobInvoices(): HasMany
    {
        return $this->hasMany(JobberJobInvoice::class, 'jobber_job_id');
    }

    /**
     * Limit the query to jobs the given vendor is assigned to.
     *
     * Deliberately a local scope rather than a global one: a global scope would
     * silently change what the Jobber importer and webhook controller can see
     * whenever a vendor session happens to be active.
     */
    public function scopeForVendor($query, Vendor $vendor): void
    {
        $query->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendor->id));
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'job_number',
                    'title',
                    'job_status',
                ], 'LIKE', "%{$search}%");
        }
    }
}
