<?php

namespace App\Services;

use App\Jobs\CreateJobberJobForWorkOrder;
use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Work orders for Crystal Creek Air customers: people whose home is not a
 * Texas Renters property. Nothing about them exists in PropertyWare, so the
 * office types the caller's details in, the work order is created here with
 * its own number, the THMP crew is attached, and the Jobber job is created
 * for the crew to be scheduled on. PropertyWare is never involved.
 */
class CrystalCreekWorkOrderService
{
    /** The service status a fresh work order starts in. */
    private const NEW_STATUS = 'New';

    /**
     * Create the customer (or find the returning one), the work order and the
     * THMP assignment, then queue the Jobber job.
     *
     * @param  array{
     *     name: string, phone?: ?string, email?: ?string,
     *     street: string, city: string, state: string, postal_code: string,
     *     category: string, type?: ?string, description: string
     * }  $data
     */
    public function create(array $data, ?User $staff): WorkOrder
    {
        $workOrder = DB::transaction(function () use ($data, $staff) {
            $customer = $this->findOrCreateCustomer($data);

            $workOrder = WorkOrder::create([
                'work_order_no' => $this->allocateWorkOrderNo(),
                'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
                'outside_customer_id' => $customer->id,
                'category' => WorkOrderCategory::canonicalName($data['category']),
                'type' => $data['type'] ?? null,
                'description' => trim($data['description']),
                'status' => 'Open',
                'service_status_id' => $this->newStatusId(),
                'location' => $customer->oneLineAddress(),
                'service_request_contact_name' => $customer->name,
                'service_request_contact_phone' => $customer->phone,
                'service_request_contact_email' => $customer->email,
                'user_id' => $staff?->id,
                'created_date' => now(),
                'local_status' => 'Created',
            ]);

            $this->attachThmp($workOrder);

            return $workOrder;
        });

        // Dispatched only once the row is committed: queue.after_commit is
        // off, so a worker that beat the commit would find no work order and
        // give up without a retry.
        CreateJobberJobForWorkOrder::dispatch($workOrder->id);

        return $workOrder;
    }

    /**
     * The next number in the Crystal Creek Air series. Serialized behind a
     * cache lock because work_order_no has no unique index to catch a race.
     */
    public function allocateWorkOrderNo(): int
    {
        return Cache::lock('crystal-creek:allocate-work-order-no', 10)->block(5, function () {
            $floor = (int) config('services.crystal_creek.first_work_order_no', 7000001);

            $highest = (int) WorkOrder::withoutGlobalScopes()
                ->crystalCreek()
                ->lockForUpdate()
                ->max('work_order_no');

            return max($floor, $highest + 1);
        });
    }

    /**
     * Put the THMP crew on the work order without any of the vendor-assignment
     * mail: the crew is us. The pivot's schedule_followup_sent_at is stamped
     * so vendors:followup-unscheduled never nags THMP about these jobs, and no
     * portal access token is made (the crew works from Jobber, not the vendor
     * portal).
     */
    public function attachThmp(WorkOrder $workOrder): void
    {
        $thmp = Vendor::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(Vendor::THMP_NAME)])
            ->first();

        if ($thmp === null) {
            Log::error('Crystal Creek Air: the THMP vendor is missing from the vendors table; the work order has no vendor.', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
            ]);

            return;
        }

        $workOrder->vendors()->syncWithoutDetaching([
            $thmp->id => ['schedule_followup_sent_at' => now()],
        ]);
    }

    /**
     * A returning caller is matched by phone, then email, then street + zip,
     * so their Jobber property is reused instead of created again. Blank
     * fields on the match are filled from what was just typed; filled ones
     * are left alone, since the typed value may be a one-off.
     *
     * @param  array<string, mixed>  $data
     */
    public function findOrCreateCustomer(array $data): OutsideCustomer
    {
        $phone = PhoneFormatter::e164($data['phone'] ?? null);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $street = trim((string) ($data['street'] ?? ''));
        $postalCode = trim((string) ($data['postal_code'] ?? ''));

        $customer = null;

        if ($phone !== null) {
            $customer = OutsideCustomer::query()->where('phone', $phone)->first();
        }

        if ($customer === null && $email !== '') {
            $customer = OutsideCustomer::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($customer === null && $street !== '' && $postalCode !== '') {
            $customer = OutsideCustomer::query()
                ->whereRaw('LOWER(street) = ?', [mb_strtolower($street)])
                ->where('postal_code', $postalCode)
                ->first();
        }

        $attributes = [
            'name' => trim((string) $data['name']),
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'street' => $street !== '' ? $street : null,
            'city' => filled($data['city'] ?? null) ? trim((string) $data['city']) : null,
            'state' => filled($data['state'] ?? null) ? mb_strtoupper(trim((string) $data['state'])) : null,
            'postal_code' => $postalCode !== '' ? $postalCode : null,
        ];

        if ($customer === null) {
            return OutsideCustomer::query()->create($attributes);
        }

        $fill = array_filter(
            $attributes,
            fn ($value, string $key) => $value !== null && blank($customer->{$key}),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($fill !== []) {
            $customer->update($fill);
        }

        return $customer;
    }

    private function newStatusId(): int
    {
        return (int) (ServiceStatus::query()->where('name', self::NEW_STATUS)->value('id')
            ?? ServiceStatus::query()->orderBy('id')->value('id'));
    }
}
