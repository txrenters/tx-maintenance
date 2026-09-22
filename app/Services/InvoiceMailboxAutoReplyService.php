<?php

namespace App\Services;

use App\Models\InvoiceEmailReply;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Answers vendors who email an invoice to the invoices mailbox with a pointer
 * to their no-login portal dashboard, from that same mailbox, in their thread.
 *
 * Only vendor-looking invoice email is answered. Internal senders, bounces and
 * out-of-office replies are skipped, and so is anything that carries neither
 * an attachment nor an invoice word. The mailbox itself is left as it was:
 * nothing is marked read or moved, so staff still see every message.
 *
 * The first run looks back one hour, not through the backlog, so switching the
 * feature on cannot fire a reply at every invoice ever emailed.
 */
class InvoiceMailboxAutoReplyService
{
    public const OUTCOME_REPLIED_VENDOR = 'replied_vendor';

    public const OUTCOME_REPLIED_GENERIC = 'replied_generic';

    public const OUTCOME_SKIPPED_INTERNAL = 'skipped_internal';

    public const OUTCOME_SKIPPED_AUTOMATED = 'skipped_automated';

    public const OUTCOME_SKIPPED_NOT_INVOICE = 'skipped_not_invoice';

    public const OUTCOME_SKIPPED_RECENT = 'skipped_recent';

    private const CURSOR_KEY = 'invoices.auto_reply.cursor';

    /** @var array<int, string> */
    private const INTERNAL_DOMAINS = ['texasrenters.com', 'txhomemp.com'];

    /** @var array<int, string> */
    private const SYSTEM_SENDERS = ['no-reply', 'noreply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster'];

    private const INVOICE_WORDS = '/\b(invoices?|bills?|billing|statement|payment|remit|remittance)\b/i';

    /** @var array<int, string> */
    private const AUTOMATED_SUBJECTS = ['automatic reply', 'out of office', 'undeliverable', 'delivery status notification', 'delivery has failed'];

    public function __construct(
        private MicrosoftGraphMailService $graph,
        private VendorPortalLinkService $links,
    ) {}

    /**
     * Poll the mailbox once. In dry-run mode nothing is sent or recorded and
     * the cursor stays put, so a run can be repeated to see what it would do.
     *
     * @return array<int, array{from: string, subject: string, outcome: string}>
     */
    public function run(bool $dryRun = false): array
    {
        $mailbox = (string) config('services.microsoft.invoices_mailbox');
        $cachedCursor = Cache::get(self::CURSOR_KEY);
        $since = is_string($cachedCursor) ? Carbon::parse($cachedCursor) : now()->subHour();
        $maxReceived = $since->copy();
        $results = [];

        foreach ($this->graph->fetchInbox($since, $mailbox) as $message) {
            $graphMessageId = $message['id'] ?? null;
            $receivedAt = Carbon::parse($message['receivedDateTime'] ?? now());

            if (! is_string($graphMessageId) || $graphMessageId === '') {
                continue;
            }

            if (InvoiceEmailReply::query()->where('graph_message_id', $graphMessageId)->exists()) {
                $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;

                continue;
            }

            $from = $this->senderAddress($message);
            $subject = (string) ($message['subject'] ?? '');
            $vendor = $from === '' ? null : $this->vendorFor($from);
            $outcome = $this->decide($message, $from, $vendor);
            $results[] = ['from' => $from, 'subject' => $subject, 'outcome' => $outcome];

            if ($dryRun) {
                continue;
            }

            try {
                $replied = in_array($outcome, [self::OUTCOME_REPLIED_VENDOR, self::OUTCOME_REPLIED_GENERIC], true);

                if ($replied) {
                    $dashboardUrl = $vendor ? $this->links->dashboardLink($vendor) : null;
                    // A known vendor whose link could not be issued still gets
                    // the generic instructions rather than nothing.
                    $outcome = $dashboardUrl ? self::OUTCOME_REPLIED_VENDOR : self::OUTCOME_REPLIED_GENERIC;
                    $results[count($results) - 1]['outcome'] = $outcome;

                    $this->graph->reply($graphMessageId, $this->replyHtml($vendor, $dashboardUrl), $mailbox);
                }

                InvoiceEmailReply::query()->create([
                    'graph_message_id' => $graphMessageId,
                    'from_email' => $from,
                    'vendor_id' => $vendor?->id,
                    'subject' => Str::limit($subject, 250, ''),
                    'outcome' => $outcome,
                    'received_at' => $receivedAt,
                    'replied_at' => $replied ? now() : null,
                ]);
            } catch (Throwable $exception) {
                // Stop here so the cursor does not move past this message; the
                // next tick fetches it again. A permanently failing message is
                // visible in the log rather than silently skipped.
                Log::warning('invoices:auto-reply failed for a message', [
                    'id' => $graphMessageId,
                    'from' => $from,
                    'error' => $exception->getMessage(),
                ]);

                break;
            }

            $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;
        }

        if (! $dryRun) {
            Cache::put(self::CURSOR_KEY, $maxReceived->toIso8601ZuluString());
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function decide(array $message, string $from, ?Vendor $vendor): string
    {
        if ($from === '' || $this->isInternal($from)) {
            return self::OUTCOME_SKIPPED_INTERNAL;
        }

        if ($this->isAutomated($message, $from)) {
            return self::OUTCOME_SKIPPED_AUTOMATED;
        }

        $hasAttachments = (bool) ($message['hasAttachments'] ?? false);
        $mentionsInvoice = $this->mentionsInvoice($message);

        // A known vendor writing to the invoices mailbox needs only one signal;
        // a stranger needs both, so newsletters and cold pitches with a PDF
        // attached do not get a portal link.
        $looksLikeInvoice = $vendor
            ? ($hasAttachments || $mentionsInvoice)
            : ($hasAttachments && $mentionsInvoice);

        if (! $looksLikeInvoice) {
            return self::OUTCOME_SKIPPED_NOT_INVOICE;
        }

        if ($this->repliedRecently($from)) {
            return self::OUTCOME_SKIPPED_RECENT;
        }

        return $vendor ? self::OUTCOME_REPLIED_VENDOR : self::OUTCOME_REPLIED_GENERIC;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function senderAddress(array $message): string
    {
        return Str::lower(trim((string) ($message['from']['emailAddress']['address'] ?? '')));
    }

    /**
     * The one vendor this address belongs to. An address shared by several
     * vendor records is ambiguous, so it matches none of them: the sender is
     * still answered, but with the generic instructions rather than a
     * dashboard that might list another record's work orders.
     */
    private function vendorFor(string $from): ?Vendor
    {
        // PropertyWare pads some vendor emails with trailing spaces, and MySQL
        // and sqlite disagree on whether those count in "=", so trim both sides.
        $vendors = Vendor::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$from])
            ->limit(2)
            ->get();

        return $vendors->count() === 1 ? $vendors->first() : null;
    }

    private function isInternal(string $from): bool
    {
        [$local, $domain] = array_pad(explode('@', $from, 2), 2, '');

        return in_array($domain, self::INTERNAL_DOMAINS, true)
            || in_array($local, self::SYSTEM_SENDERS, true);
    }

    /**
     * Bounces, out-of-office notices and other machine mail, by the headers
     * mail systems set for exactly this purpose, with the subject as a backstop.
     *
     * @param  array<string, mixed>  $message
     */
    private function isAutomated(array $message, string $from): bool
    {
        foreach (($message['internetMessageHeaders'] ?? []) as $header) {
            $name = Str::lower((string) ($header['name'] ?? ''));
            $value = Str::lower(trim((string) ($header['value'] ?? '')));

            if ($name === 'auto-submitted' && $value !== '' && $value !== 'no') {
                return true;
            }

            if (in_array($name, ['x-auto-response-suppress', 'x-autoreply', 'x-autorespond'], true)) {
                return true;
            }

            if ($name === 'precedence' && in_array($value, ['bulk', 'auto_reply', 'junk', 'list'], true)) {
                return true;
            }
        }

        $subject = Str::lower((string) ($message['subject'] ?? ''));

        return Str::startsWith($subject, self::AUTOMATED_SUBJECTS);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function mentionsInvoice(array $message): bool
    {
        $subject = (string) ($message['subject'] ?? '');
        $body = strip_tags((string) ($message['body']['content'] ?? ''));

        return preg_match(self::INVOICE_WORDS, $subject.' '.Str::limit($body, 4000, '')) === 1;
    }

    private function repliedRecently(string $from): bool
    {
        return InvoiceEmailReply::query()
            ->where('from_email', $from)
            ->where('replied_at', '>=', now()->subDay())
            ->exists();
    }

    private function replyHtml(?Vendor $vendor, ?string $dashboardUrl): string
    {
        return view('emails.invoices-auto-reply', [
            'vendorName' => $vendor?->name,
            'dashboardUrl' => $dashboardUrl,
        ])->render();
    }
}
