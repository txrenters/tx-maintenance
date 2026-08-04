<?php

namespace App\Services;

use App\Models\WorkOrder;

/**
 * Puts names to the phone numbers in a conversation.
 *
 * work_order_conversations stores numbers, not people, so every surface that
 * shows a thread — the Inbox list, the message bubbles, the arrival banner —
 * needs the same lookup. Keeping it here stops those drifting apart.
 */
class ConversationParticipants
{
    /**
     * Who the coordinator is talking to on this thread.
     *
     * Messages tagged with a vendor_id or owner_id name that record directly.
     * Rows that predate those columns fall back to the work order's only
     * vendor/owner — unambiguous when there is just one — and finally to the
     * raw number, which is still better than nothing.
     *
     * @param  object  $row  Anything carrying conversation_type, vendor_id, owner_id, sender_number.
     */
    public function counterpartyName(object $row, ?WorkOrder $workOrder): string
    {
        if (! $workOrder) {
            return $row->sender_number ?? 'Unknown';
        }

        $name = match ($row->conversation_type) {
            'vendor', 'vendor_tenant' => $this->vendorName($row, $workOrder),
            'owner', 'vendor_owner' => $this->ownerName($row, $workOrder),
            'tenant' => $this->tenantName($workOrder),
            default => null,
        };

        return $name ?: ($row->sender_number ?? 'Unknown');
    }

    private function vendorName(object $row, WorkOrder $workOrder): ?string
    {
        if ($row->vendor_id) {
            return $workOrder->vendors->firstWhere('id', $row->vendor_id)?->name;
        }

        return $workOrder->vendors->count() === 1
            ? $workOrder->vendors->first()?->name
            : null;
    }

    private function ownerName(object $row, WorkOrder $workOrder): ?string
    {
        $owner = $row->owner_id
            ? $workOrder->owners->firstWhere('id', $row->owner_id)
            : ($workOrder->owners->count() === 1 ? $workOrder->owners->first() : null);

        return $owner ? (trim($owner->first_name.' '.$owner->last_name) ?: null) : null;
    }

    private function tenantName(WorkOrder $workOrder): ?string
    {
        $tenant = $workOrder->requested_by;

        return $tenant ? (trim($tenant->first_name.' '.$tenant->last_name) ?: null) : null;
    }

    /**
     * Names for every number that can appear in this work order's threads, in
     * the shape MessageCard's `participants` prop expects.
     *
     * @return array<string, array{name: string, role: string, avatar: string}>
     */
    public function mapFor(WorkOrder $workOrder): array
    {
        $participants = [];

        $add = function (?string $phone, ?string $name, string $role, ?string $avatar = null) use (&$participants) {
            $key = $this->phoneKey($phone);
            $name = trim((string) $name);

            if ($key === '' || $name === '' || isset($participants[$key])) {
                return;
            }

            $participants[$key] = [
                'name' => $name,
                'role' => $role,
                'avatar' => $avatar ?? '',
            ];
        };

        foreach ($workOrder->vendors as $vendor) {
            $add($vendor->twilio_number, $vendor->name, 'Vendor');
            $add($vendor->user?->phone, $vendor->name, 'Vendor');
        }

        foreach ($workOrder->owners as $owner) {
            $add($owner->phone, trim($owner->first_name.' '.$owner->last_name), 'Owner');
        }

        if ($tenant = $workOrder->requested_by) {
            $add($tenant->mobile_phone, trim($tenant->first_name.' '.$tenant->last_name), 'Tenant');
        }

        $add(
            $this->wocNumberFor($workOrder),
            $workOrder->woc?->name,
            'Coordinator',
            $workOrder->woc?->profile_photo_url
        );

        return $participants;
    }

    public function wocNumberFor(WorkOrder $workOrder): ?string
    {
        // Same fallback the conversation tabs use via the shared Inertia prop.
        return $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?? env('MAINTENANC_TWILIO_PHONE_NUMBER');
    }

    public function phoneKey(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    public function partyLabel(?string $conversationType): string
    {
        return match ($conversationType) {
            'tenant' => 'Tenant',
            'owner' => 'Owner',
            'vendor' => 'Vendor',
            'vendor_tenant' => 'Vendor–Tenant',
            'vendor_owner' => 'Vendor–Owner',
            default => 'Message',
        };
    }
}
