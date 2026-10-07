<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The texts a tenant or owner exchanged with us about their work orders
 * before the support app took over messaging, for the client portal to show
 * as closed history.
 *
 * Across all their work orders, a message is only returned when all of these
 * hold:
 *  1. the person is the PropertyWare contact the portal asked for;
 *  2. the work order is one they are linked to, as a tenant or as an owner;
 *  3. the message is on their side of the work order (tenant or owner, never
 *     vendor or internal);
 *  4. the message was sent to or from one of the numbers on their own record
 *     (and, for owners, names them when it records an owner at all).
 *
 * For one work order's page, rule 4 widens to the person's household on it:
 * the tenants on the work order's lease, when the person is one of them, or
 * the property's owners. Whoever reported it from outside the lease is not
 * part of that household (a former tenant, say), so neither side sees the
 * other's texts. Each message says whether it was the person's own, and a
 * message no one in the household can be matched to is still left out.
 *
 * Messages the support app already holds are left out, so the portal never
 * shows one twice.
 */
class PortalMessageHistory
{
    /** The most messages returned for one person. */
    public const LIMIT = 500;

    /**
     * @param  'tenant'|'owner'  $party
     * @param  ?string  $workOrderId  A PropertyWare work order id, for everyone's texts on that one work order.
     * @return list<array{work_order: array{id: int, propertyware_id: ?string, number: ?string, title: ?string, status: ?string}, messages: list<array{id: int, from: 'client'|'other'|'staff', to_you: bool, body: string, media: list<string>, sent_at: ?string}>}>
     */
    public function for(string $contactId, string $party, ?string $workOrderId = null): array
    {
        $people = $party === 'owner'
            ? Owner::query()->where('propertyware_id', $contactId)->get()
            : Tenants::query()->where('propertyware_id', $contactId)->get();

        if ($people->isEmpty()) {
            return [];
        }

        $mine = $this->numbers($people, $party);
        $workOrderIds = $this->workOrderIds($people->modelKeys(), $party, $workOrderId);

        if ($workOrderIds->isEmpty() || ($workOrderId === null && $mine->isEmpty())) {
            return [];
        }

        // The rest of the person's household on the one work order, by number.
        $others = $workOrderId === null
            ? collect()
            : $this->numbers($this->householdOn($workOrderIds->first(), $party, $people->modelKeys()), $party)->diff($mine)->values();

        $messages = Conversation::query()
            ->withoutGlobalScopes()
            ->with('media')
            ->whereIn('work_order_id', $workOrderIds)
            ->where('conversation_type', $party)
            ->whereNull('chatbot_thread_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('chatbot_message_deliveries')
                ->whereColumn('chatbot_message_deliveries.conversation_id', 'work_order_conversations.id'))
            ->when($party === 'owner' && $workOrderId === null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('owner_id')
                ->orWhereIn('owner_id', $people->modelKeys())))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT * 4)
            ->get()
            ->map(fn (Conversation $message): ?array => $this->present($message, $mine, $others))
            ->filter()
            ->take(self::LIMIT)
            ->values();

        if ($messages->isEmpty()) {
            return [];
        }

        $workOrders = WorkOrder::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $messages->pluck('work_order_id')->unique())
            ->get()
            ->keyBy('id');

        return $messages
            ->groupBy('work_order_id')
            ->map(function (Collection $thread, int $id) use ($workOrders): array {
                $workOrder = $workOrders->get($id);

                return [
                    'work_order' => [
                        'id' => $id,
                        'propertyware_id' => filled($workOrder?->propertyware_id) ? (string) $workOrder->propertyware_id : null,
                        'number' => filled($workOrder?->work_order_no) ? (string) $workOrder->work_order_no : null,
                        'title' => $workOrder?->title,
                        'status' => $workOrder?->status,
                    ],
                    'messages' => $thread
                        ->reverse()
                        ->map(fn (array $message): array => collect($message)->except('work_order_id')->all())
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The last ten digits of every number on these records.
     *
     * @param  Collection<int, Owner|Tenants>  $people
     * @return Collection<int, string>
     */
    private function numbers(Collection $people, string $party): Collection
    {
        return $people
            ->flatMap(fn ($person): array => $party === 'owner'
                ? [$person->mobile, $person->phone]
                : [$person->mobile_phone, $person->home_phone])
            ->map(fn (?string $number): ?string => Conversation::lastTenDigits($number))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * The person's household on a work order: the property's owners, or the
     * tenants on its lease when the person is one of them. Empty otherwise.
     *
     * @param  list<int>  $personIds
     * @return Collection<int, Owner|Tenants>
     */
    private function householdOn(int $workOrderId, string $party, array $personIds): Collection
    {
        $workOrder = WorkOrder::query()->withoutGlobalScopes()->find($workOrderId);
        $household = match (true) {
            $workOrder === null => collect(),
            $party === 'owner' => $workOrder->owners()->get(),
            default => $workOrder->tenants()->get(),
        };

        return $household->contains(fn ($member): bool => in_array($member->id, $personIds, true)) ? $household : collect();
    }

    /**
     * The work orders the person is on, as the requesting tenant or on the
     * work order's tenant or owner list; or just the one asked for, when they
     * are on it.
     *
     * @param  list<int>  $personIds
     * @return Collection<int, int>
     */
    private function workOrderIds(array $personIds, string $party, ?string $workOrderId): Collection
    {
        $query = WorkOrder::query()->withoutGlobalScopes()
            ->when($workOrderId !== null, fn (Builder $query) => $query->where('propertyware_id', $workOrderId));

        if ($party === 'owner') {
            $query->whereHas('owners', fn (Builder $owners) => $owners->whereIn('owners.id', $personIds));
        } else {
            $query->where(fn (Builder $query) => $query
                ->whereIn('tenant_id', $personIds)
                ->orWhereHas('tenants', fn (Builder $tenants) => $tenants->whereIn('tenants.id', $personIds)));
        }

        return $query->pluck('id');
    }

    /**
     * The message as the portal shows it, or null when no number on it is the
     * person's or, on a work order's page, someone else's on that work order.
     *
     * @param  Collection<int, string>  $mine
     * @param  Collection<int, string>  $others
     * @return array{id: int, work_order_id: int, from: 'client'|'other'|'staff', to_you: bool, body: string, media: list<string>, sent_at: ?string}|null
     */
    private function present(Conversation $message, Collection $mine, Collection $others): ?array
    {
        $sender = Conversation::lastTenDigits($message->sender_number);
        $receiver = Conversation::lastTenDigits($message->receiver_number);

        [$from, $toYou] = match (true) {
            $mine->contains($sender) => ['client', false],
            $others->contains($sender) => ['other', false],
            $mine->contains($receiver) => ['staff', true],
            $others->contains($receiver) => ['staff', false],
            default => [null, false],
        };

        if ($from === null) {
            return null;
        }

        return [
            'id' => $message->id,
            'work_order_id' => $message->work_order_id,
            'from' => $from,
            'to_you' => $toYou,
            'body' => self::maskCodes((string) $message->message),
            'media' => $message->media->map(fn ($media): string => $media->public_url)->values()->all(),
            'sent_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Hide entry, gate, lockbox and alarm codes: the portal never shows them.
     */
    public static function maskCodes(string $body): string
    {
        return (string) preg_replace_callback(
            '/\b(codes?|lock\s?box|gate|alarm|keypad|pin|combo|combination)\b([^\d\n]{0,24})([\d#*][\d#*\- ]{1,}[\d#*])/i',
            fn (array $match): string => $match[1].$match[2].preg_replace('/[\d#*]/', '•', $match[3]),
            $body,
        );
    }
}
