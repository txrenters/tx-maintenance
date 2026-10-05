<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\ServiceSchedule;
use App\Models\Technician;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TenantAppointmentNotificationService
{
    public const SKIP_GATE_OFF = 'gate_off';

    public const SKIP_CANCELLED = 'cancelled';

    public const SKIP_TENANT_MUTED = 'tenant_muted';

    public const SKIP_AUTOMATED_MESSAGES = 'skips_automated_messages';

    public function __construct(private TenantPortalLinkService $portalLinks) {}

    /**
     * Tell the tenant their service appointment has been set.
     *
     * Posts the update into the tenant<->WOC conversation thread and texts the
     * tenant the date, the vendor, and their no-login portal link. Unlike the
     * owner notification this fires whoever set the schedule — a tenant always
     * needs to know someone is coming to their home. Fired at most once per
     * schedule, and wrapped so a failure is logged but never breaks schedule
     * creation.
     */
    public function notify(ServiceSchedule $serviceSchedule): void
    {
        if ($this->skipReason($serviceSchedule) !== null) {
            return;
        }

        // Claim this schedule atomically so a retry can never text the tenant
        // twice for the same appointment. A genuine reschedule clears the stamp
        // first (see ServiceScheduleController::update), so it re-notifies.
        $claimed = DB::table('service_schedules')
            ->where('id', $serviceSchedule->id)
            ->whereNull('tenant_notified_at')
            ->update(['tenant_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $this->send($serviceSchedule);
        } catch (\Throwable $exception) {
            Log::error('Tenant appointment notification failed to send.', [
                'service_schedule_id' => $serviceSchedule->id,
                'work_order_id' => $serviceSchedule->work_order_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Why the appointment text would stay silent for this schedule (one of
     * the SKIP_* keys), or null when nothing stands in its way. Shared with
     * the staff "Send tenant text" button so it can say why instead of
     * quietly doing nothing. The once-per-schedule claim is separate.
     */
    public function skipReason(ServiceSchedule $serviceSchedule): ?string
    {
        if (! config('services.twilio.tenant_schedule_sms')) {
            return self::SKIP_GATE_OFF;
        }

        // A cancelled schedule is not an appointment worth announcing.
        if ($serviceSchedule->status === 'cancelled') {
            return self::SKIP_CANCELLED;
        }

        // A WOC can mute this work order's tenant automation from the tenant
        // conversation tab; manual sends are unaffected.
        if ($serviceSchedule->work_order?->automationPausedFor('tenant')) {
            return self::SKIP_TENANT_MUTED;
        }

        // Turnover/re-key/vacant homes, company-ordered refresh cleanings and
        // homes with no lease on file are opted out of automated tenant
        // messages. Checked before the claim so the stamp stays clear and a
        // re-categorized work order can still notify.
        if ($serviceSchedule->work_order?->skipsAutomatedMessages()) {
            return self::SKIP_AUTOMATED_MESSAGES;
        }

        return null;
    }

    private function send(ServiceSchedule $serviceSchedule): void
    {
        $serviceSchedule->loadMissing([
            'vendor.user',
            'technicians',
            'work_order.requested_by',
            'work_order.building',
            'work_order.woc.wocNumber.twilioPhoneNumber',
        ]);

        $workOrder = $serviceSchedule->work_order;

        if (! $workOrder instanceof WorkOrder) {
            return;
        }

        $tenant = $workOrder->requested_by;
        $tenantNumber = $this->toE164($tenant?->mobile_phone ?: $tenant?->home_phone);
        $fromNumber = $this->fromNumber($workOrder);

        $technicianPhotos = $this->technicianPhotos($serviceSchedule);

        $message = TenantMessageFormatter::compose(
            $this->message($serviceSchedule, $workOrder, count($technicianPhotos)),
            $workOrder->work_order_no ?? $workOrder->id,
            $this->portalLinks->link($workOrder),
        );

        // Log the update in the tenant thread even when there is nothing to
        // text, so the coordinator can see the appointment went out.
        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber ?: null,
            'receiver_number' => $tenantNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'is_mms' => $technicianPhotos !== [],
        ]);

        // Attach each chosen technician's photo so the tenant recognizes who
        // is coming. A media row reuses the roster file (no copy) and its
        // signed URL is what Twilio fetches; the thread displays it too.
        $mediaUrls = [];

        foreach ($technicianPhotos as $technicianPhoto) {
            $mediaUrls[] = ConversationMedia::create([
                'message_id' => $conversation->id,
                'original_url' => '',
                'local_path' => $technicianPhoto['path'],
                'content_type' => $technicianPhoto['content_type'],
                'file_name' => $technicianPhoto['file_name'],
            ])->public_url;
        }

        if (blank($tenantNumber) || blank($fromNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($tenantNumber, $fromNumber, $message, $mediaUrls ?: null, $conversation->id);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_appointment_sms',
            $tenantNumber,
            $workOrder,
            $message,
            ['service_schedule_id' => $serviceSchedule->id, 'conversation_id' => $conversation->id],
        );
    }

    /**
     * The line asking the tenant to be home for a third-party vendor. Left out
     * of appointments with THMP: their technicians have their own access, and
     * telling the tenant to be home invited trip-charge disputes (WOC, 09-11).
     */
    private const ACCESS_LINE = 'Please make sure someone 18 or older is home to let the technician in.';

    /**
     * The tenant-facing appointment message. A schedule with chosen
     * technicians sends the THMP Technician Visit Reminder naming each of
     * them (their photos ride along when on file); otherwise the standard
     * vendor appointment message goes out as it always has, minus the
     * be-home line when the vendor is THMP.
     */
    private function message(ServiceSchedule $serviceSchedule, WorkOrder $workOrder, int $photosAttached): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $address = $workOrder->propertyAddress();
        $technicianNames = $serviceSchedule->technicians
            ->map(fn (Technician $technician): string => trim((string) $technician->name))
            ->filter()
            ->values()
            ->all();

        if ($technicianNames !== []) {
            $when = $this->formatAppointmentShort($serviceSchedule);
            $workOrderLabel = trim((string) ($workOrder->type ?: str($workOrder->description ?? '')->limit(60)));

            return AutomatedMessageTemplates::text('tenant_technician_visit_sms', [
                'greeting' => $name !== '' ? "Hi {$name}!" : 'Hi!',
                'property' => $address !== null ? ' at '.$address : '',
                'date_line' => $when !== '' ? "Date: {$when}" : '',
                'work_order_line' => $workOrderLabel !== '' ? "Work Order: {$workOrderLabel}" : '',
                'technician_label' => count($technicianNames) > 1 ? 'Assigned Technicians' : 'Assigned Technician',
                'technician_name' => $this->joinNames($technicianNames),
                'photo_line' => match (true) {
                    $photosAttached > 1 => 'Photos of the technicians assigned to your work order are attached for your reference.',
                    $photosAttached === 1 => 'A photo of the technician assigned to your work order is attached for your reference.',
                    default => '',
                },
            ]);
        }

        $vendorName = trim((string) ($serviceSchedule->vendor?->name
            ?: $serviceSchedule->vendor?->user?->name
            ?: 'the assigned vendor'));

        $when = $this->formatAppointment($serviceSchedule);
        $vendorIsThmp = (bool) $serviceSchedule->vendor?->isThmp();

        $message = AutomatedMessageTemplates::text('tenant_appointment_sms', [
            'greeting' => $name !== '' ? "Hi {$name}," : 'Hi,',
            'property' => $address !== null ? ' at '.$address : '',
            'vendor_name' => $vendorName,
            'scheduled_line' => $when !== '' ? "Scheduled: {$when}" : '',
            'access_line' => $vendorIsThmp ? '' : self::ACCESS_LINE,
        ]);

        // A template override saved before {access_line} existed still spells
        // the sentence out; a THMP appointment must not carry it either way.
        if ($vendorIsThmp && str_contains($message, self::ACCESS_LINE)) {
            $message = str_replace(self::ACCESS_LINE, '', $message);
            $message = trim((string) preg_replace("/\n{3,}/", "\n\n", $message));
        }

        return $message;
    }

    /**
     * MMS media types US carriers reliably deliver; anything else is safer
     * sent as a plain text than risked as an undeliverable attachment.
     *
     * @var list<string>
     */
    private const MMS_SAFE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    /**
     * "Kevin Cole", "Kevin Cole and Emanuel Hall", "A, B and C".
     *
     * @param  list<string>  $names
     */
    private function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    /**
     * The chosen technicians' photos, one per technician with a file on
     * disk to attach. A missing file or a carrier-unfriendly type quietly
     * leaves that technician's photo out instead of producing a Twilio
     * media error; with none left the message stays a plain text.
     *
     * @return list<array{path: string, content_type: string, file_name: string}>
     */
    private function technicianPhotos(ServiceSchedule $serviceSchedule): array
    {
        $photos = [];

        foreach ($serviceSchedule->technicians as $technician) {
            $photo = $this->technicianPhoto($technician);

            if ($photo !== null) {
                $photos[] = $photo;
            }
        }

        return $photos;
    }

    /**
     * @return array{path: string, content_type: string, file_name: string}|null
     */
    private function technicianPhoto(Technician $technician): ?array
    {
        if (! $technician->hasPhoto() || ! Storage::exists($technician->photo_path)) {
            return null;
        }

        $contentType = $technician->photo_content_type ?: 'image/jpeg';

        if (! in_array($contentType, self::MMS_SAFE_TYPES, true)) {
            return null;
        }

        return [
            'path' => $technician->photo_path,
            'content_type' => $contentType,
            'file_name' => basename($technician->photo_path),
        ];
    }

    /**
     * The THMP reminder's compact date ("08/27/2026"), with the time only
     * when one was set.
     */
    private function formatAppointmentShort(ServiceSchedule $serviceSchedule): string
    {
        if (blank($serviceSchedule->scheduled_date)) {
            return '';
        }

        $when = Carbon::parse($serviceSchedule->scheduled_date);

        if ($when->format('H:i') === '00:00') {
            return $when->format('m/d/Y');
        }

        return $when->format('m/d/Y').' at '.$when->format('g:i A');
    }

    /**
     * Format the scheduled date, appending the time only when one was set.
     */
    private function formatAppointment(ServiceSchedule $serviceSchedule): string
    {
        if (blank($serviceSchedule->scheduled_date)) {
            return '';
        }

        $when = Carbon::parse($serviceSchedule->scheduled_date);

        if ($when->format('H:i') === '00:00') {
            return $when->format('l, F j, Y');
        }

        return $when->format('l, F j, Y').' at '.$when->format('g:i A');
    }

    /**
     * The WOC's own number if the work order has one, else the maintenance line,
     * else the general Twilio number.
     */
    private function fromNumber(WorkOrder $workOrder): ?string
    {
        return $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_from')
            ?: config('services.twilio.from');
    }

    private function toE164(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits !== '' ? '+'.$digits : null;
    }
}
