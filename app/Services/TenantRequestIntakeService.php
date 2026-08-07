<?php

namespace App\Services;

use App\Jobs\GenerateThumbnail;
use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Opens a brand new work order from the tenant portal's "Report a new issue"
 * button. The tenant arrives on a portal link for one work order; anything else
 * they notice broken becomes its own request rather than a message a
 * coordinator has to re-key into PropertyWare by hand.
 *
 * Like HOA intake, PropertyWare is the system of record: create there first and
 * import the row straight back, falling back to a local-only work order so the
 * tenant's words and photos are never lost when PropertyWare is unavailable.
 */
class TenantRequestIntakeService
{
    /** The activity-log event that is both the staff notification and the anti-spam marker. */
    public const CREATED_EVENT = 'tenant_portal_work_order_created';

    public function __construct(
        private readonly PropertyWareWorkOrderCreator $creator,
        private readonly TenantPortalLinkService $linkService,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $photos
     * @return array{work_order: ?WorkOrder, token: ?TenantUploadToken, pw_created: bool, reason: ?string}
     */
    public function createForTenant(WorkOrder $source, string $description, array $photos = []): array
    {
        $description = Str::squish($description);

        if ($reason = $this->refusalReason($source, $description)) {
            return ['work_order' => null, 'token' => null, 'pw_created' => false, 'reason' => $reason];
        }

        [$workOrder, $pwCreated] = $this->createWorkOrder($source, $description);

        if ($workOrder === null) {
            return ['work_order' => null, 'token' => null, 'pw_created' => false, 'reason' => 'failed'];
        }

        $this->attachPhotos($workOrder, $source, $photos);

        $token = $this->linkService->tokenFor($workOrder);

        $this->recordOnSource($source, $workOrder, $pwCreated);

        $this->dispatchIntakeAutomations($workOrder);

        return ['work_order' => $workOrder, 'token' => $token, 'pw_created' => $pwCreated, 'reason' => null];
    }

    /**
     * The portal is public — a token in a URL is the only thing standing in
     * front of this — so refuse the obvious abuse and misfire cases before
     * anything reaches PropertyWare. Every check is durable (it reads the
     * activity log or the work orders themselves), so it survives a deploy and
     * is scoped to the link rather than to an IP a whole city shares behind
     * carrier NAT.
     */
    private function refusalReason(WorkOrder $source, string $description): ?string
    {
        $cooldownMinutes = (int) config('services.tenant_portal.request_cooldown_minutes');

        if ($cooldownMinutes > 0 && $this->createdEvents($source)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists()) {
            return 'cooldown';
        }

        $cap = (int) config('services.tenant_portal.max_open_requests');

        if ($cap > 0 && filled($source->building_id)) {
            $openedForProperty = Activity::query()
                ->where('event', self::CREATED_EVENT)
                ->where('properties->building_id', $source->building_id)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($openedForProperty >= $cap) {
                return 'capped';
            }
        }

        // Stored descriptions carry the provenance line this service appends,
        // so compare on the tenant's own words at the front of it.
        $duplicate = WorkOrder::query()
            ->withoutGlobalScopes()
            ->where('building_id', $source->building_id)
            ->where('created_at', '>=', now()->subDay())
            ->get(['id', 'description'])
            ->contains(fn (WorkOrder $existing) => Str::startsWith(
                Str::lower(Str::squish((string) $existing->description)),
                Str::lower($description),
            ));

        return $duplicate ? 'duplicate' : null;
    }

    /**
     * @return array{0: ?WorkOrder, 1: bool}
     */
    private function createWorkOrder(WorkOrder $source, string $description): array
    {
        $pwDescription = $this->propertyWareDescription($source, $description);

        if (config('services.tenant_portal.pw_create_enabled')) {
            [$location, $unitId] = $this->resolveLocation($source);

            if (filled($location) && filled($unitId)) {
                $workOrder = $this->creator->createAndImport([
                    'building_id' => $source->building_id,
                    'portfolio_id' => $source->portfolio_id ?: $source->building?->portfolio_id,
                    'category' => config('services.tenant_portal.pw_category'),
                    'description' => $pwDescription,
                    'type' => config('services.tenant_portal.pw_type'),
                    'location' => $location,
                    'unit_id' => $unitId,
                ]);

                if ($workOrder !== null) {
                    $this->relinkToTenant($workOrder, $source);

                    return [$workOrder->refresh(), true];
                }
            }
        }

        Log::warning('Tenant portal request created locally only (PropertyWare create unavailable).', [
            'source_work_order_id' => $source->id,
            'building_id' => $source->building_id,
        ]);

        return [$this->createLocalWorkOrder($source, $pwDescription), false];
    }

    /**
     * PropertyWare rejects a create outright unless the piped
     * "PORTFOLIO | BUILDING" location and the unit ID match what it holds, so
     * read them back off PropertyWare itself. The source work order is only a
     * fallback for a transient read failure, and only when its own location
     * carries the pipe: rows imported over REST hold PropertyWare's own
     * (possibly truncated) string, and HOA local-only rows hold the building
     * name — both are known-rejected payloads.
     *
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveLocation(WorkOrder $source): array
    {
        [$location, $unitId] = $this->creator->locationFor($source->building_id);

        if (filled($location) && filled($unitId)) {
            return [$location, $unitId];
        }

        if (str_contains((string) $source->location, ' | ') && filled($source->unit_id)) {
            return [$source->location, $source->unit_id];
        }

        return [null, null];
    }

    /**
     * The create payload carries no requestedByContact, so the imported row
     * comes back with no tenant at all — which would silently no-op the tenant
     * confirmation text, the intake email, and the new portal page's greeting.
     * Stamp it before any tenant-facing job runs against it.
     */
    private function relinkToTenant(WorkOrder $workOrder, WorkOrder $source): void
    {
        WorkOrder::query()->withoutGlobalScopes()->whereKey($workOrder->id)->update([
            'tenant_id' => $source->tenant_id,
            'user_id' => $workOrder->user_id ?: $source->user_id,
            'source' => 'Tenant Portal',
        ]);
    }

    private function createLocalWorkOrder(WorkOrder $source, string $description): ?WorkOrder
    {
        try {
            return WorkOrder::create([
                'work_order_no' => null,
                'category' => config('services.tenant_portal.pw_category'),
                'type' => config('services.tenant_portal.pw_type'),
                'description' => $description,
                'status' => 'Open',
                // The only NOT NULL column without a default.
                'service_status_id' => ServiceStatus::query()->where('name', 'New')->value('id')
                    ?? ServiceStatus::query()->value('id'),
                'building_id' => $source->building_id,
                'portfolio_id' => $source->portfolio_id,
                'unit_id' => $source->unit_id,
                'location' => $source->location,
                'tenant_id' => $source->tenant_id,
                'user_id' => $source->user_id,
                'source' => 'Tenant Portal',
                'created_date' => now(),
                'local_status' => 'Created',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Tenant portal request could not be created at all.', [
                'source_work_order_id' => $source->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Provenance goes last on purpose: the first line is what the AI classifier
     * reads and what the owner intake text quotes back to the owner.
     */
    private function propertyWareDescription(WorkOrder $source, string $description): string
    {
        return $description."\n\n(Submitted by the tenant through the tenant portal"
            .($source->work_order_no ? ' — related to WO#'.$source->work_order_no : '').'.)';
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     */
    private function attachPhotos(WorkOrder $workOrder, WorkOrder $source, array $photos): void
    {
        foreach ($photos as $photo) {
            try {
                $attachment = Attachments::create([
                    'title' => 'Tenant photo - WO#'.($workOrder->work_order_no ?? $workOrder->id),
                    'filename' => $photo->store('attachments', 'public'),
                    'filetype' => $photo->getMimeType(),
                    'type' => 'before',
                    'work_order_id' => $workOrder->id,
                    'user_id' => $source->requested_by?->user_id,
                    'uploaded_via_tenant_portal' => true,
                    'is_publish_to_owner_portal' => true,
                    'is_publish_to_tenant_portal' => true,
                    'created_at' => now(),
                ]);

                UploadAttachment::dispatch($attachment);
                GenerateThumbnail::dispatch(Attachments::class, $attachment->id);
            } catch (\Throwable $exception) {
                // The request itself is already open; losing one photo must not
                // cost the tenant the whole submission.
                Log::warning('Tenant portal request photo could not be stored.', [
                    'work_order_id' => $workOrder->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Leave a trail on the work order the tenant was actually looking at: a
     * line in their coordinator thread, and the activity row that both feeds
     * the staff notification bell and anchors the anti-spam checks above.
     */
    private function recordOnSource(WorkOrder $source, WorkOrder $workOrder, bool $pwCreated): void
    {
        $number = $workOrder->work_order_no ?? $workOrder->id;

        try {
            DB::transaction(function () use ($source, $workOrder, $pwCreated, $number) {
                Conversation::create([
                    'message' => 'I submitted a new request: #'.$number.' — '.Str::limit((string) $workOrder->description, 120),
                    'sender_number' => 'portal',
                    'work_order_id' => $source->id,
                    'conversation_type' => 'tenant',
                    'is_read' => false,
                    'read_by_tenant' => true,
                ]);

                activity()
                    ->performedOn($source)
                    ->event(self::CREATED_EVENT)
                    ->withProperties([
                        'work_order_id' => $workOrder->id,
                        'source_work_order_id' => $source->id,
                        'building_id' => $source->building_id,
                        'pw_created' => $pwCreated,
                        'message' => 'Tenant submitted a new request: #'.$number,
                    ])
                    ->log('Work Order #'.$source->work_order_no.' - Tenant Submitted a New Request');
            });
        } catch (\Throwable $exception) {
            Log::warning('Tenant portal request could not be recorded on the source work order.', [
                'source_work_order_id' => $source->id,
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The standard new-work-order fan-out, dispatched here rather than through
     * WorkOrderService's flag so it runs only after the row has been stamped
     * with its tenant.
     *
     * The AI recommendation job matters more here than anywhere else: the form
     * deliberately has no category or emergency field, so its classification is
     * the only thing that will spot a genuine emergency in what the tenant
     * typed. AdoptCategorizedHoaViolationJob is deliberately absent — the
     * configured category is never "HOA Violation", so it could only ever be a
     * no-op.
     *
     * paused_automations is deliberately NOT inherited: it is a coordinator's
     * mute on one conversation, and must not silence a brand new request they
     * have not seen yet. Tenant spam is handled by the refusal checks above.
     */
    private function dispatchIntakeAutomations(WorkOrder $workOrder): void
    {
        GenerateWorkOrderRecommendationJob::dispatch($workOrder->id, allowAutoAssign: true);
        SendOwnerServiceRequestNotificationJob::dispatch($workOrder->id);
        SendTenantWorkOrderIntakeEmailJob::dispatch($workOrder->id);
        SendTenantServiceRequestNotificationJob::dispatch($workOrder->id);
    }

    /** @return Builder<Activity> */
    private function createdEvents(WorkOrder $source)
    {
        return Activity::query()
            ->where('event', self::CREATED_EVENT)
            ->where('subject_type', WorkOrder::class)
            ->where('subject_id', $source->id);
    }
}
