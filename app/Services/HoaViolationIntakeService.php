<?php

namespace App\Services;

use App\Jobs\UploadHoaNoticeToPropertyWare;
use App\Models\Attachments;
use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a single extracted HOA violation notice into a working work order:
 * create it in PropertyWare (falling back to a local-only row), attach the
 * notice page PDF, set the Tenant Easy Fix status, and open the tenant portal
 * token that drives the reminder/deadline workflow.
 *
 * Extraction and property matching happen upstream (staff confirm the property
 * on screen), so this service receives one confirmed notice at a time.
 */
class HoaViolationIntakeService
{
    public const EASY_FIX_STATUS = 'Checking for Tenant Easy Fix';

    public function __construct(
        private readonly PropertyWareService $propertyWare,
        private readonly TenantPortalLinkService $linkService,
        private readonly PropertyWareWorkOrderCreator $creator,
    ) {}

    /**
     * @param  array{
     *     description?: ?string,
     *     violation_items?: array<int, string>,
     *     hoa_name?: ?string,
     *     notice_date?: ?Carbon,
     *     deadline_date?: ?Carbon,
     *     deadline_days?: ?int,
     *     file_name?: ?string,
     *     mime?: ?string
     * }  $notice
     * @return array{work_order: WorkOrder, created: bool, pw_created: bool, description: string}
     */
    public function createFromNotice(Building $building, string $pagePdfContents, array $notice): array
    {
        $description = $this->buildDescription($notice);
        $noticeDate = ($notice['notice_date'] ?? null) instanceof Carbon ? $notice['notice_date'] : now();

        // An open HOA work order for the same property means this notice is a
        // follow-up — attach to it instead of creating a duplicate.
        $workOrder = $this->findExistingOpenHoaWorkOrder($building);
        $created = false;
        $pwCreated = false;

        if ($workOrder === null) {
            [$workOrder, $pwCreated] = $this->createWorkOrder($building, $description);
            $created = true;
        }

        $this->attachNotice($workOrder, $pagePdfContents, $notice['file_name'] ?? null, $notice['mime'] ?? null);
        $this->applyEasyFixStatus($workOrder, $description, $created);
        $this->linkTenantFromLease($workOrder);
        $this->openHoaToken(
            $workOrder,
            $noticeDate,
            ($notice['deadline_date'] ?? null) instanceof Carbon ? $notice['deadline_date'] : null,
        );

        return [
            'work_order' => $workOrder->refresh(),
            'created' => $created,
            'pw_created' => $pwCreated,
            'description' => $description,
        ];
    }

    /**
     * Adopt a work order that staff categorized "HOA Violation" straight in
     * PropertyWare instead of uploading the notice PDF here.
     *
     * There is no notice document to read, so the description, type, and
     * attachments stay exactly as PropertyWare sent them. This only starts the
     * same downstream workflow the upload path starts: Tenant Easy Fix status,
     * the deadline token, and the tenant's photo-upload link — after which the
     * daily reminders, escalation, and confirmation all run unchanged, because
     * they key off the token.
     *
     * The owner is deliberately not notified: they are usually the one who
     * forwarded the HOA notice in the first place.
     */
    public function adoptCategorizedWorkOrder(WorkOrder $workOrder): bool
    {
        if (trim((string) $workOrder->category) !== WorkOrder::HOA_VIOLATION_CATEGORY) {
            return false;
        }

        // Any HOA token at all — open or already completed — means this work
        // order has been through the workflow, so never restart it.
        $alreadyTracked = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->exists();

        if ($alreadyTracked) {
            return false;
        }

        // No notice date was extracted, so the deadline counts from when the
        // work order was raised.
        $noticeDate = $workOrder->created_date
            ? Carbon::parse($workOrder->created_date)
            : now();

        $this->applyEasyFixStatus($workOrder, (string) $workOrder->description, false);
        $this->linkTenantFromLease($workOrder);
        $this->openHoaToken($workOrder, $noticeDate);

        return true;
    }

    /**
     * Stamp requested_by from the work order's lease tenants when the import
     * left it empty.
     *
     * The PropertyWare create sends no requestedByContact, so a created-then-
     * imported work order comes back tenant-less — and every automated tenant
     * message reads requested_by only, so the whole HOA workflow (intake text,
     * daily reminders) silently no-ops while the vendor escalation still fires
     * (WO#43864, 2026-08-19: ten violations reached "needs vendor" without the
     * tenant ever being texted). The import does fill the work_order_tenants
     * pivot from the active lease, so link the most reachable lease tenant
     * before the token opens. Public so hoa:relink-tenants can repair old rows.
     */
    public function linkTenantFromLease(WorkOrder $workOrder): bool
    {
        if (filled($workOrder->tenant_id)) {
            return true;
        }

        $tenant = $this->leaseTenantFor($workOrder);

        if ($tenant === null) {
            Log::warning('HOA violation work order has no lease tenant to link — automated tenant messages will not send.', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
            ]);

            return false;
        }

        WorkOrder::query()->withoutGlobalScopes()->whereKey($workOrder->id)->update([
            'tenant_id' => $tenant->id,
        ]);

        $workOrder->setAttribute('tenant_id', $tenant->id);
        $workOrder->unsetRelation('requested_by');

        return true;
    }

    /**
     * The lease tenant to treat as the requester: the one staff can actually
     * text, so a tenant with a mobile number wins, then one with a home phone,
     * then any lease tenant at all.
     */
    public function leaseTenantFor(WorkOrder $workOrder): ?Tenants
    {
        $leaseTenants = $workOrder->tenants()->get();

        return $leaseTenants->first(fn (Tenants $leaseTenant) => filled($leaseTenant->mobile_phone))
            ?? $leaseTenants->first(fn (Tenants $leaseTenant) => filled($leaseTenant->home_phone))
            ?? $leaseTenants->first();
    }

    /**
     * @param  array{description?: ?string, violation_items?: array<int, string>, hoa_name?: ?string}  $notice
     */
    private function buildDescription(array $notice): string
    {
        $lines = [trim((string) ($notice['description'] ?? '')) ?: HoaNoticeExtractor::FALLBACK_DESCRIPTION];

        $items = array_values(array_filter($notice['violation_items'] ?? [], fn ($item) => filled($item)));

        if ($items !== []) {
            $lines[] = '';
            $lines[] = 'Items to correct:';

            foreach ($items as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (filled($notice['hoa_name'] ?? null)) {
            $lines[] = '';
            $lines[] = 'Notice issued by: '.$notice['hoa_name'];
        }

        return implode("\n", $lines);
    }

    /**
     * Deliberately narrower than the hoaViolations() scope: only a work order
     * this feature itself opened (it carries the HOA token) counts as the
     * follow-up target. A work order merely categorized "HOA Violation" by hand
     * shows on the HOA board, but adopting one here could attach a fresh notice
     * to a stale or unrelated work order, so those still get their own.
     */
    private function findExistingOpenHoaWorkOrder(Building $building): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('building_id', $building->propertyware_id)
            ->whereHas('tenantUploadTokens', function ($tokens) {
                $tokens->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION);
            })
            ->where('status', 'Open')
            ->latest('id')
            ->first();
    }

    /**
     * Create in PropertyWare first (the app treats PW as the system of record);
     * import the new work order back so the local row carries the tenant, owner,
     * and lease data every downstream feature expects. When PW creation is
     * disabled or fails, fall back to a local-only work order so intake never
     * blocks — staff can re-link it to PropertyWare later.
     *
     * @return array{0: WorkOrder, 1: bool}
     */
    private function createWorkOrder(Building $building, string $description): array
    {
        if (config('services.hoa.pw_create_enabled')) {
            [$location, $unitId] = $this->creator->locationFor($building->propertyware_id);

            $workOrder = $this->creator->createAndImport([
                'building_id' => $building->propertyware_id,
                'portfolio_id' => $building->portfolio_id,
                'category' => config('services.hoa.pw_category'),
                'description' => $description,
                'type' => config('services.hoa.pw_type'),
                'location' => $location,
                'unit_id' => $unitId,
            ]);

            if ($workOrder !== null) {
                return [$workOrder, true];
            }
        }

        Log::warning('HOA work order created locally only (PropertyWare create unavailable).', [
            'building_propertyware_id' => $building->propertyware_id,
        ]);

        // A local-only row never reaches PropertyWare, so it is not bound by the
        // PW picklist the create payload has to satisfy — label it for what it
        // is so staff can spot the orphan on the board.
        $workOrder = WorkOrder::create([
            'work_order_no' => null,
            'category' => WorkOrder::HOA_VIOLATION_CATEGORY,
            'type' => config('services.hoa.pw_type'),
            'description' => $description,
            'status' => 'Open',
            'service_status_id' => ServiceStatus::query()->where('name', self::EASY_FIX_STATUS)->value('id')
                ?? ServiceStatus::query()->value('id'),
            'building_id' => $building->propertyware_id,
            'portfolio_id' => $building->portfolio_id,
            'location' => $building->name,
            'created_date' => now(),
            'local_status' => 'Created',
        ]);

        return [$workOrder, false];
    }

    private function attachNotice(WorkOrder $workOrder, string $noticeContents, ?string $originalName, ?string $mime): void
    {
        $mime = $mime ?: 'application/pdf';
        $extension = $this->extensionForMime($mime);

        $fileName = 'HOA Notice - WO'.($workOrder->work_order_no ?? $workOrder->id).' - '.now()->format('Y-m-d').'.'.$extension;
        $storedPath = 'attachments/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->put($storedPath, $noticeContents);

        $attachment = Attachments::create([
            'title' => 'HOA violation notice',
            'filename' => $storedPath,
            'filetype' => $mime,
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => auth()->id() ?? $workOrder->requested_by?->user_id ?? User::query()->value('id'),
            // pw_file_name stays null until the queued upload confirms; the
            // document sync skips names it finds here, so an early write would
            // permanently hide a notice whose push failed.
            // Staff put the notice here themselves — never badge it as new.
            'viewed_by_staff_at' => now(),
            'created_at' => now(),
        ]);

        // Every notice goes to PropertyWare's DOCS, PDF or photo alike — the
        // upload dialog accepts both and a photographed notice is the same
        // document. PropertyWare takes the raw bytes and reads the type off the
        // file name, whose extension extensionForMime() already matched to the
        // upload. Queued with retries — a transient PropertyWare error must not
        // lose the notice.
        if ($workOrder->propertyware_id) {
            UploadHoaNoticeToPropertyWare::dispatch($attachment->id, $fileName);
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            // PropertyWare types the document off this extension, so a mime it
            // does not know must never fall through to the pdf default and hand
            // PropertyWare an image named .pdf.
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'pdf',
        };
    }

    /**
     * Straight to Tenant Easy Fix: the tenant portal machinery keys off this
     * service status. Also make sure the local row carries the extracted
     * description (the import may have brought back blanks). HOA identity lives
     * on the upload token, not the type/category, so those are left as PW sent
     * them.
     */
    private function applyEasyFixStatus(WorkOrder $workOrder, string $description, bool $created): void
    {
        $statusId = ServiceStatus::query()->where('name', self::EASY_FIX_STATUS)->value('id');

        $updates = ['service_status_id' => $statusId ?? $workOrder->service_status_id];

        if ($created) {
            $updates['description'] = $description;
        }

        $workOrder->update($updates);

        if ($workOrder->propertyware_id && $statusId) {
            try {
                $this->propertyWare->updateServiceStatus($workOrder, ServiceStatus::find($statusId));
            } catch (\Throwable $exception) {
                Log::warning('Pushing HOA service status to PropertyWare failed.', [
                    'work_order_id' => $workOrder->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * The HOA token anchors the whole downstream workflow: portal link, daily
     * reminders, deadline, escalation, and confirmation. The tenant messages
     * are the same five every time and the day-4 vendor warning always lands on
     * the same day, because the reminder cadence counts sends (notified_count),
     * not days — the deadline here only drives the vendor escalation.
     */
    private function openHoaToken(WorkOrder $workOrder, Carbon $noticeDate, ?Carbon $statedDeadline = null): void
    {
        $existing = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereNull('completed_at')
            ->exists();

        if ($existing) {
            return;
        }

        $token = TenantUploadToken::create([
            'token' => TenantUploadToken::generateUniqueToken(),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
            'hoa_notice_date' => $noticeDate->toDateString(),
            'hoa_deadline_at' => $this->resolveDeadline($noticeDate, $statedDeadline),
        ]);

        // Send the tenant their link right away; the daily command handles
        // reminders from here.
        $this->linkService->sendHoaLink($token);
    }

    /**
     * The tenant gets the configured window of business days, but never counted
     * from a date already in the past: notices reach us days or weeks after they
     * were written, and counting from the notice date made a violation overdue
     * the moment it was uploaded — flagging staff to send a vendor before the
     * tenant had been given a single day to fix it.
     *
     * The HOA's own stated deadline caps it. Running past the date the
     * association actually set would escalate too late to be any use, so
     * whichever comes first wins — but never earlier than two business days
     * from now. Notices regularly arrive after the date the HOA printed on
     * them, and honoring a deadline that has already passed made the violation
     * born-overdue: staff were flagged to send a vendor before the tenant got
     * a single reminder (WO#43864 was flagged the day it was uploaded).
     */
    private function resolveDeadline(Carbon $noticeDate, ?Carbon $statedDeadline = null): Carbon
    {
        $start = $noticeDate->isPast() ? now() : $noticeDate->copy();

        $deadline = $start->copy()
            ->addWeekdays((int) config('services.hoa.deadline_business_days', 5))
            ->endOfDay();

        if ($statedDeadline instanceof Carbon && $statedDeadline->copy()->endOfDay()->lt($deadline)) {
            $floor = now()->addWeekdays(2)->endOfDay();

            return $statedDeadline->copy()->endOfDay()->max($floor);
        }

        return $deadline;
    }
}
