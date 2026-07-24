<?php

namespace App\Services;

use App\Models\Attachments;
use App\Models\Building;
use App\Models\ServiceStatus;
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
        $this->openHoaToken($workOrder, $noticeDate, $notice);

        return [
            'work_order' => $workOrder->refresh(),
            'created' => $created,
            'pw_created' => $pwCreated,
            'description' => $description,
        ];
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

    private function findExistingOpenHoaWorkOrder(Building $building): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('building_id', $building->propertyware_id)
            ->hoaViolations()
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
            $propertywareId = $this->propertyWare->createWorkOrder([
                'building_id' => $building->propertyware_id,
                'portfolio_id' => $building->portfolio_id,
                'category' => config('services.hoa.pw_category'),
                'description' => $description,
                'type' => config('services.hoa.pw_type'),
            ]);

            if ($propertywareId !== null) {
                $workOrder = $this->importCreatedWorkOrder($propertywareId);

                if ($workOrder !== null) {
                    return [$workOrder, true];
                }
            }
        }

        Log::warning('HOA work order created locally only (PropertyWare create unavailable).', [
            'building_propertyware_id' => $building->propertyware_id,
        ]);

        $workOrder = WorkOrder::create([
            'work_order_no' => null,
            'category' => config('services.hoa.pw_category'),
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

    private function importCreatedWorkOrder(string $propertywareId): ?WorkOrder
    {
        try {
            $pwWorkOrder = $this->propertyWare->getWorkOrder($propertywareId);
            $number = is_array($pwWorkOrder) ? ($pwWorkOrder['number'] ?? null) : null;

            if ($number) {
                $workOrders = $this->propertyWare->getWorkOrderByNumber((int) $number);

                if (is_array($workOrders) && $workOrders !== []) {
                    (new WorkOrderService)->handle($workOrders);
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Importing the created HOA work order back from PropertyWare failed.', [
                'propertyware_id' => $propertywareId,
                'error' => $exception->getMessage(),
            ]);
        }

        return WorkOrder::query()->where('propertyware_id', $propertywareId)->first();
    }

    private function attachNotice(WorkOrder $workOrder, string $noticeContents, ?string $originalName, ?string $mime): void
    {
        $mime = $mime ?: 'application/pdf';
        $extension = $this->extensionForMime($mime);

        $fileName = 'HOA Notice - WO'.($workOrder->work_order_no ?? $workOrder->id).' - '.now()->format('Y-m-d').'.'.$extension;
        $storedPath = 'attachments/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->put($storedPath, $noticeContents);

        Attachments::create([
            'title' => 'HOA violation notice',
            'filename' => $storedPath,
            'filetype' => $mime,
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => auth()->id() ?? $workOrder->requested_by?->user_id ?? User::query()->value('id'),
            'pw_file_name' => $fileName,
            'created_at' => now(),
        ]);

        // PropertyWare's upload endpoint expects a PDF; only push when the notice
        // is one (a photo upload still lives on the local work order).
        if ($workOrder->propertyware_id && $mime === 'application/pdf') {
            $this->propertyWare->uploadWorkOrderPdf(
                (string) $workOrder->propertyware_id,
                $noticeContents,
                $fileName,
                'HOA violation notice uploaded via the maintenance app.'
            );
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
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
     * reminders, deadline, escalation, and confirmation. The deadline honours
     * whatever the notice actually stated — an explicit "resolve by" date, or a
     * "within N days" window — falling back to the configured business days.
     *
     * @param  array{deadline_date?: ?Carbon, deadline_days?: ?int}  $notice
     */
    private function openHoaToken(WorkOrder $workOrder, Carbon $noticeDate, array $notice): void
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
            'hoa_deadline_at' => $this->resolveDeadline($noticeDate, $notice),
        ]);

        // Send the tenant their link right away; the daily command handles
        // reminders from here.
        $this->linkService->sendHoaLink($token);
    }

    /**
     * @param  array{deadline_date?: ?Carbon, deadline_days?: ?int}  $notice
     */
    private function resolveDeadline(Carbon $noticeDate, array $notice): Carbon
    {
        if (($notice['deadline_date'] ?? null) instanceof Carbon) {
            return $notice['deadline_date']->copy()->endOfDay();
        }

        if (filled($notice['deadline_days'] ?? null)) {
            return $noticeDate->copy()->addDays((int) $notice['deadline_days'])->endOfDay();
        }

        return $noticeDate->copy()
            ->addWeekdays((int) config('services.hoa.deadline_business_days', 5))
            ->endOfDay();
    }
}
