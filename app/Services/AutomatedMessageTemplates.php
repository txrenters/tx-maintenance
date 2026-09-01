<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;

/**
 * The editable canned-message registry behind the Message Templates tab on the
 * IT Tools Automated Messages page. Every entry pairs a template key with the
 * canonical default wording, the placeholder tokens a sender substitutes, and
 * the copy the editor UI shows staff.
 *
 * Overrides are stored as one flat {template_key: text} map in a single
 * app_settings row, so the feature ships inert: absent row or absent key means
 * the hardcoded default, byte-identical to the wording that shipped before this
 * registry existed. Reads fail OPEN — a corrupt or unreadable override can
 * never stop a send, it just falls back to the default.
 *
 * Templates never branch: senders precompute whole clauses (a greeting, an
 * " at 123 Main St" fragment, a "Scheduled: ..." line) and pass them as token
 * values that may be empty. Entries with 'collapse' squash the blank hole an
 * empty line-level token leaves behind. Formatter chrome — sign-offs, portal
 * link blocks, (Ref: WO#...) footers added by TenantMessageFormatter,
 * OwnerMessageFormatter and VendorPortalLinkService — deliberately stays in
 * code, outside the editable text.
 */
class AutomatedMessageTemplates
{
    /**
     * The app_settings row holding all overrides as one {template_key: text} map.
     */
    public const KEY = 'automated_message_templates';

    /**
     * Registry of editable templates. Every 'automation' must be a key of
     * AutomatedMessageLogService::AUTOMATIONS (the ledger stays the single
     * source of truth for automation labels); every {token} used in a default
     * must be declared under 'tokens'; every 'required' token must appear in
     * the default; every 'sample' must cover all declared tokens — all four
     * invariants are asserted by the registry-integrity test.
     *
     * @var array<string, array{
     *     automation: string,
     *     label: string,
     *     group: string|null,
     *     channel: 'sms'|'sms_email',
     *     audience: 'tenant'|'owner'|'vendor',
     *     sends_when: string,
     *     tokens: array<string, string>,
     *     required: list<string>,
     *     sample: array<string, string>,
     *     collapse: bool,
     *     default: string,
     * }>
     */
    public const TEMPLATES = [
        'vendor_schedule_follow_up_first' => [
            'automation' => 'vendor_schedule_follow_up_sms',
            'label' => 'First notice',
            'group' => 'vendor_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'Texted to the vendor on the first daily nudge after an assignment has no service schedule yet. Their portal link is added automatically below this text.',
            'tokens' => [],
            'required' => [],
            'sample' => [],
            'collapse' => false,
            'default' => "Hello,\n"
                ."We noticed that a service schedule has not yet been set for this work order.\n"
                ."Please make sure to update the work order by creating a schedule under the Service Schedule tab on your dashboard once confirmed with the tenant.\n"
                ."Once the appointment has been scheduled, please ensure that the completed tasks are checked off accordingly so the work order status can be updated to Scheduled.\n"
                ."Please complete this update as soon as possible and let us know once it has been done.\n"
                .'Thank you.',
        ],
        'vendor_schedule_follow_up_variant_1' => [
            'automation' => 'vendor_schedule_follow_up_sms',
            'label' => 'Rotation variant 1',
            'group' => 'vendor_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'The daily schedule nudge rotates through variants 1-5 after the first notice, so consecutive days never repeat the same text.',
            'tokens' => [],
            'required' => [],
            'sample' => [],
            'collapse' => false,
            'default' => "Hello,\n"
                ."Just following up on our earlier message - we still don't see a service schedule for this work order.\n"
                ."Once you've confirmed a time with the tenant, please add it under the Service Schedule tab on your dashboard, and check off the completed tasks so the status can be updated to Scheduled.\n"
                ."We'd appreciate an update as soon as you're able. Thank you!",
        ],
        'vendor_schedule_follow_up_variant_2' => [
            'automation' => 'vendor_schedule_follow_up_sms',
            'label' => 'Rotation variant 2',
            'group' => 'vendor_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'The daily schedule nudge rotates through variants 1-5 after the first notice, so consecutive days never repeat the same text.',
            'tokens' => [],
            'required' => [],
            'sample' => [],
            'collapse' => false,
            'default' => "Hi,\n"
                ."A quick reminder about this work order - the service schedule still hasn't been added.\n"
                ."When you and the tenant have agreed on a time, please enter it under the Service Schedule tab on your dashboard and mark the completed tasks so we can move the status to Scheduled.\n"
                ."Please let us know once it's done. Thank you so much!",
        ],
        'vendor_schedule_follow_up_variant_3' => [
            'automation' => 'vendor_schedule_follow_up_sms',
            'label' => 'Rotation variant 3',
            'group' => 'vendor_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'The daily schedule nudge rotates through variants 1-5 after the first notice, so consecutive days never repeat the same text.',
            'tokens' => [],
            'required' => [],
            'sample' => [],
            'collapse' => false,
            'default' => "Hello,\n"
                ."We wanted to check in, as this work order is still showing without a service schedule.\n"
                ."If you've already confirmed with the tenant, please take a moment to record the appointment under the Service Schedule tab on your dashboard and tick off the completed tasks so the status updates to Scheduled.\n"
                .'If something is holding this up, just reply here and let us know. Thank you!',
        ],
        'vendor_schedule_follow_up_variant_4' => [
            'automation' => 'vendor_schedule_follow_up_sms',
            'label' => 'Rotation variant 4',
            'group' => 'vendor_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'The daily schedule nudge rotates through variants 1-5 after the first notice, so consecutive days never repeat the same text.',
            'tokens' => [],
            'required' => [],
            'sample' => [],
            'collapse' => false,
            'default' => "Hi,\n"
                ."Checking in again on this work order - we're still waiting on the service schedule.\n"
                ."Please confirm a visit time with the tenant if you haven't yet, then add it under the Service Schedule tab on your dashboard and check off the completed tasks so the status can change to Scheduled.\n"
                ."A quick note once that's in would be much appreciated. Thanks for your help!",
        ],
        'vendor_schedule_follow_up_variant_5' => [
            'automation' => 'vendor_schedule_follow_up_sms',
            'label' => 'Rotation variant 5',
            'group' => 'vendor_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'The daily schedule nudge rotates through variants 1-5 after the first notice, so consecutive days never repeat the same text.',
            'tokens' => [],
            'required' => [],
            'sample' => [],
            'collapse' => false,
            'default' => "Hello,\n"
                ."A friendly nudge on this one - the service schedule for this work order hasn't come through yet.\n"
                ."Once the time is set with the tenant, please log it under the Service Schedule tab on your dashboard and mark the completed tasks so the work order can move to Scheduled.\n"
                ."Thank you for keeping this moving - please update us when it's done.",
        ],
        'tenant_service_request_sms' => [
            'automation' => 'tenant_service_request_sms',
            'label' => 'Request received',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'Texted to the tenant once when their service request is received. Their portal link and the sign-off are added automatically below this text.',
            'tokens' => [
                'greeting' => 'Opening line — "Hi <first name>," or "Hi," when no name is on file',
                'property' => '" for <street address>" or empty when no address is on file',
            ],
            'required' => [],
            'sample' => [
                'greeting' => 'Hi Jane,',
                'property' => ' for 123 Main St',
            ],
            'collapse' => false,
            'default' => "{greeting}\n\n"
                ."This is TexasRenters.com Maintenance. We wanted to let you know we have received your service request{property}.\n\n"
                ."Once we review the details with our maintenance team and, if needed, the owner, we will provide you with further information on how we will proceed with any necessary repairs.\n\n"
                .'Photos of the issue help us get the right person out the first time, so please add them if you can.',
        ],
        'owner_service_request_confirmation_sms' => [
            'automation' => 'owner_service_request_sms',
            'label' => 'Request received',
            'group' => 'owner_service_request',
            'channel' => 'sms',
            'audience' => 'owner',
            'sends_when' => 'Texted to each owner once when a new service request comes in. Their portal link and the sign-off are added automatically below this text.',
            'tokens' => [
                'property' => '"your property" or "your property at <street address>" when one is on file',
                'work_order_no' => 'The work order number',
            ],
            'required' => [],
            'sample' => [
                'property' => 'your property at 123 Main St',
                'work_order_no' => '43361',
            ],
            'collapse' => false,
            'default' => "Hello,\n\n"
                ."TexasRenters.com has received a new service request for {property} (request #{work_order_no}).\n\n"
                .'We will take care of arranging the estimate and any repairs needed, as outlined in your property management agreement.',
        ],
        'owner_service_request_description_sms' => [
            'automation' => 'owner_service_request_sms',
            'label' => 'Request details',
            'group' => 'owner_service_request',
            'channel' => 'sms',
            'audience' => 'owner',
            'sends_when' => 'The second text to each owner, carrying the request description. Skipped entirely when the work order has no description.',
            'tokens' => [
                'description' => "The work order's description as the tenant/PropertyWare entered it",
            ],
            'required' => ['description'],
            'sample' => [
                'description' => 'Kitchen sink is leaking under the cabinet.',
            ],
            'collapse' => false,
            'default' => "Here are the details of the request:\n\n"
                .'{description}',
        ],
        'tenant_appointment_sms' => [
            'automation' => 'tenant_appointment_sms',
            'label' => 'Appointment scheduled',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'Texted to the tenant when a vendor sets a service appointment on their work order. The sign-off is added automatically below this text.',
            'tokens' => [
                'greeting' => 'Opening line — "Hi <first name>," or "Hi," when no name is on file',
                'property' => '" at <street address>" or empty when no address is on file',
                'vendor_name' => "The assigned vendor's name",
                'scheduled_line' => '"Scheduled: <date, and time if one was set>" or empty when no date is set',
            ],
            'required' => ['vendor_name'],
            'sample' => [
                'greeting' => 'Hi Jane,',
                'property' => ' at 123 Main St',
                'vendor_name' => 'ACME Plumbing',
                'scheduled_line' => 'Scheduled: Monday, August 24, 2026 at 3:00 PM',
            ],
            'collapse' => true,
            'default' => "{greeting}\n\n"
                ."This is TexasRenters.com Maintenance. The service appointment for your home{property} has been scheduled with {vendor_name}.\n\n"
                ."{scheduled_line}\n\n"
                ."Please make sure someone 18 or older is home to let the technician in.\n\n"
                .'Thank you!',
        ],
        'tenant_technician_visit_sms' => [
            // Same automation as the standard appointment message: one
            // sender, one gate/ledger key — this is just its THMP shape when
            // a technician is chosen.
            'automation' => 'tenant_appointment_sms',
            'label' => 'THMP technician visit (photo attached)',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'Texted to the tenant INSTEAD of the standard appointment message when a technician is chosen on the service schedule. Their photo is attached when one is on file. The portal link and sign-off are added automatically below this text.',
            'tokens' => [
                'greeting' => '"Hi <first name>!" or "Hi!" when no name is on file',
                'property' => '" at <street address>" or empty when no address is on file',
                'date_line' => '"Date: <mm/dd/yyyy, and time if one was set>" or empty when no date is set',
                'work_order_line' => '"Work Order: <type or description>" or empty when neither is on file',
                'technician_name' => "The assigned technician's name",
                'photo_line' => '"A photo of the technician assigned to your work order is attached for your reference." or empty when no photo is on file',
            ],
            'required' => ['technician_name'],
            'sample' => [
                'greeting' => 'Hi Jane!',
                'property' => ' at 123 Main St',
                'date_line' => 'Date: 08/27/2026',
                'work_order_line' => 'Work Order: Tenant Benefit Package',
                'technician_name' => 'Emanuel Hall',
                'photo_line' => 'A photo of the technician assigned to your work order is attached for your reference.',
            ],
            'collapse' => true,
            'default' => "THMP Technician Visit Reminder\n\n"
                ."{greeting}\n\n"
                ."This is a reminder from THMP regarding the scheduled technician visit at your property{property}.\n\n"
                ."{date_line}\n"
                ."{work_order_line}\n"
                ."Assigned Technician: {technician_name}\n\n"
                ."{photo_line}\n\n"
                ."Please make sure our technician can access the property at the scheduled time to avoid unnecessary rescheduling and penalties.\n\n"
                .'Thank you for your cooperation!',
        ],
        'owner_appointment_sms' => [
            'automation' => 'owner_appointment_sms',
            'label' => 'Appointment scheduled',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'owner',
            'sends_when' => 'Texted to each owner when a vendor sets a service appointment on their work order (once per work order). The sign-off is added automatically below this text.',
            'tokens' => [
                'property' => '" at <street address>" or empty when no address is on file',
                'vendor_name' => "The assigned vendor's name",
                'scheduled_line' => '"Scheduled Date: <date, and time if one was set>" or empty when no date is set',
            ],
            'required' => ['vendor_name'],
            'sample' => [
                'property' => ' at 123 Main St',
                'vendor_name' => 'ACME Plumbing',
                'scheduled_line' => 'Scheduled Date: Monday, August 24, 2026 at 3:00 PM',
            ],
            'collapse' => true,
            'default' => "Hello,\n\n"
                ."The service appointment for your property{property} has been scheduled with {vendor_name}.\n\n"
                ."{scheduled_line}\n\n"
                ."If any major issues or additional repairs are identified during the visit related to the reported concern, please keep your phone lines available so we can reach out for approval before any additional work is performed, except in the case of an emergency repair that requires immediate action.\n\n"
                ."We will keep you updated once the service has been completed.\n\n"
                .'Thank you!',
        ],
        'tenant_vendor_contact_follow_up_sms' => [
            'automation' => 'tenant_vendor_contact_follow_up_sms',
            'label' => 'Has the vendor reached out?',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'Texted to the tenant when a vendor was assigned days ago but no appointment has been scheduled yet. Their portal link and the sign-off are added automatically below this text.',
            'tokens' => [
                'greeting' => 'Opening — "Hi <first name>, " or "Hi, " (keeps its trailing space; the sentence continues right after it)',
                'work_order_no' => 'The work order number',
            ],
            'required' => [],
            'sample' => [
                'greeting' => 'Hi Jane, ',
                'work_order_no' => '43361',
            ],
            'collapse' => false,
            'default' => '{greeting}this is TexasRenters.com Maintenance about your service request (WO#{work_order_no}). '
                .'Has the assigned vendor reached out to you yet to schedule the repair? '
                .'Please reply to let us know so we can help. Thank you!',
        ],
        'owner_schedule_follow_up_sms' => [
            'automation' => 'owner_schedule_follow_up_sms',
            'label' => 'Upcoming appointment follow-up',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'owner',
            'sends_when' => 'Texted to each owner ahead of an upcoming service appointment. The sign-off is added automatically below this text.',
            'tokens' => [
                'property' => '" at <street address>" or empty when no address is on file',
            ],
            'required' => [],
            'sample' => [
                'property' => ' at 123 Main St',
            ],
            'collapse' => false,
            'default' => "Hello,\n\n"
                ."We wanted to follow up on the upcoming service appointment for your property{property}.\n\n"
                ."Please let us know if you would like to be available at the appointment time to speak with the technician directly, or to approve the work order, and we will coordinate that with you.\n\n"
                .'Thank you!',
        ],
        'tenant_schedule_follow_up_no_date' => [
            'automation' => 'tenant_schedule_follow_up_sms',
            'label' => 'Reminder — no date on file',
            'group' => 'tenant_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'The appointment reminder text when the appointment has no scheduled date on file. The sign-off is added automatically below this text.',
            'tokens' => [
                'greeting' => 'Opening line — "Hi <first name>," or "Hi," when no name is on file',
            ],
            'required' => [],
            'sample' => [
                'greeting' => 'Hi Jane,',
            ],
            'collapse' => false,
            'default' => "{greeting}\n\n"
                ."This is TexasRenters.com Maintenance. This is a reminder about the upcoming service appointment for your home.\n\n"
                ."Please make sure someone 18 or older is home to let the technician in.\n\n"
                .'Thank you!',
        ],
        'tenant_schedule_follow_up_date' => [
            'automation' => 'tenant_schedule_follow_up_sms',
            'label' => 'Reminder — date only',
            'group' => 'tenant_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'The appointment reminder text when the appointment has a date but no time. The sign-off is added automatically below this text.',
            'tokens' => [
                'greeting' => 'Opening line — "Hi <first name>," or "Hi," when no name is on file',
                'date' => 'The appointment date, e.g. "Monday, August 24, 2026"',
            ],
            'required' => ['date'],
            'sample' => [
                'greeting' => 'Hi Jane,',
                'date' => 'Monday, August 24, 2026',
            ],
            'collapse' => false,
            'default' => "{greeting}\n\n"
                ."This is TexasRenters.com Maintenance. This is a reminder that your service appointment is set for {date}.\n\n"
                ."Please make sure someone 18 or older is home to let the technician in.\n\n"
                .'Thank you!',
        ],
        'tenant_schedule_follow_up_date_time' => [
            'automation' => 'tenant_schedule_follow_up_sms',
            'label' => 'Reminder — date and time',
            'group' => 'tenant_schedule_follow_up',
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'The appointment reminder text when the appointment has both a date and a time. The sign-off is added automatically below this text.',
            'tokens' => [
                'greeting' => 'Opening line — "Hi <first name>," or "Hi," when no name is on file',
                'date' => 'The appointment date, e.g. "Monday, August 24, 2026"',
                'time' => 'The appointment time, e.g. "3:00 PM"',
            ],
            'required' => ['date', 'time'],
            'sample' => [
                'greeting' => 'Hi Jane,',
                'date' => 'Monday, August 24, 2026',
                'time' => '3:00 PM',
            ],
            'collapse' => false,
            'default' => "{greeting}\n\n"
                ."This is TexasRenters.com Maintenance. This is a reminder that your service appointment is set for {date} at {time}.\n\n"
                ."Please make sure someone 18 or older is home to let the technician in.\n\n"
                .'Thank you!',
        ],
        'tenant_portal_link_sms' => [
            'automation' => 'tenant_portal_link_sms',
            'label' => 'Photo upload link',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'Texted to the tenant with their no-login upload link when photos would help resolve the request.',
            'tokens' => [
                'greeting' => 'Opening — "Hi <first name>, " or "Hi, " (keeps its trailing space; the sentence continues right after it)',
                'work_order_no' => 'The work order number',
                'link' => 'The secure no-login portal link (must stay in the message)',
            ],
            'required' => ['link'],
            'sample' => [
                'greeting' => 'Hi Jane, ',
                'work_order_no' => '43361',
                'link' => 'https://maintenance.texasrenters.com/t/abc123',
            ],
            'collapse' => false,
            'default' => '{greeting}this is TexasRenters.com Maintenance about your service request (WO#{work_order_no}). '
                .'To help us resolve it quickly, please upload photos of the issue using this secure link - no login needed: '
                ."{link}\n"
                .'(Ref: WO#{work_order_no})',
        ],
        'tenant_portal_link_reminder_sms' => [
            'automation' => 'tenant_portal_link_reminder_sms',
            'label' => 'Photo upload reminder',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'The reminder text when the tenant has not used their upload link yet.',
            'tokens' => [
                'greeting' => 'Opening — "Hi <first name>, " or "Hi, " (keeps its trailing space; the sentence continues right after it)',
                'work_order_no' => 'The work order number',
                'link' => 'The secure no-login portal link (must stay in the message)',
            ],
            'required' => ['link'],
            'sample' => [
                'greeting' => 'Hi Jane, ',
                'work_order_no' => '43361',
                'link' => 'https://maintenance.texasrenters.com/t/abc123',
            ],
            'collapse' => false,
            'default' => '{greeting}a quick reminder from TexasRenters.com Maintenance: please upload photos for your service request (WO#{work_order_no}) using this secure link - no login needed: '
                ."{link}\n"
                .'(Ref: WO#{work_order_no})',
        ],
        'tenant_job_reminder_14_day' => [
            'automation' => 'tenant_job_reminder_sms',
            'label' => '14 days before',
            'group' => 'tenant_job_reminder',
            'channel' => 'sms_email',
            'audience' => 'tenant',
            'sends_when' => 'The Jobber Tenant Benefit Package advance notice sent 14 days before the scheduled visit. The same text goes out by SMS and email.',
            'tokens' => [
                'CLIENT_NAME' => "The tenant's name as it appears on the Jobber client",
                'SCHEDULED_DATE' => 'The scheduled visit date',
            ],
            'required' => ['SCHEDULED_DATE'],
            'sample' => [
                'CLIENT_NAME' => 'Jane Doe',
                'SCHEDULED_DATE' => 'Monday, August 24, 2026',
            ],
            'collapse' => false,
            'default' => "Dear {CLIENT_NAME},\n\n"
                ."            As part of your Tenant Benefit Package (TBP), we have scheduled the following services on {SCHEDULED_DATE}:\n"
                ."                * Pest control treatment\n"
                ."                * Air filter replacement\n"
                ."                * Occupied inspection\n"
                ."            This advance notice is sent two weeks ahead so you have time to prepare, and reminders will follow closer to the date. Please note the following important details:\n"
                ."                * Access & Preparation: You do not need to be present during the visit. We will provide access to our technician. Please secure all valuables and crate any pets. If any areas are inaccessible, a trip charge may be applied in accordance with your lease agreement.\n"
                ."                * Timing: We cannot provide an exact arrival time, as our technicians have multiple appointments, and job durations may vary. However, the technician will call or notify you prior to arrival.\n"
                ."                * Body Cameras: For security and documentation purposes, our technicians wear body cameras during all visits.\n"
                ."                * Filter Access: Filters will only be replaced if they are unobstructed. Please ensure furniture or other items are moved beforehand to allow access.\n"
                ."                * Rescheduling: If the technician is unable to attend for any reason, we will promptly reschedule and notify you.\n"
                ."            Please confirm receipt of this notice and your approval by replying to this message. We appreciate your cooperation and understanding.\n\n"
                ."            Warm regards,\n"
                .'            TexasRenters.com, LLC',
        ],
        'tenant_job_reminder_7_day' => [
            'automation' => 'tenant_job_reminder_sms',
            'label' => '7 days before',
            'group' => 'tenant_job_reminder',
            'channel' => 'sms_email',
            'audience' => 'tenant',
            'sends_when' => 'The Jobber Tenant Benefit Package visit notice sent 7 days before the scheduled visit. The same text goes out by SMS and email.',
            'tokens' => [
                'CLIENT_NAME' => "The tenant's name as it appears on the Jobber client",
                'SCHEDULED_DATE' => 'The scheduled visit date',
            ],
            'required' => ['SCHEDULED_DATE'],
            'sample' => [
                'CLIENT_NAME' => 'Jane Doe',
                'SCHEDULED_DATE' => 'Monday, August 24, 2026',
            ],
            'collapse' => false,
            'default' => "Dear {CLIENT_NAME},\n\n"
                ."            As part of your Tenant Benefit Package (TBP), we have scheduled the following services on {SCHEDULED_DATE}:\n"
                ."                * Pest control treatment\n"
                ."                * Air filter replacement\n"
                ."                * Occupied inspection\n"
                ."            Please note the following important details:\n"
                ."                * Access & Preparation: You do not need to be present during the visit. We will provide access to our technician. Please secure all valuables and crate any pets. If any areas are inaccessible, a trip charge may be applied in accordance with your lease agreement.\n"
                ."                * Timing: We cannot provide an exact arrival time, as our technicians have multiple appointments, and job durations may vary. However, the technician will call or notify you prior to arrival.\n"
                ."                * Body Cameras: For security and documentation purposes, our technicians wear body cameras during all visits.\n"
                ."                * Filter Access: Filters will only be replaced if they are unobstructed. Please ensure furniture or other items are moved beforehand to allow access.\n"
                ."                * Rescheduling: If the technician is unable to attend for any reason, we will promptly reschedule and notify you.\n"
                ."            Please confirm receipt of this notice and your approval by replying to this message. We appreciate your cooperation and understanding.\n\n"
                ."            Warm regards,\n"
                .'            TexasRenters.com, LLC',
        ],
        'tenant_job_reminder_3_day' => [
            'automation' => 'tenant_job_reminder_sms',
            'label' => '3 days before',
            'group' => 'tenant_job_reminder',
            'channel' => 'sms_email',
            'audience' => 'tenant',
            'sends_when' => 'The Jobber Tenant Benefit Package visit reminder sent 3 days before the scheduled visit. The same text goes out by SMS and email.',
            'tokens' => [
                'CLIENT_NAME' => "The tenant's name as it appears on the Jobber client",
                'SCHEDULED_DATE' => 'The scheduled visit date',
            ],
            'required' => ['SCHEDULED_DATE'],
            'sample' => [
                'CLIENT_NAME' => 'Jane Doe',
                'SCHEDULED_DATE' => 'Monday, August 24, 2026',
            ],
            'collapse' => false,
            'default' => "Dear {CLIENT_NAME},\n\n"
                ."            This is a friendly reminder of the scheduled visit on {SCHEDULED_DATE} for the * Pest control treatment * Air filter replacement * Occupied inspection.\n\n"
                ."            Please note:\n\n"
                ."                * We are unable to provide an exact arrival time, as our technicians have multiple appointments and job durations may vary. The technician will call or notify you prior to arrival.\n"
                ."                * For safety and efficiency, please ensure all pets are secured in a crate or on a leash before the visit. Technicians will be unable to enter the property otherwise.\n"
                ."            Thank you for your cooperation. Should you have any questions, feel free to reach out to us\n\n"
                ."            Warm regards,\n"
                .'            TexasRenters.com, LLC',
        ],
        'tenant_job_reminder_1_day' => [
            'automation' => 'tenant_job_reminder_sms',
            'label' => 'Day before',
            'group' => 'tenant_job_reminder',
            'channel' => 'sms_email',
            'audience' => 'tenant',
            'sends_when' => 'The Jobber Tenant Benefit Package "visit tomorrow" reminder sent the day before the scheduled visit. The same text goes out by SMS and email.',
            'tokens' => [
                'CLIENT_NAME' => "The tenant's name as it appears on the Jobber client",
                'SCHEDULED_DATE' => 'The scheduled visit date',
            ],
            'required' => ['SCHEDULED_DATE'],
            'sample' => [
                'CLIENT_NAME' => 'Jane Doe',
                'SCHEDULED_DATE' => 'Monday, August 24, 2026',
            ],
            'collapse' => false,
            'default' => "Dear {CLIENT_NAME},\n\n"
                ."            This is a friendly reminder that your scheduled visit for the * Pest control treatment * Air filter replacement * Occupied inspection is tomorrow, {SCHEDULED_DATE}.\n\n"
                ."            Please note:\n\n"
                ."                * We are unable to provide an exact arrival time, as our technicians have multiple appointments and job durations may vary. The technician will call or notify you prior to arrival.\n"
                ."                * For safety and efficiency, please ensure all pets are secured in a crate or on a leash before the visit. Technicians will be unable to enter the property otherwise.\n"
                ."                * Please make sure air filters are unobstructed - kindly move any furniture or items blocking access beforehand.\n"
                ."            Thank you for your cooperation. Should you have any questions, feel free to reach out to us.\n\n"
                ."            Warm regards,\n"
                .'            TexasRenters.com, LLC',
        ],
        'tenant_tbp_visit_notice' => [
            'automation' => 'tenant_tbp_visit_notice_sms',
            'label' => 'Send notification button',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'tenant',
            'sends_when' => 'Texted only when staff press Send notification on a Tenant Benefit Package visit in the Scheduled Visits calendar - the notice for a visit the automated reminders did not reach. Never sent on a schedule; the person sending picks the recipients.',
            'tokens' => [
                'SCHEDULED_DATE' => 'The scheduled visit date',
            ],
            'required' => ['SCHEDULED_DATE'],
            'sample' => [
                'SCHEDULED_DATE' => 'Wednesday, August 26, 2026',
            ],
            'collapse' => false,
            'default' => "Good day,\n\n"
                ."As part of your Tenant Benefit Package (TBP), we have scheduled the following services {SCHEDULED_DATE}:\n"
                ."* Pest control treatment\n"
                ."* Air filter replacement\n"
                ."* Occupied inspection\n"
                ."Please note the following important details:\n"
                ."* Access & Preparation: You do not need to be present during the visit. We will provide access to our technician. Please secure all valuables and crate any pets. If any areas are inaccessible, a trip charge may be applied in accordance with your lease agreement.\n"
                ."* Timing: We cannot provide an exact arrival time, as our technicians have multiple appointments, and job durations may vary. However, the technician will call or notify you prior to arrival.\n"
                ."* Body Cameras: For security and documentation purposes, our technicians wear body cameras during all visits.\n"
                ."* Filter Access: Filters will only be replaced if they are unobstructed. Please ensure furniture or other items are moved beforehand to allow access.\n"
                ."* Rescheduling: If the technician is unable to attend for any reason, we will promptly reschedule and notify you.\n"
                ."* Please note that if the technicians are unable to access the property upon arrival, a trip charge will apply.\n\n"
                ."Please confirm receipt of this notice. If we do not receive a response, we will assume the property will be available and accessible on the scheduled date. We appreciate your cooperation and understanding. Please feel free to reach out with any questions.\n\n"
                ."Warm regards,\n"
                .'TexasRenters, LLC',
        ],
        'vendor_jobber_assignment_sms' => [
            'automation' => 'vendor_jobber_assignment_sms',
            'label' => 'Job assigned',
            'group' => null,
            'channel' => 'sms',
            'audience' => 'vendor',
            'sends_when' => 'Texted to the vendor when a Jobber job is assigned to them.',
            'tokens' => [
                'job_number' => 'The Jobber job number',
                'title' => "The job's title",
                'property_clause' => '" at <property address>" or empty when the job has no address',
                'portal_clause' => '". Details and photo/invoice upload: <portal link>" or empty when no portal link could be issued',
            ],
            'required' => ['job_number'],
            'sample' => [
                'job_number' => '1042',
                'title' => 'TBP Visit',
                'property_clause' => ' at 123 Main St',
                'portal_clause' => '. Details and photo/invoice upload: https://maintenance.texasrenters.com/v/abc123',
            ],
            'collapse' => false,
            'default' => 'You have been assigned Job #{job_number} - {title}{property_clause}{portal_clause}',
        ],
    ];

    /**
     * The resolved body for a template: the stored override when one exists,
     * else the registry default, with {tokens} substituted in a single pass.
     */
    public static function text(string $key, array $tokens = []): string
    {
        $template = self::raw($key);

        if ($tokens !== []) {
            $replacements = [];

            foreach ($tokens as $name => $value) {
                $replacements['{'.$name.'}'] = (string) $value;
            }

            $template = strtr($template, $replacements);
        }

        if (self::TEMPLATES[$key]['collapse']) {
            $template = trim((string) preg_replace("/\n{3,}/", "\n\n", $template));
        }

        return $template;
    }

    /**
     * The override-or-default body WITHOUT token substitution — for senders
     * that substitute later themselves (SendJobReminders replaces per
     * recipient).
     */
    public static function raw(string $key): string
    {
        $entry = self::TEMPLATES[$key] ?? null;

        if ($entry === null) {
            throw new \LogicException("Unknown automated message template [{$key}].");
        }

        $override = self::overrides()[$key] ?? null;

        return is_string($override) && trim($override) !== '' ? $override : $entry['default'];
    }

    public static function default(string $key): string
    {
        $entry = self::TEMPLATES[$key] ?? null;

        if ($entry === null) {
            throw new \LogicException("Unknown automated message template [{$key}].");
        }

        return $entry['default'];
    }

    /**
     * The stored override map. Fails OPEN — an unreadable or corrupt row must
     * mean "all defaults", never a crashed sender.
     *
     * @return array<string, mixed>
     */
    public static function overrides(): array
    {
        try {
            $stored = AppSetting::getValue(self::KEY, []);
        } catch (\Throwable $exception) {
            Log::warning('Automated message templates unreadable; using the default wording.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        return is_array($stored) ? $stored : [];
    }

    public static function isOverridden(string $key): bool
    {
        $override = self::overrides()[$key] ?? null;

        return is_string($override) && trim($override) !== '';
    }

    public static function put(string $key, string $text): void
    {
        $overrides = self::overrides();
        $overrides[$key] = $text;

        AppSetting::putValue(self::KEY, $overrides);
    }

    /**
     * Back to the default wording: absence from the map means default.
     */
    public static function reset(string $key): void
    {
        $overrides = self::overrides();
        unset($overrides[$key]);

        AppSetting::putValue(self::KEY, $overrides);
    }

    /**
     * The ordered template keys of a variant family, e.g.
     * variants('vendor_schedule_follow_up_variant_') => the five rotation keys.
     * Registry order is the rotation order.
     *
     * @return list<string>
     */
    public static function variants(string $prefix): array
    {
        return array_values(array_filter(
            array_keys(self::TEMPLATES),
            fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }

    /**
     * Everything the Message Templates editor needs, one row per template in
     * registry order.
     *
     * @return array{templates: list<array<string, mixed>>}
     */
    public static function forUi(): array
    {
        return ['templates' => array_values(array_map(
            fn (string $key): array => self::uiEntry($key),
            array_keys(self::TEMPLATES),
        ))];
    }

    /**
     * One editor row: the registry entry plus the current override state.
     *
     * @return array<string, mixed>
     */
    public static function uiEntry(string $key): array
    {
        $entry = self::TEMPLATES[$key];
        $override = self::overrides()[$key] ?? null;
        $override = is_string($override) && trim($override) !== '' ? $override : null;

        return [
            'key' => $key,
            'automation' => $entry['automation'],
            'automation_label' => AutomatedMessageLogService::AUTOMATIONS[$entry['automation']] ?? $entry['automation'],
            'label' => $entry['label'],
            'group' => $entry['group'],
            'channel' => $entry['channel'],
            'audience' => $entry['audience'],
            'sends_when' => $entry['sends_when'],
            'tokens' => array_map(
                fn (string $name): array => ['name' => $name, 'description' => $entry['tokens'][$name]],
                array_keys($entry['tokens']),
            ),
            'required' => $entry['required'],
            'sample' => $entry['sample'],
            'default' => $entry['default'],
            'override' => $override,
            'is_overridden' => $override !== null,
        ];
    }

    /**
     * {placeholders} used in $text that the template does not declare — the
     * typo guard: an unknown token would go out to the recipient literally.
     *
     * @return list<string>
     */
    public static function unknownTokens(string $key, string $text): array
    {
        preg_match_all('/\{([A-Za-z_]+)\}/', $text, $matches);

        return array_values(array_diff(
            array_unique($matches[1]),
            array_keys(self::TEMPLATES[$key]['tokens']),
        ));
    }

    /**
     * Declared required tokens missing from $text — e.g. a portal-link message
     * without its {link}.
     *
     * @return list<string>
     */
    public static function missingRequiredTokens(string $key, string $text): array
    {
        return array_values(array_filter(
            self::TEMPLATES[$key]['required'],
            fn (string $token): bool => ! str_contains($text, '{'.$token.'}'),
        ));
    }

    /**
     * Characters that would push an SMS out of the GSM-7 alphabet — em dashes,
     * curly quotes, and friends. Twilio rejects such texts outright on this
     * account (error 30019, undelivered at double the cost), so every template
     * body must stay GSM-7: printable ASCII, newlines, and the GSM accented
     * set.
     *
     * @return list<string> unique offending characters, in order of appearance
     */
    public static function nonGsmCharacters(string $text): array
    {
        $allowedBeyondAscii = '£¥èéùìòÇØøÅåÆæßÉÄÖÑÜäöñüà€§¿¡ΔΦΓΛΩΠΨΣΘΞ';

        preg_match_all('/[^\x20-\x7E\n\r]/u', $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $char): bool => ! str_contains($allowedBeyondAscii, $char),
        )));
    }
}
