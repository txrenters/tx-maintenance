<?php

namespace App\Console\Commands;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\CrystalCreekWorkOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Give every Jobber job under the "Crystal Creek Air, LLC" client a work order
 * on the Crystal Creek Air board.
 *
 * The office has been making these jobs by hand in Jobber, and may keep
 * doing so. This reads the jobber_jobs mirror (kept current by the Jobber
 * webhooks, and by jobber:import-jobs) and creates one work order per job
 * that has none yet, with the customer taken from the job's property. It is
 * the day-one backfill and the hourly catch-up in one; it never calls
 * Jobber and never creates a Jobber job (the job already exists).
 */
class ImportCrystalCreekJobs extends Command
{
    protected $signature = 'crystal-creek:import-jobs
        {--dry-run : List what would be created without writing anything}';

    protected $description = 'Create a Crystal Creek Air work order for every Jobber job under the Crystal Creek Air client that has none yet.';

    /** Jobber job statuses that mean the work is over. */
    private const CLOSED_JOB_STATUSES = ['archived', 'closed', 'complete', 'completed'];

    public function handle(CrystalCreekWorkOrderService $service): int
    {
        $client = $this->crystalCreekClient();

        if ($client === null) {
            $this->warn('The Crystal Creek Air client is not in jobber_clients yet. Set JOBBER_CRYSTAL_CREEK_CLIENT_GID or run jobber:import-jobs once.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $jobs = Jobber::query()
            ->where('jobber_client_id', $client->id)
            ->with(['property', 'clientContacts'])
            ->orderBy('created_at_jobber')
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($jobs as $job) {
            if ($this->alreadyHasWorkOrder($job)) {
                $skipped++;

                continue;
            }

            $customerData = $this->customerDataFor($job, $client);
            $closed = $this->isClosed($job);

            if ($dryRun) {
                $this->line(sprintf(
                    '  would create: Jobber job #%s "%s" → %s, %s%s',
                    $job->job_number,
                    $job->title,
                    $customerData['name'],
                    $customerData['street'] ?? '(no street)',
                    $closed ? ' [closed]' : '',
                ));
                $created++;

                continue;
            }

            $workOrder = $this->createWorkOrder($service, $job, $client, $customerData, $closed);
            $created++;

            $this->line("  created #{$workOrder->work_order_no} for Jobber job #{$job->job_number} ({$customerData['name']})");
        }

        $verb = $dryRun ? 'would be created' : 'created';
        $this->info("{$created} work order(s) {$verb}, {$skipped} Jobber job(s) already had one.");

        if (! $dryRun && $created > 0) {
            Log::info('Crystal Creek Air: Jobber jobs imported as work orders.', ['created' => $created]);
        }

        return self::SUCCESS;
    }

    private function crystalCreekClient(): ?JobberClient
    {
        $gid = config('services.jobber.crystal_creek_client_gid');

        if (filled($gid)) {
            $byGid = JobberClient::query()->where('jobber_id', $gid)->first();

            if ($byGid !== null) {
                return $byGid;
            }
        }

        return JobberClient::query()
            ->where(function ($query) {
                $query->where('name', 'LIKE', WorkOrder::CRYSTAL_CREEK_SOURCE.'%')
                    ->orWhere('company_name', 'LIKE', WorkOrder::CRYSTAL_CREEK_SOURCE.'%');
            })
            ->orderBy('id')
            ->first();
    }

    private function alreadyHasWorkOrder(Jobber $job): bool
    {
        return WorkOrder::withoutGlobalScopes()
            ->where(function ($query) use ($job) {
                $query->where('jobber_job_gid', $job->jobber_id);

                if (filled($job->jobber_web_uri)) {
                    $query->orWhere('jobber_web_uri', $job->jobber_web_uri);
                }
            })
            ->exists();
    }

    /**
     * The customer as the Jobber job knows them: the property is the address;
     * the name and phone come from the job's contact when the office recorded
     * one, else from the title ("Jane Doe - AC not cooling" → "Jane Doe").
     *
     * @return array<string, mixed>
     */
    private function customerDataFor(Jobber $job, JobberClient $client): array
    {
        $contact = $job->clientContacts->firstWhere('is_primary', true) ?? $job->clientContacts->first();

        $name = trim((string) ($contact->name ?? ''));

        if ($name === '') {
            $name = trim((string) explode(' - ', (string) $job->title, 2)[0]);
        }

        if ($name === '' || preg_match('/^#?\d+$/', $name)) {
            $name = 'Crystal Creek Air customer';
        }

        $property = $job->property;

        return [
            'name' => $name,
            'phone' => $contact->phone ?? null,
            'email' => null,
            'street' => $property?->street,
            'city' => $property?->city,
            'state' => $property?->province,
            'postal_code' => $property?->postal_code,
            'jobber_client_gid' => $client->jobber_id,
            'jobber_property_gid' => $property?->jobber_id,
        ];
    }

    private function isClosed(Jobber $job): bool
    {
        return $job->closed_at !== null
            || in_array(mb_strtolower(trim((string) $job->job_status)), self::CLOSED_JOB_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $customerData
     */
    private function createWorkOrder(CrystalCreekWorkOrderService $service, Jobber $job, JobberClient $client, array $customerData, bool $closed): WorkOrder
    {
        $customer = $service->findOrCreateCustomer($customerData);

        // The Jobber ids come from the job itself here; keep them on the
        // customer so a later work order for the same address reuses them.
        $customer->fill(array_filter([
            'jobber_client_gid' => blank($customer->jobber_client_gid) ? $customerData['jobber_client_gid'] : null,
            'jobber_property_gid' => blank($customer->jobber_property_gid) ? $customerData['jobber_property_gid'] : null,
        ]))->save();

        $createdAt = filled($job->created_at_jobber) ? Carbon::parse($job->created_at_jobber) : now();
        $completedAt = $closed
            ? Carbon::parse($job->completed_at ?? $job->end_at ?? $job->created_at_jobber ?? now())
            : null;

        $workOrder = WorkOrder::create([
            'work_order_no' => $service->allocateWorkOrderNo(),
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'category' => (string) config('services.crystal_creek.default_category', 'HVAC'),
            'description' => trim((string) ($job->instructions ?: $job->title)),
            'status' => $closed ? 'Closed' : 'Open',
            'service_status_id' => $this->statusId($closed ? 'Closed' : 'New'),
            'location' => $customer->oneLineAddress(),
            'service_request_contact_name' => $customer->name,
            'service_request_contact_phone' => $customer->phone,
            'service_request_contact_email' => $customer->email,
            'created_date' => $createdAt,
            'completed_date' => $completedAt?->toDateString(),
            'local_status' => 'Created',
            'jobber_job_gid' => $job->jobber_id,
            'jobber_web_uri' => $job->jobber_web_uri,
        ]);

        $service->attachThmp($workOrder);

        return $workOrder;
    }

    private function statusId(string $name): int
    {
        return (int) (ServiceStatus::query()->where('name', $name)->value('id')
            ?? ServiceStatus::query()->orderBy('id')->value('id'));
    }
}
