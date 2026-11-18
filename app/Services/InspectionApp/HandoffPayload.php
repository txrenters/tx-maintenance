<?php

namespace App\Services\InspectionApp;

use App\Models\Building;
use App\Models\InspectionAppHandoff;
use App\Models\OutsideCustomer;
use App\Models\Tenants;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PhoneFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What goes inside the envelope: everything the inspection app needs to put
 * the job on a technician's day. The work order itself, the property with its
 * PropertyWare ids and map pin (or the outside customer for a Crystal Creek
 * Air job), whether someone lives there and how to reach them, which vendors
 * are on it, and the Jobber job it already links to.
 *
 * The idempotency key is the work order's id here, so the app files a
 * retried envelope once. The shape is a contract with the other developer:
 * add fields freely, never rename or drop one without telling them.
 */
class HandoffPayload
{
    public const SOURCE_SYSTEM = 'tx-maintenance';

    public function __construct(private HandoffRule $rule, private ApprovalGate $gate) {}

    /**
     * @param  InspectionAppHandoff|null  $row  The outbox row, for the rule that let it through and the AI reading behind it.
     * @return array<string, mixed>
     */
    public function build(WorkOrder $workOrder, ?InspectionAppHandoff $row = null): array
    {
        $workOrder->loadMissing(['vendors', 'building', 'tenants', 'outsideCustomer', 'service_status']);

        return [
            'idempotency_key' => self::SOURCE_SYSTEM.':work-order:'.$workOrder->id,
            'source_system' => self::SOURCE_SYSTEM,
            'work_order' => [
                'id' => $workOrder->id,
                'number' => $workOrder->work_order_no === null ? null : (int) $workOrder->work_order_no,
                'propertyware_id' => $this->stringOrNull($workOrder->propertyware_id),
                'source' => $this->stringOrNull($workOrder->source),
                'is_crystal_creek' => $workOrder->isCrystalCreek(),
                'status' => $this->stringOrNull($workOrder->status),
                'service_status' => $this->stringOrNull($workOrder->service_status?->name),
                'local_status' => $this->stringOrNull($workOrder->local_status),
                'priority' => $this->stringOrNull($workOrder->priority),
                'is_emergency' => $workOrder->is_emergency === null ? null : (bool) $workOrder->is_emergency,
                'cost_estimate' => $this->gate->estimate($workOrder),
                'category' => $this->stringOrNull($workOrder->category),
                'type' => $this->stringOrNull($workOrder->type),
                'description' => $workOrder->description,
                'specific_location' => $this->stringOrNull($workOrder->specific_location),
                'authorized_to_enter' => $this->stringOrNull($workOrder->authorized_to_enter),
                'zone' => $this->stringOrNull($workOrder->zone),
                'created_date' => $this->iso($workOrder->created_date),
                'approved_date' => $this->iso($workOrder->approved_date),
                'date_to_enter' => $this->iso($workOrder->date_to_enter),
                'scheduled_end_date' => $this->iso($workOrder->scheduled_end_date),
                'completed_date' => $this->iso($workOrder->completed_date),
                'jobber' => [
                    'job_gid' => $this->stringOrNull($workOrder->jobber_job_gid),
                    'web_uri' => $this->stringOrNull($workOrder->jobber_web_uri),
                ],
                'url' => route('work_orders.details', $workOrder->id),
            ],
            'property' => $this->property($workOrder),
            'outside_customer' => $this->outsideCustomer($workOrder),
            'occupied' => ! $workOrder->hasNoTenant(),
            'tenants' => $workOrder->tenants->map(fn (Tenants $tenant): array => [
                'name' => trim(trim((string) $tenant->first_name).' '.trim((string) $tenant->last_name)),
                'phone' => $workOrder->normalizedTenantPhone($tenant),
                'email' => $this->stringOrNull($tenant->email),
            ])->values()->all(),
            'vendors' => $workOrder->vendors->map(fn (Vendor $vendor): array => [
                'propertyware_id' => $this->stringOrNull($vendor->propertyware_id),
                'name' => $this->stringOrNull($vendor->name),
                'in_house' => $this->rule->isInHouse($vendor),
            ])->values()->all(),
            'notes' => $this->notes($workOrder),
            'approval' => $this->approval($workOrder, $row),
        ];
    }

    /**
     * The Notes tab, oldest first. Private notes go too, flagged: the crew
     * is in-house, and the office asked for all the information.
     *
     * @return list<array{subject: ?string, body: string, author: ?string, date: ?string, is_private: bool}>
     */
    private function notes(WorkOrder $workOrder): array
    {
        // The receiver takes at most 50 notes of 4000 characters: the newest
        // 50, still oldest first, each cut to fit, rather than a 422.
        return WorkOrderNotes::query()
            ->with('user')
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkOrderNotes $note): ?array => trim((string) $note->body) === '' ? null : [
                'subject' => $this->clip($this->stringOrNull($note->subject), 200),
                'body' => $this->clip(trim((string) $note->body), 4000),
                'author' => $this->clip($this->stringOrNull($note->user?->name), 120),
                'date' => $this->iso($note->date ?: $note->created_at),
                'is_private' => (bool) $note->is_private,
            ])
            ->filter()
            ->slice(-50)
            ->values()
            ->all();
    }

    /**
     * Cut to the receiver's limit, counting characters as it does. Null
     * stays null.
     */
    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
    }

    /**
     * Which rule let it through and what stood behind it: the recorded
     * approver, or the AI's reading with the owner's quoted words.
     *
     * @return array<string, mixed>
     */
    private function approval(WorkOrder $workOrder, ?InspectionAppHandoff $row): array
    {
        // The reading goes only when it is what let the work order through.
        // A reading left over from an earlier hold must not ride along on a
        // send made under another rule: "declined" beside "approval on record"
        // would tell the crew two things at once.
        $reading = $row?->rule === InspectionAppHandoff::RULE_OWNER_APPROVED_IN_MESSAGES ? $row->aiInsight : null;
        $summary = $reading === null ? null : OwnerApprovalReader::summarize($reading);
        $recorded = $this->gate->approvalRecorded($workOrder);

        return [
            'rule' => $row?->rule,
            'threshold' => $this->gate->threshold(),
            'estimate' => $this->gate->estimate($workOrder),
            'recorded_by' => $recorded ? $this->clip($this->stringOrNull($workOrder->approved_by), 120) : null,
            'recorded_at' => $recorded ? $this->iso($workOrder->approved_date) : null,
            'ai_confidence' => $summary['confidence'] ?? null,
            'ai_read_at' => $summary['read_at'] ?? null,
            'summary' => $this->clip($summary['summary'] ?? null, 1000),
            'problems' => array_map(fn (array $problem): array => [
                'item' => (string) $this->clip((string) ($problem['item'] ?? ''), 120),
                'decision' => (string) ($problem['decision'] ?? 'not_mentioned'),
                'evidence' => $this->clip($this->stringOrNull($problem['evidence'] ?? null), 500),
            ], array_slice($summary['problems'] ?? [], 0, 30)),
        ];
    }

    /**
     * A fingerprint of the payload, so a work order that has not changed is
     * not sent again.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function property(WorkOrder $workOrder): ?array
    {
        $building = $workOrder->building;

        if (! $building instanceof Building) {
            return null;
        }

        return [
            'propertyware_building_id' => $this->stringOrNull($building->propertyware_id),
            'propertyware_unit_id' => $this->stringOrNull($workOrder->unit_id),
            'propertyware_lease_id' => $this->stringOrNull($workOrder->lease_id),
            'portfolio_id' => $this->stringOrNull($workOrder->portfolio_id ?? $building->portfolio_id),
            'name' => $this->stringOrNull($building->name),
            'address' => $this->stringOrNull($building->address),
            'address_cont' => $this->stringOrNull($building->address_cont),
            'city' => $this->stringOrNull($building->city),
            'state' => $this->stringOrNull($building->state_region),
            'postal_code' => $this->stringOrNull($building->postal_code),
            'latitude' => $building->latitude === null ? null : (float) $building->latitude,
            'longitude' => $building->longitude === null ? null : (float) $building->longitude,
            'location' => $this->stringOrNull($workOrder->location),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outsideCustomer(WorkOrder $workOrder): ?array
    {
        $customer = $workOrder->outsideCustomer;

        if (! $workOrder->isCrystalCreek() || ! $customer instanceof OutsideCustomer) {
            return null;
        }

        return [
            'name' => $this->stringOrNull($customer->name),
            'phone' => $customer->normalizedPhone() ?? PhoneFormatter::e164($customer->phone),
            'email' => $this->stringOrNull($customer->email),
            'address' => $customer->oneLineAddress(),
            'street' => $this->stringOrNull($customer->street),
            'city' => $this->stringOrNull($customer->city),
            'state' => $this->stringOrNull($customer->state),
            'postal_code' => $this->stringOrNull($customer->postal_code),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
