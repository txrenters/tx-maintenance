<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A work order conversation as a downloadable PDF transcript: the messages the
 * coordinator has on screen, oldest first, with sender, recipient and time.
 *
 * The tab decides which messages are in the thread (the vendor tab filters by
 * the vendor picked), so it sends their ids; only rows on this work order are
 * ever loaded. Images are embedded from storage because dompdf does not fetch
 * remote URLs; other attachments are listed by name.
 */
class ConversationPdf
{
    public const TIMEZONE = 'America/Chicago';

    /** Larger images are listed by name rather than embedded. */
    private const MAX_EMBEDDED_IMAGE_BYTES = 5 * 1024 * 1024;

    private const EMBEDDABLE_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];

    /**
     * @param  array<int, int>  $messageIds
     * @param  array<int, array{phone?: ?string, name?: ?string, role?: ?string}>  $participants
     */
    public function render(WorkOrder $workOrder, string $title, array $messageIds, ?string $ourNumber, array $participants): string
    {
        $workOrder->loadMissing('building');

        $people = $this->participantsByPhone($participants);
        $ourKey = $this->phoneKey($ourNumber);

        $messages = $this->messages($workOrder, $messageIds)
            ->map(fn (Conversation $message) => $this->present($message, $ourKey, $people));

        $building = $workOrder->building;

        return Pdf::loadView('pdf.conversation', [
            'heading' => $workOrder->work_order_no ? "WO#{$workOrder->work_order_no} — {$title}" : $title,
            'address' => collect([
                $building?->address,
                $building?->city,
                trim(($building?->state_region ?? '').' '.($building?->postal_code ?? '')),
            ])->filter()->implode(', '),
            'participants' => $people->values(),
            'messages' => $messages,
            'printedAt' => now(self::TIMEZONE),
        ])->output();
    }

    public function fileName(WorkOrder $workOrder, string $title): string
    {
        $party = Str::of($title)->before('↔')->trim()->whenEmpty(fn () => Str::of('Conversation'));
        $prefix = $workOrder->work_order_no ? "WO-{$workOrder->work_order_no} " : '';

        return Str::of("{$prefix}{$party} conversation")->replaceMatches('/[^A-Za-z0-9 #\-]/', '')->squish().'.pdf';
    }

    /**
     * @param  array<int, int>  $messageIds
     * @return Collection<int, Conversation>
     */
    private function messages(WorkOrder $workOrder, array $messageIds): Collection
    {
        if ($messageIds === []) {
            return collect();
        }

        return Conversation::query()
            ->with('media')
            ->where('work_order_id', $workOrder->getKey())
            ->whereIn('id', $messageIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, array{phone?: ?string, name?: ?string, role?: ?string}>  $participants
     * @return Collection<string, array{name: string, role: string}>
     */
    private function participantsByPhone(array $participants): Collection
    {
        return collect($participants)
            ->map(fn (array $person) => [
                'key' => $this->phoneKey($person['phone'] ?? null),
                'name' => trim((string) ($person['name'] ?? '')),
                'role' => trim((string) ($person['role'] ?? '')),
            ])
            ->filter(fn (array $person) => $person['key'] !== '' && $person['name'] !== '')
            ->unique('key')
            ->mapWithKeys(fn (array $person) => [$person['key'] => ['name' => $person['name'], 'role' => $person['role']]]);
    }

    /**
     * @param  Collection<string, array{name: string, role: string}>  $people
     * @return array{outbound: bool, from: string, to: string, sent_at: string, body: string, failed: ?string, images: array<int, string>, files: array<int, string>}
     */
    private function present(Conversation $message, string $ourKey, Collection $people): array
    {
        $outbound = $message->chatbot_direction
            ? $message->chatbot_direction === 'outbound'
            : ($ourKey !== '' && $ourKey === $this->phoneKey($message->sender_number));

        $from = $this->personLabel(
            $message->sender_number,
            $people,
            $message->chatbot_sender_name ?: ($outbound ? 'Coordinator' : ''),
        );
        $to = $outbound
            ? $this->personLabel($message->receiver_number, $people)
            : 'Work Order Coordinator';

        $status = strtolower((string) $message->twilio_status);

        $images = [];
        $files = [];
        foreach ($message->media as $media) {
            $embedded = $this->embeddedImage($media);
            if ($embedded !== null) {
                $images[] = $embedded;
            } else {
                $files[] = $media->file_name ?: 'Attachment';
            }
        }

        return [
            'outbound' => $outbound,
            'from' => $from,
            'to' => $to,
            'sent_at' => $message->created_at
                ? $message->created_at->copy()->setTimezone(self::TIMEZONE)->format('D, M j, Y g:i A')
                : '',
            'body' => (string) ($message->message ?? $message->messages ?? ''),
            'failed' => in_array($status, ['failed', 'undelivered', 'canceled'], true) ? $status : null,
            'images' => $images,
            'files' => $files,
        ];
    }

    /**
     * "Dana Smith (Tenant) · (512) 555-0100", or the number alone when unknown.
     *
     * @param  Collection<string, array{name: string, role: string}>  $people
     */
    private function personLabel(?string $number, Collection $people, string $fallbackName = ''): string
    {
        $person = $people->get($this->phoneKey($number));
        $name = $person['name'] ?? $fallbackName;
        $role = $person['role'] ?? '';

        $named = $name !== '' ? ($role !== '' ? "{$name} ({$role})" : $name) : '';
        $phone = $number ? PhoneFormatter::display($number) : '';

        return collect([$named, $phone])->filter()->implode(' · ') ?: 'Unknown';
    }

    /** The last ten digits, so +1 / 1 / bare formatting still matches. */
    private function phoneKey(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    private function embeddedImage(ConversationMedia $media): ?string
    {
        $type = strtolower((string) $media->content_type);
        if (! in_array($type, self::EMBEDDABLE_TYPES, true) || ! $media->local_path) {
            return null;
        }

        foreach (['local', 'public'] as $disk) {
            $storage = Storage::disk($disk);
            if ($storage->exists($media->local_path) && $storage->size($media->local_path) <= self::MAX_EMBEDDED_IMAGE_BYTES) {
                return "data:{$type};base64,".base64_encode($storage->get($media->local_path));
            }
        }

        return null;
    }
}
