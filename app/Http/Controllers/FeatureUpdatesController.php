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
            'date' => '2026-08-20',
            'area' => 'Owners',
            'title' => 'Owners get the appointment text automatically',
            'description' => 'When a vendor books the service appointment through their portal, the owner is now texted the standard appointment message automatically — property address, vendor, date, and the ask to stay reachable in case extra repairs need approval. It goes out at most once per work order, so a second appointment (say, after an estimate is approved) stays silent. Appointments set by a coordinator do not auto-text — use the insert button on the owner tab for those. The per-work-order owner automation Off switch is respected as usual.',
        ],
        [
            'date' => '2026-08-20',
            'area' => 'Owners',
            'title' => 'One-click "appointment scheduled" message for owners',
            'description' => 'The owner conversation tab now has an "Insert appointment scheduled message" button under the thread. One click fills the composer with the standard owner appointment wording — property address, vendor and the latest scheduled date filled in automatically — and you review and hit Send as usual. Nothing is ever sent on its own, and if the work order has no service schedule yet the button tells you instead of inserting a dateless message.',
        ],
        [
            'date' => '2026-08-20',
            'area' => 'Vendor Portal',
            'title' => 'Vendors can send PDFs in the portal chat',
            'description' => 'Vendors kept telling us they "can\'t upload PDFs". The upload forms actually take PDFs fine — the places that tripped them up are now fixed. The message-to-coordinator box only accepted photos, so a vendor trying to send a PDF (usually their invoice) through the chat was stuck; it now takes PDFs up to 10 MB, and the attach button shows a paperclip instead of a camera so it reads as "any file". On the invoice form, the Submit button silently stays greyed out until an amount is entered — there is now a hint under the button saying exactly that, so an attached PDF no longer looks broken. And on the Jobber portal, the invoice title is no longer required: leave it blank and the file name is used, same as the main portal.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'HOA Violations',
            'title' => 'HOA violation texts reach the tenant again',
            'description' => 'HOA violation work orders created from an uploaded notice were coming back from PropertyWare without a requesting tenant, so the photo-link text and the daily reminders were silently skipped — the tenant never heard from us while the violation still escalated to "needs vendor". The tenant on the lease is now linked automatically when the work order is created (and a repair pass fixes the existing ones), so the texts go out. Also: when a notice arrives with an HOA deadline that has already passed, the tenant now gets at least two business days to self-fix before staff are flagged to send a vendor.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'Inbox',
            'title' => 'The Inbox works more like Messenger',
            'description' => 'Four quality-of-life changes. The list shows the 50 newest conversations and quietly loads older ones as you scroll — no more hard wall. The red badge on the Messages nav now counts only conversations with messages YOU have not opened yet, so it drains as you read instead of sitting at the whole backlog (the workload number still lives on the Awaiting reply filter). The Summary button now reports on the conversations currently on your screen — your active tab, filter and search — instead of the all-time queue. And message chips got smarter: a new violet "Approved" chip for go-aheads ("please proceed", "place the service call"), short replies like "yes" are read in the context of what we asked, and the old Question chip is now called "Needs reply".',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'Inbox',
            'title' => 'The Inbox tells you what each message wants',
            'description' => 'Threads in the Inbox now carry a small chip on their newest unanswered message — Reschedule, Complaint, Access issue, Job done, Question, or Confirmed — read by AI every few minutes, so you can see what a thread needs without opening it. Hover the chip for a one-line summary. Purely informational: nothing is sent and nothing changes on the work order.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'Scheduling',
            'title' => 'Appointment suggestions pulled from texts',
            'description' => 'When a tenant or vendor texts a concrete day or time ("Tuesday after 2 works"), it now appears as a suggestion card on the work order\'s Service Schedule tab. "Use" pre-fills the normal schedule form with that date — you still pick the vendor and hit save, so PropertyWare sync and the tenant notification work exactly as always. Dismissed suggestions stay dismissed.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'Vendors',
            'title' => 'AI double-checks vendor "after" photos',
            'description' => 'When a vendor uploads an after photo, AI compares it against the reported issue. Photos that don\'t seem to match, or are too unclear to tell, get a small staff-only flag on the Attachments tab — worth a look before the work order moves toward payment. Clean photos show nothing, vendors never see the flags, and uploads are never blocked.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'Reports',
            'title' => 'The over-30-days report explains why',
            'description' => 'Each row on the "Open WOs Over 30 Days" report has an Analyze button: AI reads the work order\'s messages, notes, tasks and schedules and answers two things — why it looks stuck, and the single next step to move it. One click per work order, answers are kept for a day.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'Search',
            'title' => 'Search remembers your recent lookups',
            'description' => 'The search dialog (Ctrl+K) now shows your last 10 searches when you open it, including any property filter. Click one to run it again, hit the X to drop a single entry, or Clear to wipe the list. The list is yours alone — it is kept in your browser per login and never shared between accounts.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'System',
            'title' => 'Choose the AI engine from IT Tools',
            'description' => 'Admins can now pick which AI provider and model power the work order classifier, vendor picker, HOA notice reader, board summaries and inbox reports — IT Tools → AI Settings in the sidebar. Paste a provider\'s API key right on the page, hit Test to try the choice live, then Save; changes apply immediately with no deploy. Reset puts everything back to the server default.',
        ],
        [
            'date' => '2026-08-19',
            'area' => 'System',
            'title' => 'IT Tools links grouped in the sidebar',
            'description' => 'Jobber, Automated Messages and AI Settings now sit together under one collapsible IT Tools menu in the sidebar instead of separate links, so the Settings section stays short as more tools are added.',
        ],
        [
            'date' => '2026-08-15',
            'area' => 'Jobber',
            'title' => 'Sync Jobber actually works again',
            'description' => 'The "Sync Jobber" button on the Jobs page had been failing since it was added, so any job that never arrived through Jobber automatically — for example one created while the Jobber connection was down — had no way to reach the dashboard. It now works, and it starts straight away instead of freezing the page: you get a "Sync Started" message and the jobs and visits appear as they come in. Give it a few minutes on a big catch-up, then refresh.',
        ],
        [
            'date' => '2026-08-15',
            'area' => 'HOA',
            'title' => 'Photographed HOA notices reach PropertyWare',
            'description' => 'An HOA violation notice uploaded as a phone photo instead of a PDF now lands in the PropertyWare work order\'s Notes & Docs like every other notice. Before, it showed on the work order here but was never copied over. The nightly check that re-sends anything PropertyWare missed now covers notices too, so a lost one repairs itself by the next morning.',
        ],
        [
            'date' => '2026-08-14',
            'area' => 'Vendors',
            'title' => 'Send assignment info to a vendor by hand',
            'description' => 'The vendor conversation tab has a "Send assignment info" button next to the automation switch. It sends the vendor the same assignment email (with the work order PDF and their portal link) and text the system sends automatically — useful when the vendor was assigned in PropertyWare, or when their email or phone number was added after the assignment. It tells you if the vendor has no contact details on file.',
        ],
        [
            'date' => '2026-08-14',
            'area' => 'Messaging',
            'title' => 'Vacant properties stop getting automated messages',
            'description' => 'Work orders on homes with no active lease in PropertyWare — new to market or between tenants — no longer send the automated tenant texts and emails (appointment, follow-ups, photo-link) or the owner "new request" text. No toggle needed: the system reads the lease straight from PropertyWare, and if the lease appears later the messages resume on their own.',
        ],
        [
            'date' => '2026-08-12',
            'area' => 'Jobber',
            'title' => 'Day-before reminder for TBP visits',
            'description' => 'Tenants with a Tenant Benefit Package visit scheduled for tomorrow now get a final courtesy text and email at 8:00 AM the day before, on top of the existing 7-day and 3-day notices. The automation switches on the Jobber pages cover it.',
        ],
        [
            'date' => '2026-08-12',
            'area' => 'Jobber',
            'title' => 'Automation switches on the Jobber pages',
            'description' => 'The Jobber pages have a new Automations control in the header: a master switch plus individual switches for the tenant visit reminders and vendor assignment messages, so any of them can be paused instantly without IT.',
        ],
        [
            'date' => '2026-08-12',
            'area' => 'Vendors',
            'title' => 'Photo descriptions now optional for vendors',
            'description' => 'Vendors can upload work photos without typing a description — the system fills in a sensible title automatically, so uploads are no longer blocked by the description box.',
        ],
        [
            'date' => '2026-08-12',
            'area' => 'Tenants',
            'title' => 'Simpler tenant appointment texts',
            'description' => 'The appointment confirmation and reminder texts no longer invite tenants to reply for a reschedule — they simply state the date and time and ask that someone 18 or older is home.',
        ],
        [
            'date' => '2026-08-11',
            'area' => 'Attachments',
            'title' => 'Photos reach PropertyWare reliably again',
            'description' => 'Work order photos upload to PropertyWare dependably again, and a repair sweep re-uploaded the ones that had silently failed.',
        ],
        [
            'date' => '2026-08-11',
            'area' => 'HOA',
            'title' => 'HOA notice PDFs file into PropertyWare',
            'description' => 'The violation notice PDF now lands in the PropertyWare work order\'s Documents section automatically when an HOA work order is created.',
        ],
        [
            'date' => '2026-08-08',
            'area' => 'Messaging',
            'title' => 'Text reactions show as reaction chips',
            'description' => 'When a tenant reacts to a text with a thumbs-up, heart, or similar, the conversation shows it as a small reaction chip on the original message instead of a confusing "Liked ..." text.',
        ],
        [
            'date' => '2026-08-08',
            'area' => 'Work Orders',
            'title' => 'Open in Jobber from every work order window',
            'description' => 'The "Open in Jobber" button now sits beside the PropertyWare button in every work order pop-up — the board, Closed, Lawn Care, Inspections, Paid, Waiting on Payment, the bell and search — and on the vendor Work Orders page for the in-house crew. It only appears on work orders assigned to Texas Home Maintenance Pros, since those are the ones with a Jobber job.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Tenant Portal',
            'title' => 'Tenants can open a new request themselves',
            'description' => 'The tenant portal has a "Report a new issue" button: the tenant describes the problem, adds photos, and a brand new work order is created in PropertyWare and lands on the board like any other. It arrives labelled "Tenant Portal", the notification bell announces it, and the tenant is taken to their new request\'s own portal page.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Attachments',
            'title' => 'Tenant photos land on the Attachments tab',
            'description' => 'Photos a tenant sends — by text reply or through their portal chat — now appear on the work order\'s Attachments tab as Before Pictures, and the tab shows a number badge for new arrivals until someone opens it.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Messaging',
            'title' => 'Thank-you replies no longer count as awaiting',
            'description' => 'When a conversation ends with a plain "thank you", it stops counting toward the Messages badge and the awaiting-reply lists. Anything that could be a real question or answer still counts and is checked with full context.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Notifications',
            'title' => 'Notification bell holds the latest 1000',
            'description' => 'The bell now keeps your latest 1000 notifications (up from 100), loading them in pages of 100 with a Show more button.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Vendors',
            'title' => 'Vendor work orders as a board',
            'description' => 'The vendor Work Orders page is now a kanban board with one column per status, like the staff board, plus a "Hide cities" filter that remembers each person\'s choices.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'HOA',
            'title' => 'HOA violation sync fix',
            'description' => 'HOA violation uploads reliably create their PropertyWare work order again. If PropertyWare cannot hand back a work order number, the upload warns you instead of failing quietly, and the stray unsynced entries from the outage were cleaned up.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Messaging',
            'title' => 'Message cards back to the classic layout',
            'description' => 'Conversation bubbles show the To and From numbers and the full date and time again, and stay readable in dark mode.',
        ],
        [
            'date' => '2026-08-07',
            'area' => 'Owners',
            'title' => 'Owner portal highlights the work description',
            'description' => 'The owner portal presents the work order description in its own highlighted card, so owners see what the job is at a glance.',
        ],
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
