<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Texts the vendor for the before/after photos the coordinator's "If
 * Pictures Have not been uploaded contact vendor to upload" line asks
 * about, once per work order, with their portal link, the way the daily
 * schedule nudge does (FollowUpUnscheduledVendors). Earl (2026-10-14):
 * the boss wants the text sent by the system, and it must not be
 * backfilled, so only lines that appeared after the feature went live
 * (app_settings vendor_photo_request.started_at) are texted about; the
 * open backlog stays with the coordinator.
 *
 * THMP work orders (any with a Jobber job) are never texted: the crew's
 * photos come in through the Jobber app and the note sync.
 */
class VendorPhotoRequestService
{
    public const AUTOMATION = 'vendor_photo_request_sms';

    public const STARTED_AT_KEY = 'vendor_photo_request.started_at';

    public function __construct(
        private JobberJobLocator $jobs,
        private VendorPortalLinkService $portalLinks,
    ) {}

    public static function isEnabled(): bool
    {
        return (bool) config('services.tasks.vendor_photo_request_sms');
    }

    /**
     * When the feature went live, or null when the migration has not run
     * (then nothing is ever texted, which is the safe side).
     */
    public static function startedAt(): ?Carbon
    {
        $value = AppSetting::getValue(self::STARTED_AT_KEY);
        $iso = is_array($value) ? ($value['started_at'] ?? null) : $value;

        return filled($iso) ? Carbon::parse($iso) : null;
    }

    /**
     * Why this line is not texted about right now, or null when it is due.
     *
     * @param  list<string>  $missing  which photo types are still missing
     */
    public function holdReason(WorkOrder $workOrder, WorkOrderTask $task, array $missing): ?string
    {
        if (! self::isEnabled()) {
            return 'the photo request text is switched off';
        }

        if ($missing === []) {
            return 'the photos are on file';
        }

        $startedAt = self::startedAt();

        if ($startedAt === null || $task->created_at === null || $task->created_at->lt($startedAt)) {
            return 'the line appeared before the photo request text went live (no backfill)';
        }

        $delay = max(0, (int) config('services.tasks.vendor_photo_request_delay_hours', 24));

        if ($task->created_at->gt(now()->subHours($delay))) {
            return "the line is less than {$delay}h old";
        }

        if ($this->jobs->allForWorkOrder($workOrder)->isNotEmpty()) {
            return 'THMP uploads through the Jobber app';
        }

        if ($this->sentAt($workOrder) !== null) {
            return 'the vendor was already texted for these photos';
        }

        if ($this->vendorsToText($workOrder)->isEmpty()) {
            return 'no assigned vendor has a phone number';
        }

        return null;
    }

    /**
     * When the system texted this work order's vendor for photos, if ever.
     */
    public function sentAt(WorkOrder $workOrder): ?Carbon
    {
        $row = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', self::AUTOMATION)
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->orderByDesc('id')
            ->first();

        return $row?->created_at;
    }

    /**
     * Text every assigned vendor with a phone number, once. Returns the
     * sentence stored on the ticked task, or null when nothing was sent.
     *
     * @param  list<string>  $missing
     */
    public function send(WorkOrder $workOrder, WorkOrderTask $task, array $missing): ?string
    {
        if ($this->holdReason($workOrder, $task, $missing) !== null) {
            return null;
        }

        $label = $this->missingLabel($missing);
        $sent = 0;

        foreach ($this->vendorsToText($workOrder) as $vendor) {
            try {
                $this->text($workOrder, $vendor, $label);
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Vendor photo request could not be sent.', [
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent > 0
            ? 'Vendor texted for the '.$label.' ('.now()->timezone('America/Chicago')->format('M j, Y').')'
            : null;
    }

    /**
     * @param  list<string>  $missing
     */
    public function missingLabel(array $missing): string
    {
        $types = array_values(array_intersect(['before', 'after'], $missing));

        return match ($types) {
            ['before', 'after'] => 'before and after photos',
            ['before'] => 'before photos',
            ['after'] => 'after photos',
            default => 'photos',
        };
    }

    /**
     * @return Collection<int, Vendor>
     */
    private function vendorsToText(WorkOrder $workOrder)
    {
        return $workOrder->vendors()->with('user')->get()
            ->filter(fn (Vendor $vendor) => filled(PhoneFormatter::e164($vendor->phone)))
            ->values();
    }

    /**
     * Mirrors the schedule nudge: a Conversation row in the vendor thread
     * from the coordinator's number, the SMS job, and a ledger row.
     */
    private function text(WorkOrder $workOrder, Vendor $vendor, string $label): void
    {
        $vendorNumber = PhoneFormatter::e164($vendor->phone);
        $message = $this->messageFor($workOrder, $vendor, $label);

        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $wocNumber ?: null,
            'receiver_number' => $vendorNumber,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'read_by_vendor' => false,
            'is_mms' => false,
        ]);

        $notTextedReason = blank($wocNumber) ? 'missing_woc_number' : null;

        if ($notTextedReason === null) {
            SendConversationMessageJob::dispatch($vendorNumber, $wocNumber, $message, null, $conversation->id);
        }

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'vendor',
            self::AUTOMATION,
            $vendorNumber,
            $workOrder,
            $message,
            array_filter([
                'vendor_id' => $vendor->id,
                'conversation_id' => $conversation->id,
                'missing' => $label,
                'not_texted_reason' => $notTextedReason,
            ]),
        );
    }

    private function messageFor(WorkOrder $workOrder, Vendor $vendor, string $label): string
    {
        $body = AutomatedMessageTemplates::text(self::AUTOMATION, ['missing' => $label]);

        $linkBlock = VendorPortalLinkService::linkBlock($this->portalLinks->link($workOrder, $vendor));

        return $linkBlock ? $body."\n\n".$linkBlock : $body;
    }
}
