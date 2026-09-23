<?php

namespace App\Console\Commands;

use App\Models\Owner;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\OwnerPortalLinkService;
use App\Services\OwnerWorkOrderEmailSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * The maintenance system's replacement for PropertyWare's daily "Work Order
 * Pending Approval" owner alert.
 *
 * PropertyWare emails an owner once a day, every day, while a work order is
 * open and unapproved, and that email sends them into the PropertyWare owner
 * portal. This command sends the same daily email from here instead, with
 * the owner's no-login portal link, so the only screen an owner ever uses is
 * ours. It copies PropertyWare's mechanism on purpose: email only, daily, no
 * cap, and it stops only when the approval resolves.
 */
class NudgeOwnerApproval extends Command
{
    protected $signature = 'owners:nudge-approval
        {--dry-run : List who would be reminded today without sending or recording anything}';

    protected $description = 'Email each owner once a day that a work order is waiting on their approval, until they answer in the owner portal or PropertyWare shows it approved or closed.';

    public const SKIP_NO_CONTACT = 'no_owner_email';

    private int $sent = 0;

    /** @var list<array{work_order: string, owner: string, to: string}> */
    private array $dryRunRows = [];

    /**
     * Runs daily. "Waiting on owner approval" is PropertyWare's own definition:
     * the work order is Open there and its approved flag is false — whatever
     * our service status says, since PropertyWare nags on exactly that set.
     * Both fields are re-imported every ten minutes.
     *
     * An owner is reminded until any of these is true:
     *  - an owner answered in the portal (approve OR don't approve — one answer
     *    per work order is enough, and "don't approve" never reaches PropertyWare)
     *  - PropertyWare shows the work order approved (portal push, or approved
     *    there directly) or no longer Open
     *  - the WOC muted owner automation for this work order
     * Turnover, re-key and refresh-cleaning work orders are company-ordered
     * work, so they are left out as with the other owner messages. Work
     * orders that predate the feature are stamped excluded by the fresh-start
     * backfill and never enter the loop.
     */
    public function handle(OwnerPortalLinkService $portalLinks, OwnerWorkOrderEmailSender $emails): int
    {
        // Gated off by default so local/testing never contacts a real owner.
        if (! config('services.twilio.owner_approval_nudge')) {
            $this->info('Owner approval reminder is disabled (OWNER_APPROVAL_NUDGE_ENABLED=false).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $startOfToday = now()->startOfDay();

        // Scoped in SQL so we never load the whole open board. Work orders
        // that existed when this shipped carry the fresh-start stamp and are
        // never reminded about: the reminder starts with what arrives after.
        $workOrderIds = DB::table('work_orders')
            ->where('status', 'Open')
            ->where('is_approved', false)
            ->whereNull('approval_nudge_excluded_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($workOrderIds as $workOrderId) {
            $workOrder = WorkOrder::query()
                ->with(['owners', 'building'])
                ->find($workOrderId);

            if (! $workOrder || $workOrder->status !== 'Open' || $workOrder->is_approved) {
                continue;
            }

            if ($workOrder->automationPausedFor('owner') || $this->isCompanyOrdered($workOrder)) {
                continue;
            }

            if ($this->hasPortalDecision($workOrder)) {
                continue;
            }

            $recipients = $this->recipients($workOrder);

            if ($recipients === []) {
                $this->recordNobodyReachable($workOrder, $dryRun);

                continue;
            }

            foreach ($recipients as [$owner, $to]) {
                if ($dryRun) {
                    $this->dryRunRows[] = [
                        'work_order' => '#'.($workOrder->work_order_no ?? $workOrder->id),
                        'owner' => $this->ownerName($owner),
                        'to' => $to,
                    ];

                    continue;
                }

                if ($this->remind($workOrder, $owner, $to, $portalLinks, $emails, $startOfToday)) {
                    $this->sent++;
                }
            }
        }

        if ($dryRun) {
            $this->table(['Work order', 'Owner', 'Email'], $this->dryRunRows);
            $this->info('Dry run: '.count($this->dryRunRows).' reminder(s) would go out today. Nothing was sent or recorded.');

            return self::SUCCESS;
        }

        $this->info("Owner approval reminders sent: {$this->sent}.");

        return self::SUCCESS;
    }

    /**
     * Whether any owner has already answered for this work order in the portal.
     * One answer is enough: PropertyWare has one approval per work order, and a
     * "don't approve" is a decision too, even though it never reaches
     * PropertyWare (which would keep asking — that is the point of replacing it).
     */
    private function hasPortalDecision(WorkOrder $workOrder): bool
    {
        return Activity::query()
            ->where('event', 'owner_portal_approval')
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->exists();
    }

    /**
     * Turnover, re-key and refresh-cleaning work orders are work the company
     * set in motion, not something the owner is asked to approve here — the
     * same skip the intake owner text applies.
     */
    private function isCompanyOrdered(WorkOrder $workOrder): bool
    {
        return $workOrder->isTurnover() || $workOrder->isRekey() || $workOrder->isRefreshCleaning();
    }

    /**
     * Every owner on the work order (any ownership percentage) who has an
     * email address. A couple sharing one inbox gets a single reminder. Owners
     * with no email are left out here and reported by the caller: this
     * reminder is email only, like the PropertyWare alert it replaces.
     *
     * @return list<array{0: Owner, 1: string}> [owner, address]
     */
    private function recipients(WorkOrder $workOrder): array
    {
        $recipients = [];
        $seen = [];

        $owners = $workOrder->owners
            ->sortBy(fn (Owner $owner): int => $owner->id)
            ->sortByDesc(fn (Owner $owner): float => (float) $owner->percentage_ownership);

        foreach ($owners as $owner) {
            $email = $this->ownerEmail($owner);

            if ($email === null || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;
            $recipients[] = [$owner, $email];
        }

        return $recipients;
    }

    /**
     * The owner's email address, lower-cased, or null when blank or not an
     * address at all (PropertyWare sends "" rather than null, and the odd
     * placeholder).
     */
    private function ownerEmail(Owner $owner): ?string
    {
        $email = strtolower(trim((string) $owner->email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Send one owner today's reminder, or skip them. Returns whether a
     * reminder was actually sent.
     */
    private function remind(
        WorkOrder $workOrder,
        Owner $owner,
        string $to,
        OwnerPortalLinkService $portalLinks,
        OwnerWorkOrderEmailSender $emails,
        Carbon $startOfToday,
    ): bool {
        // The reminder always carries a portal link, so the token is issued
        // here if this owner never had one (a work order that predates the
        // owner texts, or an owner PropertyWare added later).
        $token = $portalLinks->tokenFor($workOrder, $owner);

        // Claim today's reminder atomically so an overlapping run or a retry
        // never contacts the same owner twice on the same day. No cap: like
        // PropertyWare's alert it runs until the approval resolves.
        $claimed = DB::table('owner_portal_tokens')
            ->where('id', $token->id)
            ->where(function ($query) use ($startOfToday) {
                $query->whereNull('approval_nudge_last_sent_at')
                    ->orWhere('approval_nudge_last_sent_at', '<', $startOfToday);
            })
            ->update([
                'approval_nudge_last_sent_at' => now(),
                'approval_nudge_count' => DB::raw('approval_nudge_count + 1'),
            ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $this->email($workOrder, $owner, $to, $this->messageFor($workOrder), $portalLinks->link($workOrder, $owner), $emails);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Owner approval reminder failed to send.', [
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The reminder email: the editable wording and the owner's portal link,
     * from the work-orders mailbox, recorded on the owner's email history like
     * the vendor-assignment email.
     */
    private function email(
        WorkOrder $workOrder,
        Owner $owner,
        string $to,
        string $body,
        ?string $link,
        OwnerWorkOrderEmailSender $emails,
    ): void {
        $number = $workOrder->work_order_no ?? $workOrder->id;

        $html = view('emails.owner-approval-nudge', [
            'workOrder' => $workOrder,
            'owner' => $owner,
            'body' => $body,
            'portalLink' => $link,
        ])->render();

        $notification = $emails->send(
            owner: $owner,
            workOrder: $workOrder,
            to: $to,
            mailbox: (string) config('services.microsoft.mailbox'),
            subject: $workOrder->subjectWithProperty('Approval needed - Work Order #'.$number),
            html: $html,
            trustedHtml: true,
            metadata: ['automation' => 'owner_approval_nudge_email'],
            type: 'approval_nudge',
        );

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_EMAIL,
            'owner',
            'owner_approval_nudge_email',
            $to,
            $workOrder,
            $body,
            ['owner_id' => $owner->id, 'owner_email_notification_id' => $notification->id],
        );
    }

    /**
     * A work order nobody can be reminded about: every owner is missing an
     * email. Written to the ledger so a coordinator asking "why is this owner
     * silent?" finds the answer where the sends are.
     */
    private function recordNobodyReachable(WorkOrder $workOrder, bool $dryRun): void
    {
        if ($dryRun) {
            $this->dryRunRows[] = [
                'work_order' => '#'.($workOrder->work_order_no ?? $workOrder->id),
                'owner' => '(no owner with an email)',
                'to' => '-',
            ];

            return;
        }

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_EMAIL,
            'owner',
            'owner_approval_nudge_email',
            null,
            $workOrder,
            'Not sent: no owner on this work order has an email on file.',
            ['not_texted_reason' => self::SKIP_NO_CONTACT],
        );
    }

    /**
     * The reminder wording, editable from the Automated Messages page via the
     * AutomatedMessageTemplates registry.
     */
    private function messageFor(WorkOrder $workOrder): string
    {
        $address = $workOrder->propertyAddress();

        return AutomatedMessageTemplates::text('owner_approval_nudge', [
            'work_order_no' => (string) ($workOrder->work_order_no ?? $workOrder->id),
            'property' => $address !== null ? ' at '.$address : '',
        ]);
    }

    private function ownerName(Owner $owner): string
    {
        return trim((string) $owner->name) ?: trim($owner->first_name.' '.$owner->last_name) ?: 'Owner #'.$owner->id;
    }
}
