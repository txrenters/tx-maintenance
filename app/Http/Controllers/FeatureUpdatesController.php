<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff-facing "What's New" page: a curated changelog of every update shipped
 * to the maintenance system, newest first.
 *
 * To announce a new update, add an entry to the TOP of the UPDATES list when
 * the feature reaches production. Keep descriptions in plain staff language —
 * what changed and what it does for them, no internals.
 */
class FeatureUpdatesController extends Controller
{
    /**
     * @var array<int, array{date: string, area: string, title: string, description: string}>
     */
    private const UPDATES = [
        [
            'date' => '2026-08-06',
            'area' => 'Messaging',
            'title' => 'Inbox unread indicators',
            'description' => 'Conversations you have not read yet show an unread dot, and the Inbox has an Unread filter. Message times are minute-precise ("5 mins ago") and the sender\'s phone number appears beside their name.',
        ],
        [
            'date' => '2026-08-06',
            'area' => 'System',
            'title' => 'Automated Messages log',
            'description' => 'New IT Tools page listing every automated text and email the system sends to owners, tenants, and vendors — audience, channel, automation, recipient, work order, and the exact date and time.',
        ],
        [
            'date' => '2026-08-06',
            'area' => 'Owners',
            'title' => 'Owner appointment notification (off for now)',
            'description' => 'When a vendor schedules a visit, the system can text the owner the appointment date. The wording is ready; the send stays switched off until it gets sign-off.',
        ],
        [
            'date' => '2026-08-06',
            'area' => 'Owners',
            'title' => 'Approval asked only on new work orders',
            'description' => 'The owner portal\'s Approve / Don\'t approve buttons now appear only while a work order is still New, so owners are not asked to approve work already underway.',
        ],
        [
            'date' => '2026-08-06',
            'area' => 'Turnovers',
            'title' => 'No automated tenant texts on turnover work',
            'description' => 'Turnover, vacant, RE-KEY, and REFRESH-CLEANING work orders no longer send automated tenant messages or the owner intake text.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'Messaging',
            'title' => 'Chat-style Inbox',
            'description' => 'Messages → Inbox shows every conversation as a chat-style thread, with an AI-built report of conversations still waiting on a reply.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'Owners',
            'title' => 'Owner portal',
            'description' => 'Owners get a magic-link portal showing their work order\'s messages and photos — no login needed. The link rides along in the owner texts, and new work orders show Approve / Don\'t approve buttons.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'HOA',
            'title' => 'Tenant photo replies pause HOA reminders',
            'description' => 'When a tenant texts photos back on an HOA violation, the daily reminder stops and the conversation is flagged for staff review.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'Work Orders',
            'title' => 'Maintenance board import fix',
            'description' => 'Work orders imported without a type no longer disappear from the board, and the importer itself was repaired.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'Work Orders',
            'title' => 'Alert when a description changes in PropertyWare',
            'description' => 'If a work order\'s description is edited in PropertyWare after intake, staff get a bell alert so nothing changes silently.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'System',
            'title' => 'Jobber connection hardening',
            'description' => 'The diagnostics page that could break the Jobber connection is now admin-only, and a new IT Tools page shows connection health with retry buttons for failed job creations.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'Tenants',
            'title' => 'Tenant portal everywhere',
            'description' => 'The tenant photo portal now matches the owner portal, and a portal link is included in every tenant text and the intake email.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'System',
            'title' => 'Property address accuracy',
            'description' => 'Texts and pages now use the building\'s PropertyWare address instead of the tenant\'s mailing address, which could point somewhere else entirely.',
        ],
        [
            'date' => '2026-08-05',
            'area' => 'Work Orders',
            'title' => 'PropertyWare documents preview inline',
            'description' => 'PDFs synced from PropertyWare now open in the preview dialog instead of forcing a download.',
        ],
        [
            'date' => '2026-07-30',
            'area' => 'Owners',
            'title' => 'Owner intake text reworded',
            'description' => 'The text owners receive when a work order is created no longer says "submitted by your tenant" — it simply reports the new work order.',
        ],
        [
            'date' => '2026-07-30',
            'area' => 'Work Orders',
            'title' => 'Task due dates follow the schedule',
            'description' => 'When a vendor visit is rescheduled, the work order\'s task due dates re-anchor to the new date automatically.',
        ],
        [
            'date' => '2026-07-29',
            'area' => 'Messaging',
            'title' => 'Any phone video accepted',
            'description' => 'Tenants can upload videos in any phone format (.3gp included), not just the common ones.',
        ],
        [
            'date' => '2026-07-29',
            'area' => 'HOA',
            'title' => 'HOA text lists the corrective actions',
            'description' => 'The tenant HOA text now lists the corrective actions from PropertyWare as a numbered, de-duplicated list instead of one long sentence.',
        ],
        [
            'date' => '2026-07-29',
            'area' => 'Work Orders',
            'title' => 'Attachment previews',
            'description' => 'Any PDF or image on the Attachments and Invoice tabs opens in a preview dialog instead of downloading.',
        ],
        [
            'date' => '2026-07-29',
            'area' => 'Reports',
            'title' => 'Open Over 30 Days report fixed',
            'description' => 'The month filter is cumulative and ages are counted against today, so the report finally matches reality.',
        ],
        [
            'date' => '2026-07-28',
            'area' => 'Work Orders',
            'title' => 'Repeat issues auto-assign the last vendor',
            'description' => 'When intake detects a repeat of a recent issue at the same property, the vendor who handled it last time is assigned automatically.',
        ],
        [
            'date' => '2026-07-28',
            'area' => 'Jobber',
            'title' => 'External client jobs',
            'description' => 'Jobs for non-TexasRenters clients now live end-to-end on the Jobber page — vendor assignment, photos, and invoices — without ever becoming work orders.',
        ],
        [
            'date' => '2026-07-25',
            'area' => 'HOA',
            'title' => 'HOA violations, end to end',
            'description' => 'Upload an HOA violation PDF and the system creates the PropertyWare work order, writes an AI description, offers the tenant an Easy Fix photo flow, sends daily reminders, and escalates when ignored.',
        ],
        [
            'date' => '2026-07-25',
            'area' => 'Messaging',
            'title' => 'Per-work-order automation switch',
            'description' => 'Every conversation tab has an On/Off switch that mutes all automated messages for that one work order.',
        ],
        [
            'date' => '2026-07-25',
            'area' => 'Tenants',
            'title' => 'Tenant told when a vendor is assigned',
            'description' => 'Tenants get an automatic text the moment a third-party vendor takes their work order.',
        ],
        [
            'date' => '2026-07-25',
            'area' => 'Tenants',
            'title' => '"Did the vendor reach out?" follow-up',
            'description' => 'A daily follow-up text asks the tenant whether the vendor has made contact, capped at five sends per work order.',
        ],
        [
            'date' => '2026-07-25',
            'area' => 'Tenants',
            'title' => 'No-login photo portal',
            'description' => 'Tenants get a portal link to add photos to their work order without logging in — sent automatically on Easy Fix requests, with capped reminders.',
        ],
        [
            'date' => '2026-07-25',
            'area' => 'Turnovers',
            'title' => 'Turnover invoices notify accounting',
            'description' => 'Uploading an invoice on a turnover work order automatically emails the accounting mailbox.',
        ],
        [
            'date' => '2026-07-23',
            'area' => 'Owners',
            'title' => 'Vacant property wording',
            'description' => 'Owner texts about vendor assignment drop the "vendor will contact your tenant" line whenever the property is vacant.',
        ],
        [
            'date' => '2026-07-22',
            'area' => 'System',
            'title' => 'Board stability',
            'description' => 'The main boards no longer crash with a blank error under heavy data — card payloads were slimmed down.',
        ],
        [
            'date' => '2026-07-21',
            'area' => 'Turnovers',
            'title' => 'Turnover task checklist',
            'description' => 'Turnover work orders get their own six-task template set, and task templates are now aware of the work order type.',
        ],
        [
            'date' => '2026-07-21',
            'area' => 'Turnovers',
            'title' => 'Vendor emails from the THMP mailbox',
            'description' => 'Turnover vendor emails send from the THMP mailbox, and vendor replies sync back into the conversation.',
        ],
        [
            'date' => '2026-07-21',
            'area' => 'Vendors',
            'title' => 'Readable vendor portal links',
            'description' => 'Vendor portal links now include the work order number and property address, so vendors can tell links apart. Old links keep working.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Work Orders',
            'title' => 'Repeat issue detection',
            'description' => 'Intake flags work orders that look like a repeat of a recent issue at the same property and badges the card on the board.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Vendors',
            'title' => 'Vendor scheduling nudges (off by default)',
            'description' => 'A daily text can nudge an assigned vendor until a visit is scheduled — built and ready, currently switched off.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Work Orders',
            'title' => 'Editable work order type',
            'description' => 'Admins and coordinators can change a work order\'s type from a dropdown, and the change syncs to PropertyWare.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Jobber',
            'title' => 'THMP jobs created automatically',
            'description' => 'Assigning THMP to a work order creates the matching Jobber job straight from the app.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Owners',
            'title' => 'Owner texts reach every owner',
            'description' => 'The owner texts now go to every owner on the property, de-duplicated by phone number, instead of just the first one.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Work Orders',
            'title' => 'HVAC category fix',
            'description' => 'HVAC work orders now land in the correct PropertyWare category.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Vendors',
            'title' => 'Assign a vendor at any stage',
            'description' => 'The vendor picker no longer disappears once a work order moves past New — vendors can be assigned or changed at any point.',
        ],
        [
            'date' => '2026-07-18',
            'area' => 'Jobber',
            'title' => 'Shift+Enter makes a new line',
            'description' => 'In Jobber messages, Enter sends and Shift+Enter starts a new line instead of sending mid-thought.',
        ],
        [
            'date' => '2026-07-11',
            'area' => 'Work Orders',
            'title' => 'Emergency classification',
            'description' => 'AI labels every incoming work order Emergency or Standard at intake, alerts staff on emergencies, and adds an Emergency filter to the board.',
        ],
        [
            'date' => '2026-07-11',
            'area' => 'Work Orders',
            'title' => 'Board keeps your place',
            'description' => 'Moving a card no longer snaps the board back to the top — your scroll position holds and the moved card flashes so you can find it.',
        ],
        [
            'date' => '2026-07-11',
            'area' => 'Work Orders',
            'title' => 'Color filter',
            'description' => 'Filter the board by the Blue / Green / Red card colors.',
        ],
    ];

    /**
     * Release dates of every update, newest first. Shared with the layout so
     * the sidebar can badge how many updates landed since the user last
     * opened the page (last-seen is remembered in the browser).
     *
     * @return array<int, string>
     */
    public static function updateDates(): array
    {
        $dates = array_column(self::UPDATES, 'date');

        rsort($dates);

        return $dates;
    }

    public function index(Request $request): Response
    {
        $this->authorizeStaff($request);

        $updates = collect(self::UPDATES)
            ->sortByDesc('date')
            ->values();

        return Inertia::render('FeatureUpdates/Index', [
            'title' => "What's New",
            'updates' => $updates->all(),
            'areas' => $updates->pluck('area')->unique()->sort()->values()->all(),
        ]);
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();

        abort_unless((bool) $user?->hasAnyRole(['admin', 'woc']), 403);
    }
}
