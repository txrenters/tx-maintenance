<?php

namespace App\Jobs;

use App\Mail\JobberVendorAssignmentMail;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tell a vendor they have been assigned a Jobber job (a non-TexasRenters client
 * property) by email and SMS, with a link to their no-login portal.
 *
 * Deliberately much smaller than SendVendorWorkOrderInformation: these jobs have
 * no PropertyWare record, no building, no owner and no tenant, so there is no
 * document to upload and nobody else to notify.
 */
class SendJobberVendorAssignment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $jobberJobId,
        public int $vendorId,
    ) {}

    public function handle(): void
    {
        $job = Jobber::with(['client', 'property'])->find($this->jobberJobId);
        $vendor = Vendor::with('user')->find($this->vendorId);

        if (! $job || ! $vendor) {
            return;
        }

        // THMP is the in-house crew who already work every Jobber job, and the
        // owner placeholder means nobody is being dispatched at all.
        if ($vendor->isThmp() || $vendor->isOwnerPlaceholder()) {
            return;
        }

        // Atomically claim this notification. A double dispatch or a retry after
        // a worker timeout would otherwise send the vendor a second email and
        // text. The conditional UPDATE only affects a row that hasn't been
        // notified yet, so exactly one run proceeds.
        $claimed = DB::table('jobber_job_vendors')
            ->where('jobber_job_id', $job->id)
            ->where('vendor_id', $vendor->id)
            ->whereNull('information_sent_at')
            ->update(['information_sent_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        // Off by default so the first deploy and any backfill send nothing. The
        // claim above still runs, so flipping the gate on later does not blast
        // the existing backlog.
        if (! config('services.jobber.vendor_notify_enabled')) {
            return;
        }

        $details = $this->details($job, $vendor);

        if (filled($vendor->email)) {
            try {
                Mail::to($vendor->email)->send(new JobberVendorAssignmentMail(...$details));
            } catch (\Throwable $exception) {
                Log::error('Jobber vendor assignment email failed.', [
                    'jobber_job_id' => $job->id,
                    'vendor_id' => $vendor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            $this->textVendor($job, $vendor, $details['portalUrl']);
        } catch (\Throwable $exception) {
            Log::error('Jobber vendor assignment text failed.', [
                'jobber_job_id' => $job->id,
                'vendor_id' => $vendor->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The job facts a vendor needs to actually show up and do the work.
     *
     * @return array{vendorName: string, jobNumber: string, title: string, clientName: ?string, clientPhone: ?string, propertyAddress: ?string, instructions: ?string, startAt: ?string, portalUrl: ?string}
     */
    private function details(Jobber $job, Vendor $vendor): array
    {
        $accessToken = $job->vendors()
            ->where('vendors.id', $vendor->id)
            ->first()?->pivot?->access_token;

        $clientName = trim(($job->client?->first_name ?? '').' '.($job->client?->last_name ?? ''));

        return [
            'vendorName' => (string) $vendor->name,
            'jobNumber' => (string) $job->job_number,
            'title' => (string) $job->title,
            'clientName' => $clientName !== '' ? $clientName : ($job->client?->company_name ?: null),
            'clientPhone' => $job->client?->phone,
            'propertyAddress' => $job->property?->full_address,
            'instructions' => $job->instructions,
            'startAt' => $this->formatStart($job->start_at),
            'portalUrl' => $accessToken ? route('jobber.portal.show', $accessToken) : null,
        ];
    }

    /**
     * Text the vendor and record it on the job's own Messages tab, which is
     * where staff already look for this job's correspondence.
     */
    private function textVendor(Jobber $job, Vendor $vendor, ?string $portalUrl): void
    {
        $vendorNumber = $this->toE164($vendor->twilio_number ?: $vendor->phone);
        $senderNumber = config('services.twilio.from');

        if (! $vendorNumber || ! $senderNumber) {
            return;
        }

        $body = 'You have been assigned Job #'.$job->job_number.' - '.$job->title;

        if ($job->property?->full_address) {
            $body .= ' at '.$job->property->full_address;
        }

        if ($portalUrl) {
            $body .= '. Details and photo/invoice upload: '.$portalUrl;
        }

        $message = JobberTextMessage::create([
            'messages' => $body,
            'sender_number' => $senderNumber,
            'receiver_number' => $vendorNumber,
            'jobber_id' => $job->id,
            'status' => 'pending',
        ]);

        SendJobberTextMessageJob::dispatch($message, $body);
    }

    /**
     * The Jobber model casts nothing, so start_at arrives as a raw string. Parse
     * defensively rather than adding casts, which would change the shape of the
     * existing Inertia payloads the Jobs pages already render.
     */
    private function formatStart(mixed $startAt): ?string
    {
        if (blank($startAt)) {
            return null;
        }

        try {
            return Carbon::parse($startAt)->format('D, M j, Y g:i A');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toE164(?string $number): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $number);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return '+'.$digits;
    }
}
