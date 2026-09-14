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
            'date' => '2026-09-12',
            'area' => 'Messaging',
            'title' => 'THMP appointment texts no longer tell the tenant to be home',
            'description' => 'The automated "appointment scheduled" text to the tenant ended with "Please make sure someone 18 or older is home to let the technician in." whoever the vendor was. For a visit by Texas Home Maintenance Pros that line was wrong - THMP technicians have their own access and the tenant does not need to be there - and a tenant could point to it later to dispute a trip charge for not being home. When the appointment on the service schedule is with THMP, the text now leaves that line out and reads: the appointment has been scheduled with Texas Home Maintenance Pros, the date, and thank you. Appointments with any other vendor keep the line exactly as before. On the Automated Messages page the line is now the {access_line} token of the "Appointment scheduled" tenant template, so it can be reworded there, and it stays empty for THMP whatever the wording. The "THMP technician visit" text that goes out when a technician is picked on the schedule is unchanged.',
        ],
        [
            'date' => '2026-09-11',
            'area' => 'Work Orders',
            'title' => 'THMP\'s own login no longer sees Jimmie Gendke SFA\'s work orders under the Texas Home Maintenance Pros filter',
            'description' => 'When a work order is really Jimmie Gendke SFA\'s, Texas Home Maintenance Pros is tagged on it as well only so the Jobber job gets created - so those work orders were filling THMP\'s own view whenever the Vendor filter was set to Texas Home Maintenance Pros. For THMP\'s login (John Carlo) only, that filter now leaves out any work order that also carries Jimmie Gendke SFA, on the Active, Inspections, Lawn Service, HOA Violations, Closed, Waiting on Payment and Paid boards, in the Summary popup and in the export, so the figures match the cards; picking Jimmie Gendke SFA still shows them. Coordinators and everyone else see every work order under the Texas Home Maintenance Pros filter exactly as before, since they still process those jobs and message the tenant. Nothing changes for the board with no filter or for any other vendor.',
        ],
        [
            'date' => '2026-09-11',
            'area' => 'Messaging',
            'title' => 'OWNER VENDOR is never texted or emailed as a vendor',
            'description' => 'Assigning "OWNER VENDOR" to a work order means the owner is handling the repair themselves - it is a placeholder, not a company. The owner and tenant texts already knew that, but the vendor\'s own assignment email and text did not, and on WO #44092 the text reached a real person. The placeholder has no contact details of its own; it showed a phone number because every vendor with no email in PropertyWare (about 3,400 of them, OWNER VENDOR included) had been sharing one contact record behind the scenes, so whichever number was saved last showed on all of them. Now OWNER VENDOR gets no assignment email or text, whether automatic or from the "Send assignment info" button, and each vendor brought in from PropertyWare keeps its own contact record, so the number shown on a vendor is that vendor\'s. The Sunday vendor refresh moves existing vendors onto their own records; "Sync from PropertyWare" on the Vendors page does it for one vendor straight away.',
        ],
        [
            'date' => '2026-09-11',
            'area' => 'Tenants',
            'title' => 'Tenants are told about a work order our team enters even when the requester is not the tenant',
            'description' => 'The "we have received your service request" and "a work order has been created for your home by our team" text and email went only to the contact PropertyWare lists under Requested By. On a work order our team enters - an inspection finding, a call-in - that contact is often the technician or staff member who logged it, or nobody at all, so the tenant heard nothing and there was no trace of the skip: on WO #44111 (Source "Inspection") the Requested By contact was the technician, with no phone number, and the owner got the created-by-our-team text while the tenant did not. The messages now go to the tenants on the property\'s lease whenever the Requested By contact is not one of them or has no number: everyone on the lease with a phone number gets the text (one text per number, however many names share it) and everyone with a real email address gets the email, each greeted by their own first name. A request the tenant sent themselves still goes to that tenant alone. When nobody on the work order has a phone number, the Automated Messages page now shows a "Not sent: nobody to text" row for the work order, naming the Requested By contact and how many lease tenants were on file, instead of silence. Nothing is sent again for work orders already on the board - WO #44111 stays a manual send from the Tenant tab.',
        ],
        [
            'date' => '2026-09-11',
            'area' => 'Owners',
            'title' => 'The onboarding form no longer fails on the sprinkler question',
            'description' => 'An owner who answered "No sprinkler system" could not submit the management onboarding form at all: it stopped at 92% with "Submission Failed - Yard Features (No Sprinkler System is invalid option)" every time, and nothing was saved - no answers, no onboarding PDF, no W-9 - however many times they tried (1309 Martin). "Yard Features" in PropertyWare is a picklist that takes only Lawn Irrigation, Sprinkler System or Not Applicable, and the form was sending "No Sprinkler System", which is not one of them, so PropertyWare rejected the whole submission. The question now offers PropertyWare\'s own three options and saves the one the owner picked, word for word - "Yes - Has a sprinkler system", "Yes - Has lawn irrigation" or "No sprinkler or irrigation system" - so there is no wording of ours in between for PropertyWare to reject. The question opens on "No sprinkler or irrigation system", since most homes have neither; if the property already has an answer in PropertyWare, that is what shows instead. The sprinkler part now asks only whether the home has one: the Sprinkler Controller Location and Sprinkler Notes boxes are gone from the form and from the onboarding PDF.',
        ],
        [
            'date' => '2026-09-10',
            'area' => 'Work Orders',
            'title' => 'Task cards and visit pop-ups open their work order',
            'description' => 'On the Tasks page, the work order number at the top of every card (#43445 and so on, in the Past Due, Due Today and Pending columns) is now a link: click it and that work order opens in the usual pop-up - details, notes, invoices, conversations and the rest - without leaving the board, so a task can be checked against its order and the board is exactly where it was when the pop-up closes. The number underlines when the mouse is over it, and it can be reached with the Tab key and opened with Enter. Ticking, editing and deleting tasks work exactly as before. The same goes for Scheduled Visits: open a visit and the work order number at the end of its title ("... - Light Fixture - #44046") is a link that opens that work order on top of the visit, so closing it lands back on the visit. Only visits that belong to a work order get the link - a Tenant Benefit Package visit has no work order, so its title reads exactly as before.',
        ],
        [
            'date' => '2026-09-09',
            'area' => 'Invoices',
            'title' => 'The Vacant filter no longer calls an occupied home vacant',
            'description' => 'The Invoices page decided whether a property was vacant partly by looking at whether PropertyWare had attached a lease to the work order. That reads a job raised against the property rather than a tenancy - an owner lawn-service quote, for instance - as an empty home, because PropertyWare attaches no lease to those however occupied the place is. WO #43275 at 2914 County Road 855b showed under "Vacant only" while its own Lease column said Active and PropertyWare said OCCUPIED. Now, where the nightly lease sync has a current status for the property, that status decides it: an active lease means the home counts as occupied. Turnovers, re-keys and the Vacant toggle are unchanged - those describe the work, not the property, so they stay vacant whatever the lease says - and a property the sync has no row for behaves exactly as before.',
        ],
        [
            'date' => '2026-09-09',
            'area' => 'Work Orders',
            'title' => 'The Recommendation tab lists the property\'s previous work orders',
            'description' => 'The Recommendation tab on a work order now has a "Previous Work Orders at This Property" card: every earlier work order on file at the same property, newest first, whatever the category and whether it is still open or already closed. Each row shows the work order number, when it was created and completed, its status (open ones are marked, emergencies in red), the category and type, the vendor who did the job, and the first line of the description. Click a number to open that work order in a new tab, so the one you are working on stays put. The ten most recent are listed with the full count, and coordinators get a "View all on the property page" link for the rest. The "Similar Work Orders" card is unchanged - it still lists only the jobs that resemble the current issue.',
        ],
        [
            'date' => '2026-09-09',
            'area' => 'Work Orders',
            'title' => 'The Invoices list shows who ticked an invoice as posted',
            'description' => 'The Posted column on the Invoices page used to show only the date and time an invoice was ticked; who ticked it was there, but only as a tooltip when you hovered the checkbox. The name now sits under the date and time in the same column - "by Work Order Coordinator", for example - so whoever is working the list can see who put an invoice through without hovering each row. Nothing changes about ticking: staff tick and untick as before, and the first person to tick stays on record. Vendors still cannot tick, and they are shown only that an invoice was posted, not who posted it.',
        ],
        [
            'date' => '2026-09-09',
            'area' => 'Work Orders',
            'title' => 'Invoices are archived instead of deleted, and can be brought back',
            'description' => 'Deleting an invoice used to remove it for good - the record and the uploaded PDF or photo both went, with no way to get either back, even though a copy had already been sent to PropertyWare. The action on the work order Invoices tab and on a Jobber job is now "Archive Invoice": it takes the invoice off the work order and out of the Invoices list, but keeps both the record and the file. On the Invoices page, admins and work order coordinators have a "Show archived" button that lists what has been archived, who archived it and when, with a Restore button on each row to put one back. Vendors never see archived invoices and cannot restore one. The Jobber job invoice menu now also asks for confirmation before archiving, which it did not do before.',
        ],
        [
            'date' => '2026-09-09',
            'area' => 'Messaging',
            'title' => 'Owners and tenants are told when our team creates a work order',
            'description' => 'When someone on our team enters a work order in PropertyWare - a coordinator after a call, a technician after a visit, an inspection finding - the owner and the tenant used to get the same "we have received your service request" texts as a request the tenant sent in, which read wrong for a request they never made. Now, whenever the work order\'s Source in PropertyWare is anything other than Tenant Portal or Website, the owner gets one text instead of two: "Hi <name>, a new work order #<number> has been created for <address> by our team", with the work order description and a note that we will keep them updated as the work progresses. The tenant gets the same in their words, with a note that we will contact them about scheduling or access if needed, and the tenant email says the same. Both texts are on the Automated Messages page under "Created by our team" and can be edited there. Everything else is as before: requests from the tenant portal or the website keep today\'s wording, the per-work-order mute still applies, no texts go out on turnover, re-key, refresh-cleaning, vacant or no-lease homes or on HOA notices, and each work order is texted once.',
        ],
        [
            'date' => '2026-09-03',
            'area' => 'Owners',
            'title' => 'New owners can tell us which utilities the HOA handles',
            'description' => 'The management onboarding form for new owners has a new part under Utilities & Services in Amenities & Features: a "Some utilities are handled by the HOA" box, and when it is ticked a required box to list them (water, trash, sewer and so on, one per line if they like). The listing agents asked for this so the listing and the tenant show the right utilities. The list is saved to the property\'s "Utilities Handled by HOA" field in PropertyWare exactly as typed, or "None" when the box is left unticked, so it shows in the maintenance details on the Building page and on the work order Details tab, and it is printed in the Utilities & Services table of the onboarding PDF. When an owner who already answered opens the form again, the box and the list are pre-filled from PropertyWare.',
        ],
        [
            'date' => '2026-09-03',
            'area' => 'Work Orders',
            'title' => 'A note PropertyWare did not take is sent again on its own, and the note says so',
            'description' => 'A note typed on the Notes tab is copied into PropertyWare\'s Notes & Docs (as Private) the moment it is saved - but only once. When PropertyWare could not be reached at that moment, or answered with an error page, the copy never happened and nothing tried again; the coordinator learned of it from the "Saved here only" message (or not at all - an error page from PropertyWare used to read as success) and retyped the note in PropertyWare by hand (WO #43649). Now such a note shows "Not in PropertyWare yet, the app keeps retrying" until it is there: the system re-sends it every ten minutes at first and less often as it ages, reading PropertyWare first so a note that did arrive is never doubled. The "Send to PropertyWare now" link on the note does the same on the spot. This covers the coordinators\' own entries such as Closing Comment as well as technician visit notes.',
        ],
        [
            'date' => '2026-09-03',
            'area' => 'Work Orders',
            'title' => 'Assigning a vendor no longer un-approves the work order in PropertyWare',
            'description' => 'Assigning or changing a vendor sent PropertyWare a copy of the work order without its Approval section, so PropertyWare dropped the approval: Approved went back to No and the approver, date and comment went blank (since 09-01 the comment survived, the rest still went). The re-approval the system attempted afterwards never took effect. Owners and coordinators were therefore asked to approve the same work order again, and again (WO #43819, three times). Now every vendor change reads the Approval section fresh from PropertyWare and sends it back exactly as it stands - approved, by whom, when, and the comment, curly quotes and accents included - and if PropertyWare cannot be read at that moment the vendor change is refused with a message rather than risk the approval. A comment that already shows "?" in PropertyWare was garbled on PropertyWare\'s side and has to be retyped there.',
        ],
        [
            'date' => '2026-09-03',
            'area' => 'Work Orders',
            'title' => 'Work orders PropertyWare has but the app does not are found and imported on their own',
            'description' => 'Every half hour the system compares PropertyWare\'s work order list with its own and imports anything PropertyWare has that the app is missing, with a full check of the whole history every night - so a work order the regular sync could not bring in no longer stays invisible until someone notices and uses Import Work Order. A brand-new open work order comes in exactly as a normal intake does (tenant and owner messages, AI recommendation); an older open one gets the recommendation only, with no messages; a recently closed one (inside the same 30-day window the Closed and Paid columns show) is simply added to the records; and closed history older than that is left alone. A bell notice lists what was backfilled, and a work order that still cannot be imported shows up in the bell with the reason instead of being silently skipped.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Owners',
            'title' => 'Vendor-assigned texts to owners skip the tenant line on vacant homes',
            'description' => 'When a vendor is assigned on a work order for a vacant home, the owner\'s "we have assigned <vendor>" text and email no longer say "The vendor will contact the tenant directly to coordinate and schedule the appointment." The line was already dropped when the Vacant switch was on or the work order was a turnover or re-key; it is now also dropped when PropertyWare shows no lease on the property (new to market or between tenants), which is how most vacant work orders arrive (WO #44032). The owner is still told which vendor was assigned.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Work Orders',
            'title' => 'A second HOA notice on a property gets its own work order',
            'description' => 'Uploading an HOA notice for a property that already had an open HOA violation used to file the new notice under that old work order on its own - no new work order in PropertyWare, and the tenant never heard about the new problem. Now, when the property you confirm on the review screen already has an open HOA violation, the row says so - the work order number, the date of its notice, what it was about, and where the tenant stands with it - and asks which this is: "New work order - this is a different violation" (the default) or "Attach to WO #NNN - same violation, follow-up notice". A notice dated the same day as the open violation\'s is taken to be that same letter uploaded again and defaults to attaching. The confirmation message now says which happened: "1 HOA violation work order created" or "1 notice attached to existing work order #NNN".',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Work Orders',
            'title' => 'Deleting a note asks first',
            'description' => 'The red X on a work order note now opens a confirmation - "Delete this note?" - instead of deleting on the spot. The X only ever appears on notes that have not reached PropertyWare yet, which are exactly the ones nothing can bring back, so one stray click no longer costs a note.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Work Orders',
            'title' => 'Field notes show the technician\'s name',
            'description' => 'Notes written by our in-house crew used to be signed "Texas Home Maintenance Pros", because the whole team shares one vendor login. The Notes tab now looks up who was actually assigned to that job\'s visit in Jobber and shows the technician\'s name instead - so "Job was completed" reads as, say, Emanuel Hall rather than the company. When the note doesn\'t line up with a Jobber visit (no Jobber job on the work order, or no one assigned yet), it falls back to the vendor name exactly as before. Notes from other vendors and from office staff are unchanged.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Work Orders',
            'title' => 'Removing a vendor no longer hides their conversation',
            'description' => 'When a vendor was removed from a work order, their back-and-forth with the coordinator disappeared from the Vendor - Coordinator tab: the messages were still saved, but the vendor picker only listed vendors currently on the work order, so there was no way to open the old thread. The picker now has a "No longer assigned" section listing every removed vendor who has messages on that work order, marked "(removed)". Pick one to read the full history, or text them again if you need to; if the work order\'s only vendor was removed, their thread opens by itself instead of an empty tab. This is for staff only - vendors, owners and tenants never see removed vendors - and the Send assignment info button hides for a removed vendor so nobody accidentally re-sends work order paperwork to someone who is off the job.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'People',
            'title' => 'A Technicians page, and tenant appointment texts that carry the technician\'s photo',
            'description' => 'The People menu has a new Technicians page: the in-house roster as profile cards - photo, name, specialty, role - where clicking a card opens the full profile (photo upload, phone, email, bio, active). And on a work order, the Create/Edit Service Schedule window has an optional Technician dropdown: pick who is going and the tenant\'s automatic appointment text becomes the THMP Technician Visit Reminder - the date, the work order, "Assigned Technician: <name>" - with that technician\'s photo attached as a picture message, so the tenant recognizes who is at their door. The wording is editable under IT Tools, Automated Messages ("THMP technician visit"). Photos are JPG or PNG up to 2 MB - the sizes and types phone carriers reliably deliver. Picking nobody sends the standard appointment message exactly as before, and all the existing rules still apply: the per-work-order automation mute, and no automated texts on turnover, re-key, refresh-cleaning or vacant homes.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Jobber',
            'title' => 'The Scheduler preview has been retired',
            'description' => 'The "Scheduler (Still developing)" page under Jobs (Jobber) has been removed. Scheduling will be handled in the separate scheduling app instead of here, so the map preview and its nightly address-geocoding run are gone. Nothing else changes: jobs, visits, and all the tenant and vendor notifications in this system keep working exactly as before.',
        ],
        [
            'date' => '2026-09-02',
            'area' => 'Work Orders',
            'title' => 'Notes now show who wrote them',
            'description' => 'Each note on a work order\'s Notes tab now shows the writer\'s name next to the time it was added, so you can tell at a glance whether an update came from a coordinator, a technician, or over from PropertyWare. A note written on the dashboard shows the name of the person who typed it; a note that came across from PropertyWare says "PropertyWare", since PropertyWare does not tell us who wrote it there.',
        ],
        [
            'date' => '2026-09-01',
            'area' => 'Work Orders',
            'title' => 'Approval comments no longer disappear after assigning a vendor',
            'description' => 'When a vendor was assigned, the sync that sends the assignment to PropertyWare was quietly erasing the work order\'s Approval Comments there, and a few minutes later the import copied the now-empty value back over the dashboard too - so the owner\'s note was gone everywhere unless someone happened to have a copy (WO#44014). Assigning a vendor, and vendor edits that sync to PropertyWare, now read the current approval comment first and send it along, so it stays put on both sides. The imports were also taught to never overwrite a saved approval comment, date, or approver with an empty one.',
        ],
        [
            'date' => '2026-09-01',
            'area' => 'Work Orders',
            'title' => 'Notes now show the time they were added',
            'description' => 'Each note on a work order\'s Notes tab now shows the date and time it was added (Central time), not just the day, so when several notes land on one work order in a day you can tell which came first and follow the order of updates. A note written on the dashboard shows the moment it was saved. A note that came from PropertyWare shows PropertyWare\'s own note time, and one that only carries a day still shows just the day.',
        ],
        [
            'date' => '2026-09-01',
            'area' => 'Owners',
            'title' => 'New owners now tell us if the property is gated, and about the sprinkler system',
            'description' => 'The management onboarding form for new owners asks two more things in Amenities & Features. First, "Is this property gated?" at the top of the gate, garage and mailbox part, with a required Gate Code box when it is - the form cannot be submitted without it. The answer is saved to the property\'s "Gated Community? Gate Code?" field in PropertyWare as "Yes - Gate code: #1234" or "No gate", so it shows in the maintenance details on the Building page and on the work order Details tab before anyone is sent out, and it is printed on the onboarding PDF. The old form was saving the Garage Door Opener answer into that field by mistake, which is why some properties read just "Yes" with no code; when one of those owners opens the form again the gate question is pre-set to Yes with an empty code box, so they have to type it in. Second, a Sprinkler / Irrigation System part under Utilities & Services - whether the home has one - for the utility companies and the tenant. The answer is saved to the property\'s "Yard Features" field in PropertyWare, and it is printed on the onboarding PDF. The Appliances part now also asks what kind of fireplace the home has - electric, freestanding, gas connections, gaslog, mock, stove, wood burning, or No Fireplace - and the answer is saved to the property\'s "Fireplace" field in PropertyWare and printed on the onboarding PDF.',
        ],
        [
            'date' => '2026-09-01',
            'area' => 'Jobber',
            'title' => 'Notes tab on Jobber jobs',
            'description' => 'The job pop-up on the Jobs (Jobber) board and the full job page have a new Notes tab for internal office notes. Type a note and press Add note (or Ctrl+Enter) and it appears at the top with who wrote it and when; the red X removes it. These notes stay in this system only: nothing is sent to Jobber and a Jobber sync never touches them (unlike Instructions, which Jobber owns), and they are separate from work order notes, which live in PropertyWare. Vendors, owners and tenants never see them, and a job that has been closed here still takes notes.',
        ],
        [
            'date' => '2026-09-01',
            'area' => 'Jobber',
            'title' => 'Send a TBP visit notice yourself when the automation could not',
            'description' => 'The 14/7/3-day and day-before Tenant Benefit Package reminders only go out when the property name in PropertyWare matches the Jobber client name exactly, so a tenant at "17710 Winnower" (PropertyWare) / "17710 Winnower Ln" (Jobber) never got them and staff were copy-pasting the notice by hand. Open any TBP visit on the Scheduled Visits calendar and there is now a Send notification button: it shows the visit notice with that visit\'s date already filled in, suggests the tenant from PropertyWare even when the street suffix differs, and lists the Jobber client\'s phone and any saved contacts. Tick who should get it (or type a number) and press Send - the text goes out from your number and appears on the visit\'s Messages tab like any other text. The dialog also shows which automated reminders this visit already received. The wording is the "Send notification button" template under IT Tools > Automated Messages > Message Templates.',
        ],
        [
            'date' => '2026-08-26',
            'area' => 'Work Orders',
            'title' => 'Notes added on the dashboard no longer disappear, and are private',
            'description' => 'Notes typed on a work order\'s Notes tab were being wiped by the PropertyWare sync a few minutes after they were saved, even though the screen had said "Success". The sync now only refreshes PropertyWare\'s own notes and leaves dashboard notes alone, so a technician\'s repair, diagnosis and visit notes stay put with their author and real date. Every note added here is now saved to PropertyWare as Private (internal only, hidden from the tenant and owner portals) and is hidden from tenant and owner logins on the dashboard too. If PropertyWare does not accept a note when it is added, the note is still kept on the dashboard and you get a "Saved here only" message instead of "Success". Notes that came from PropertyWare can no longer be deleted here (they would only come back at the next sync) — remove those in PropertyWare.',
        ],
        [
            'date' => '2026-08-25',
            'area' => 'Jobber',
            'title' => 'Scheduler map preview (still developing)',
            'description' => 'A new Scheduler page under Jobs (Jobber) maps the whole portfolio: every property pinned by its real location, colored by zone, with filters for properties with open THMP work and for the Assigned - Waiting on Scheduling queue. A Calendar tab shows each month of Jobber visits colored by type (move in, move out, TBP, maintenance) — click a day to see its visits on the map, click a visit to draw its 5-mile radius and highlight the unscheduled TBPs inside it. Dots can be colored by type or by technician, each technician\'s stops for the day are joined into a route (a suggested drive order when the visits are booked as "anytime"), and picking a technician on the property view shows just the properties they are booked at this month. Admins and coordinators only. Property positions fill in after the nightly geocoding run — addresses the geocoder does not know yet pin from Jobber\'s own coordinates — and technician names appear as visits sync from Jobber. This page is a working preview — the scheduling automation it is built for comes later.',
        ],
        [
            'date' => '2026-08-25',
            'area' => 'Automated Messages',
            'title' => 'Website requests that arrive without a lease no longer go silent',
            'description' => 'A service request submitted through the PropertyWare website can arrive without the property\'s lease attached, even on an occupied home - PropertyWare only fills the lease in the next time the work order is saved. Because the system reads new work orders within minutes, it saw "no lease" and treated the home as vacant, muting every automated tenant and owner message on that work order, including the "we received your request" texts, which are sent once and were lost for good (WO#43937). Three changes: the notification bell now flags a work order that comes in without a lease, and the Automated Messages log shows the muted texts with the reason, so the silence is visible; the moment the lease shows up - on a sync, an Import Work Order click, or when a vendor is assigned - the intake texts and email go out on their own; and assigning a vendor now pulls the lease straight from PropertyWare instead of waiting for the next sync, so the "vendor assigned" text reaches the tenant too. The vendor sync also sends the work order\'s Source back to PropertyWare so it is no longer reset to "None". Work orders without a tenant contact, and turnover, re-key and cleaning jobs, stay muted on purpose.',
        ],
        [
            'date' => '2026-08-22',
            'area' => 'Work Orders',
            'title' => 'The Closed column shows the last 30 days again',
            'description' => 'The Closed column on the main board ballooned to over 1,000 cards: PropertyWare reports many closed work orders without a completion date, and after a bulk sync touched those records the board mistook years-old work orders for freshly closed ones. The system now records its own completion date the moment it first sees a work order close, so the Closed column is back to what actually closed in the last 30 days. Searching by work order number still finds any closed work order, no matter how old.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Vendor Portal',
            'title' => 'The vendor checklist now appears on every work order',
            'description' => 'Vendors reported that some work orders in their portal had the "What needs to be done" checkboxes and others did not — and those checkboxes are how a vendor moves a work order to Scheduled on their own. The checklist was only created when the status was changed inside our system, so work orders statused or vendor-assigned in PropertyWare never got one, the vendor could not update the status, and staff kept chasing vendors who had already scheduled. The checklist is now added automatically whenever a vendor is assigned — from the assign button, the PropertyWare sync, or an import — so every vendor can check off their steps and the status updates itself. Vendor schedule reminder texts that could not actually send (no phone number on file) now also show in the Automated Messages log with the reason, instead of disappearing silently.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Jobber',
            'title' => 'Tenants now get a 14-day advance notice for TBP visits',
            'description' => 'Tenant Benefit Package visits now announce themselves two weeks ahead: tenants get the full visit notice by text and email 14 days before the scheduled date, on top of the existing 7-day, 3-day and day-before reminders. It only applies to visits already on the calendar 14 or more days out — anything booked closer just gets the usual reminders. The wording is editable on the Message Templates tab, and the Jobber tenant reminder switches cover it like the other tiers.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'System',
            'title' => 'Work order boards and dashboard load faster',
            'description' => 'The database now keeps lookup indexes on the work order fields the boards, dashboard, and searches filter by, and the dashboard charts ask for date ranges the database can jump to directly. Nothing looks different — the same pages simply come back quicker, especially the kanban boards, the dashboard tiles, and work order number search.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Work Orders',
            'title' => 'Checklist tasks tick themselves when the work is already done',
            'description' => 'Every 30 minutes the system now completes the checklist tasks it can verify on its own: "Fill in scheduled date" once a schedule exists, "Assign to Appropriate Vendor" once a vendor is on, the category/zone/management-plan updates once those fields are filled, and the photo and invoice confirmations once the uploads are actually there (including "synced to PW" once every photo has its PropertyWare copy). Only the pure data-entry confirmations are touched — anything that moves the work order to a new status, and every Yes/No question, is still yours. The service status never changes on its own.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Work Orders',
            'title' => 'Closing a work order finishes its checklist',
            'description' => 'Closing a work order now marks whatever is left on its checklist as completed, however it is closed — the Completed button or the "Close Work Order" task. No more open tasks lingering on closed work orders, and no more bulk clean-up sweeps. The tasks stay on the work order as the record of what was done.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Notifications',
            'title' => 'The system now audits its own data overnight',
            'description' => 'A set of overnight housekeeping jobs keeps the data clean without anyone remembering to run anything: duplicate documents on the attachments tab are collapsed, closing comments written in PropertyWare after import are pulled in, and the vendor list refreshes weekly. Two of them ring the bell when they find trouble: an HOA violation work order with no tenant linked (which silently stops that tenant\'s texts) raises an alert the morning it appears, and a nightly owner-data audit reports duplicate owners and owners who have work orders but no phone number on file — the reason some owner texts go nowhere.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Messaging',
            'title' => 'Edit the automated message wording yourself',
            'description' => 'The Automated Messages page has a new Message Templates tab where admins and work order coordinators can rewrite the canned text every automation sends — tenant, owner, vendor, and the Jobber visit reminders. Placeholders fill in the details per message, a preview shows the result, and any message can be reset to its original wording. The page itself is now open to coordinators as well.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Vendors',
            'title' => 'Daily schedule reminders to vendors no longer repeat the same text',
            'description' => 'The daily "please set a service schedule" text to an assigned vendor used to be the identical message every day, which read as a bot easy to ignore. The first notice is unchanged, but from day two the reminder now rotates through five polite rephrasings of the same request, so no two consecutive days read the same. Same rules as before: once per day, stops as soon as the vendor sets a schedule or the work order closes, and respects the per-work-order vendor automation Off switch.',
        ],
        [
            'date' => '2026-08-21',
            'area' => 'Messaging',
            'title' => 'Long automated texts deliver reliably again',
            'description' => 'Some longer automated texts — vendor schedule reminders, tenant photo requests, HOA notices — were being rejected by certain phone carriers as "too large" and showed up as Undelivered in the conversation threads. The cause was a special dash character that silently doubled the size of every message it appeared in. All automated message wording now uses plain characters, so the same texts send at half the size and land reliably (this also halves what those messages cost to send).',
        ],
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
            'area' => 'Work Orders',
            'title' => 'Closed work orders without a completed date show up again',
            'description' => 'Work orders closed in PropertyWare without a Completed Date filled in were invisible on the main board — searching their number came back empty even though the work order existed (like #42487). Searching now always finds them in the Closed column, and recently-closed ones appear there for 30 days based on when they last changed. Nothing floods: older ones stay tucked away exactly like before.',
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
            'area' => 'Notifications',
            'title' => 'No more undelivered alerts for automated texts',
            'description' => 'The bell only reports an undelivered text when a person sent it. Failed automatic messages — vendor schedule reminders, portal links, visit reminders — no longer fill the bell, and the ones already there are gone. Every send is still recorded on the work order and on the Automated Messages page.',
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
