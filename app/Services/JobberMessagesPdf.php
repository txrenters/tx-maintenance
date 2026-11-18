<?php

namespace App\Services;

use App\Models\Jobber;
use App\Models\JobberTextMessage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A Jobber job's text messages as a downloadable PDF transcript, using the same
 * layout as work order conversations.
 *
 * Unlike the work order tabs, the job modal only shows the first 50 messages,
 * so the whole thread is loaded here rather than the ids on screen. Senders are
 * named from the job's client, saved contacts and linked tenants. Photos we
 * sent sit on the public disk and are embedded; inbound photos are Twilio URLs
 * dompdf cannot fetch, so they are listed as attachments.
 */
class JobberMessagesPdf
{
    /** Larger images are listed by name rather than embedded. */
    private const MAX_EMBEDDED_IMAGE_BYTES = 5 * 1024 * 1024;

    private const EMBEDDABLE_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];

    public function render(Jobber $job, ?string $ourNumber): string
    {
        $job->loadMissing(['client', 'property', 'clientContacts', 'tenants']);

        $people = $this->participantsByPhone($job);
        $ourKey = $this->phoneKey($ourNumber);

        $messages = $job->textMessages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (JobberTextMessage $message) => $this->present($message, $ourKey, $people));

        $property = $job->property;
        $title = $job->title ?: 'Job Messages';

        return Pdf::loadView('pdf.conversation', [
            'heading' => $job->job_number ? "Job #{$job->job_number} — {$title}" : $title,
            'address' => collect([
                $property?->street,
                $property?->city,
                trim(($property?->province ?? '').' '.($property?->postal_code ?? '')),
            ])->filter()->implode(', '),
            'participants' => $people->values(),
            'messages' => $messages,
            'printedAt' => now(ConversationPdf::TIMEZONE),
        ])->output();
    }

    public function fileName(Jobber $job): string
    {
        $prefix = $job->job_number ? "Job-{$job->job_number} " : '';

        return Str::of("{$prefix}messages")->replaceMatches('/[^A-Za-z0-9 #\-]/', '')->squish().'.pdf';
    }

    /**
     * @return Collection<string, array{name: string, role: string}>
     */
    private function participantsByPhone(Jobber $job): Collection
    {
        $people = collect();

        if ($job->client) {
            $people->push(['phone' => $job->client->phone, 'name' => $job->client->name, 'role' => 'Client']);
        }

        foreach ($job->clientContacts as $contact) {
            $people->push(['phone' => $contact->phone, 'name' => $contact->name, 'role' => 'Contact']);
        }

        foreach ($job->tenants as $tenant) {
            $name = trim("{$tenant->first_name} {$tenant->last_name}");
            foreach ([$tenant->mobile_phone, $tenant->home_phone] as $phone) {
                $people->push(['phone' => $phone, 'name' => $name, 'role' => 'Tenant']);
            }
        }

        return $people
            ->map(fn (array $person) => [
                'key' => $this->phoneKey($person['phone']),
                'name' => trim((string) $person['name']),
                'role' => $person['role'],
            ])
            ->filter(fn (array $person) => $person['key'] !== '' && $person['name'] !== '')
            ->unique('key')
            ->mapWithKeys(fn (array $person) => [$person['key'] => ['name' => $person['name'], 'role' => $person['role']]]);
    }

    /**
     * @param  Collection<string, array{name: string, role: string}>  $people
     * @return array{outbound: bool, from: string, to: string, sent_at: string, body: string, failed: ?string, images: array<int, string>, files: array<int, string>}
     */
    private function present(JobberTextMessage $message, string $ourKey, Collection $people): array
    {
        $outbound = $ourKey !== '' && $ourKey === $this->phoneKey($message->sender_number);

        $images = [];
        $files = [];
        if ($message->image) {
            $embedded = $this->embeddedImage($message->image);
            if ($embedded !== null) {
                $images[] = $embedded;
            } else {
                $files[] = Str::startsWith($message->image, ['http://', 'https://'])
                    ? 'Photo (not stored locally)'
                    : basename($message->image);
            }
        }

        $status = strtolower((string) ($message->twilio_status ?? $message->status ?? ''));

        return [
            'outbound' => $outbound,
            'from' => $this->personLabel($message->sender_number, $people, $outbound ? 'Coordinator' : ''),
            'to' => $outbound
                ? $this->personLabel($message->receiver_number, $people)
                : 'Coordinator',
            'sent_at' => $message->created_at
                ? $message->created_at->copy()->setTimezone(ConversationPdf::TIMEZONE)->format('D, M j, Y g:i A')
                : '',
            'body' => (string) $message->messages,
            'failed' => in_array($status, ['failed', 'undelivered', 'canceled'], true) ? $status : null,
            'images' => $images,
            'files' => $files,
        ];
    }

    /**
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

    private function embeddedImage(string $path): ?string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return null;
        }

        $storage = Storage::disk('public');
        if (! $storage->exists($path) || $storage->size($path) > self::MAX_EMBEDDED_IMAGE_BYTES) {
            return null;
        }

        $type = strtolower((string) $storage->mimeType($path));
        if (! in_array($type, self::EMBEDDABLE_TYPES, true)) {
            return null;
        }

        return "data:{$type};base64,".base64_encode($storage->get($path));
    }
}
