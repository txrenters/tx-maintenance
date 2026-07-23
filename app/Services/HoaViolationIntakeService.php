<?php

namespace App\Services;

use App\Models\Attachments;
use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns an uploaded HOA violation notice into a working work order:
 * AI-read the notice, create the work order in PropertyWare (falling back to a
 * local-only row), attach the notice PDF, set the Tenant Easy Fix status, and
 * open the tenant portal token that drives the reminder/deadline workflow.
 */
class HoaViolationIntakeService
{
    public const CATEGORY = 'HOA Violation';

    public const EASY_FIX_STATUS = 'Checking for Tenant Easy Fix';

    public function __construct(
        private readonly PropertyWareService $propertyWare,
        private readonly HoaNoticeExtractor $extractor,
        private readonly TenantPortalLinkService $linkService,
    ) {}

    /**
     * @return array{work_order: WorkOrder, created: bool, pw_created: bool, description: string}
     */
    public function handle(Building $building, UploadedFile $noticeFile, ?Carbon $noticeDate): array
    {
        $pdfContents = (string) file_get_contents($noticeFile->getRealPath());

        $extracted = $this->extractor->extract($pdfContents);

        $description = $this->buildDescription($extracted);
        $noticeDate = $noticeDate ?? $extracted['notice_date'] ?? now();

        // An open HOA work order for the same property means this notice is a
        // follow-up — attach to it instead of creating a duplicate.
        $workOrder = $this->findExistingOpenHoaWorkOrder($building);
        $created = false;
        $pwCreated = false;

        if ($workOrder === null) {
            [$workOrder, $pwCreated] = $this->createWorkOrder($building, $description);
            $created = true;
        }

        $this->attachNotice($workOrder, $noticeFile, $pdfContents);
        $this->applyEasyFixStatus($workOrder, $description, $created);
        $this->openHoaToken($workOrder, Carbon::parse($noticeDate));

        return [
            'work_order' => $workOrder->refresh(),
            'created' => $created,
            'pw_created' => $pwCreated,
            'description' => $description,
        ];
    }

    /**
     * @param  array{description: string, violation_items: array<int, string>, hoa_name: ?string}  $extracted
     */
    private function buildDescription(array $extracted): string
    {
        $lines = [$extracted['description']];

        if ($extracted['violation_items'] !== []) {
            $lines[] = '';
            $lines[] = 'Items to correct:';

            foreach ($extracted['violation_items'] as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (filled($extracted['hoa_name'])) {
            $lines[] = '';
            $lines[] = 'Notice issued by: '.$extracted['hoa_name'];
        }

        return implode("\n", $lines);
    }

    private function findExistingOpenHoaWorkOrder(Building $building): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('building_id', $building->propertyware_id)
            ->where('category', self::CATEGORY)
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
                'category' => self::CATEGORY,
                'description' => $description,
                'type' => '',
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
            'category' => self::CATEGORY,
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

    private function attachNotice(WorkOrder $workOrder, UploadedFile $noticeFile, string $pdfContents): void
    {
        $fileName = 'HOA Notice - WO'.($workOrder->work_order_no ?? $workOrder->id).' - '.now()->format('Y-m-d').'.pdf';

        Attachments::create([
            'title' => 'HOA violation notice',
            'filename' => $noticeFile->store('attachments', 'public'),
            'filetype' => $noticeFile->getMimeType() ?: 'application/pdf',
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => auth()->id() ?? $workOrder->requested_by?->user_id ?? User::query()->value('id'),
            'pw_file_name' => $fileName,
            'created_at' => now(),
        ]);

        if ($workOrder->propertyware_id) {
            $this->propertyWare->uploadWorkOrderPdf(
                (string) $workOrder->propertyware_id,
                $pdfContents,
                $fileName,
                'HOA violation notice uploaded via the maintenance app.'
            );
        }
    }

    /**
     * Straight to Tenant Easy Fix: the tenant portal machinery keys off this
     * service status. Also make sure the local row carries the HOA category and
     * the extracted description (the import may have brought back blanks).
     */
    private function applyEasyFixStatus(WorkOrder $workOrder, string $description, bool $created): void
    {
        $statusId = ServiceStatus::query()->where('name', self::EASY_FIX_STATUS)->value('id');

        $updates = ['service_status_id' => $statusId ?? $workOrder->service_status_id];

        if ($created) {
            $updates['category'] = self::CATEGORY;
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
     * reminders, the 5-business-day deadline, escalation, and confirmation.
     */
    private function openHoaToken(WorkOrder $workOrder, Carbon $noticeDate): void
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
            'hoa_deadline_at' => $noticeDate->copy()->addWeekdays(
                (int) config('services.hoa.deadline_business_days', 5)
            )->endOfDay(),
        ]);

        // Send the tenant their link right away; the daily command handles
        // reminders from here.
        $this->linkService->sendHoaLink($token);
    }
}
