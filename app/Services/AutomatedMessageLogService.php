<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fail-safe ledger of every automated outbound message (SMS or email) the app
 * sends to owners, tenants, and vendors, written to the spatie activity_log
 * table under log_name "automated_message" and surfaced on the IT Tools
 * "Automated Messages" page.
 *
 * Row encoding (chosen so the page can filter without JSON functions):
 *  - log_name    automated_message (indexed; isolates the ledger)
 *  - description "{audience}:{channel}", e.g. "owner:sms"
 *  - event       the automation key (see AUTOMATIONS)
 *  - subject     the WorkOrder (or Jobber job for Jobber sends; may be null)
 *  - properties  display payload: recipient, message snippet, ids
 *
 * Call log() right after each successful send/dispatch, inside the same
 * success branch, one call per recipient. Every NEW automated sender that
 * messages an owner, tenant, or vendor must add its own log() call (and a new
 * AUTOMATIONS key) — there is no central send chokepoint that could do it.
 */
class AutomatedMessageLogService
{
    public const LOG_NAME = 'automated_message';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    /**
     * Automation key => human label, as shown in the log page's filter.
     *
     * @var array<string, string>
     */
    public const AUTOMATIONS = [
        'vendor_assignment_email' => 'Vendor assignment (email)',
        'vendor_assignment_sms' => 'Vendor assignment (text)',
        'tenant_vendor_assignment_sms' => 'Tenant: vendor assigned',
        'owner_vendor_assignment_sms' => 'Owner: vendor assigned (text)',
        'owner_vendor_assignment_email' => 'Owner: vendor assigned (email)',
        'owner_service_request_sms' => 'Owner: new service request',
        'tenant_service_request_sms' => 'Tenant: request received',
        'tenant_intake_email' => 'Tenant: request received (email)',
        'owner_appointment_sms' => 'Owner: appointment scheduled',
        'tenant_appointment_sms' => 'Tenant: appointment scheduled',
        'tenant_portal_link_sms' => 'Tenant: portal link',
        'tenant_portal_link_reminder_sms' => 'Tenant: portal link reminder',
        'tenant_hoa_violation_link_sms' => 'Tenant: HOA violation notice',
        'tenant_hoa_violation_reminder_sms' => 'Tenant: HOA violation reminder',
        'tenant_hoa_violation_confirmation_email' => 'Tenant: HOA violation corrected',
        'owner_hoa_violation_confirmation_email' => 'Owner: HOA violation corrected',
        'vendor_schedule_follow_up_sms' => 'Vendor: schedule follow-up',
        'tenant_vendor_contact_follow_up_sms' => 'Tenant: vendor contact follow-up',
        'owner_schedule_follow_up_sms' => 'Owner: schedule follow-up',
        'tenant_schedule_follow_up_sms' => 'Tenant: schedule follow-up',
        'vendor_assignment_notification_email' => 'Vendor: auto-assign notification',
        'vendor_jobber_assignment_email' => 'Vendor: Jobber job assigned (email)',
        'vendor_jobber_assignment_sms' => 'Vendor: Jobber job assigned (text)',
        'tenant_job_reminder_sms' => 'Tenant: Jobber visit reminder (text)',
        'tenant_job_reminder_email' => 'Tenant: Jobber visit reminder (email)',
    ];

    /**
     * Record one automated message. Never throws — a ledger failure must not
     * break the send it is recording.
     *
     * @param  string  $channel  self::CHANNEL_SMS or self::CHANNEL_EMAIL
     * @param  string  $audience  owner|tenant|vendor
     * @param  string  $automation  a key from self::AUTOMATIONS
     * @param  string|null  $recipient  phone number or email address
     * @param  Model|null  $subject  the WorkOrder (or Jobber job) the message is about
     * @param  string|null  $message  the body sent; stored truncated
     * @param  array<string, mixed>  $extra  extra ids worth keeping (owner_id, vendor_id, conversation_id, ...)
     */
    public static function log(
        string $channel,
        string $audience,
        string $automation,
        ?string $recipient,
        ?Model $subject = null,
        ?string $message = null,
        array $extra = [],
    ): void {
        try {
            $activity = activity(self::LOG_NAME);

            if ($subject !== null) {
                $activity->performedOn($subject);
            }

            $activity
                ->event($automation)
                ->withProperties(array_merge([
                    'channel' => $channel,
                    'audience' => $audience,
                    'automation' => $automation,
                    'recipient' => $recipient,
                    'work_order_id' => $subject instanceof WorkOrder ? $subject->id : null,
                    'work_order_no' => $subject instanceof WorkOrder ? $subject->work_order_no : null,
                    'message' => $message !== null ? Str::limit($message, 500) : null,
                ], $extra))
                ->log($audience.':'.$channel);
        } catch (\Throwable $exception) {
            Log::warning('Automated message log write failed.', [
                'automation' => $automation,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
