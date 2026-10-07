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
 * A message is only returned when all of these hold:
 *  1. the person is the PropertyWare contact the portal asked for;
 *  2. the work order is one they are linked to, as a tenant or as an owner;
 *  3. the message is on their side of the work order (tenant or owner, never
 *     vendor or internal);
 *  4. the message was sent to or from one of the numbers on their own record
 *     (and, for owners, names them when it records an owner at all).
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
     * @return list<array{work_order: array{id: int, propertyware_id: ?string, number: ?string, title: ?string, status: ?string}, messages: list<array{id: int, from: 'client'|'staff', body: string, media: list<string>, sent_at: ?string}>}>
     */
    public function for(string $contactId, string $party): array
    {
        $people = $party === 'owner'
            ? Owner::query()->where('propertyware_id', $contactId)->get()
            : Tenants::query()->where('propertyware_id', $contactId)->get();

        if ($people->isEmpty()) {
            return [];
        }

        $numbers = $people
            ->flatMap(fn ($person): array => $party === 'owner'
                ? [$person->mobile, $person->phone]
                : [$person->mobile_phone, $person->home_phone])
            ->map(fn (?string $number): ?string => Conversation::lastTenDigits($number))
            ->filter()
            ->unique()
            ->values();

        $workOrderIds = $numbers->isEmpty() ? collect() : $this->workOrderIds($people->modelKeys(), $party);

        if ($workOrderIds->isEmpty()) {
            return [];
        }

        $messages = Conversation::query()
            ->withoutGlobalScopes()
            ->with('media')
            ->whereIn('work_order_id', $workOrderIds)
            ->where('conversation_type', $party)
            ->whereNull('chatbot_thread_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('chatbot_message_deliveries')
                ->whereColumn('chatbot_message_deliveries.conversation_id', 'work_order_conversations.id'))
            ->when($party === 'owner', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('owner_id')
                ->orWhereIn('owner_id', $people->modelKeys())))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT * 4)
            ->get()
            ->map(fn (Conversation $message): ?array => $this->present($message, $numbers))
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
            ->map(function (Collection $thread, int $workOrderId) use ($workOrders): array {
                $workOrder = $workOrders->get($workOrderId);

                return [
                    'work_order' => [
                        'id' => $workOrderId,
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
     * The work orders the person is on, as the requesting tenant or on the
     * work order's tenant or owner list.
     *
     * @param  list<int>  $personIds
     * @return Collection<int, int>
     */
    private function workOrderIds(array $personIds, string $party): Collection
    {
        $query = WorkOrder::query()->withoutGlobalScopes();

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
     * The message as the portal shows it, or null when neither number on it is
     * one of the person's.
     *
     * @param  Collection<int, string>  $numbers
     * @return array{id: int, work_order_id: int, from: 'client'|'staff', body: string, media: list<string>, sent_at: ?string}|null
     */
    private function present(Conversation $message, Collection $numbers): ?array
    {
        $from = match (true) {
            $numbers->contains(Conversation::lastTenDigits($message->sender_number)) => 'client',
            $numbers->contains(Conversation::lastTenDigits($message->receiver_number)) => 'staff',
            default => null,
        };

        if ($from === null) {
            return null;
        }

        return [
            'id' => $message->id,
            'work_order_id' => $message->work_order_id,
            'from' => $from,
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
