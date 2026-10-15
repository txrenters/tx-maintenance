<?php

namespace App\Console\Commands;

use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Models\TwilioPhoneNumber;
use App\Services\AutomatedMessageLogService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Who was told about an upcoming Tenant Benefit Package visit, and who wrote
 * back. Read-only: a report for the office when scheduled visits are pulled
 * after the tenants were already reminded.
 *
 * The source is the automated-message ledger (activity_log), not the
 * jobber_visits notified flags: unscheduling a visit in Jobber deletes our
 * visit row, flags included, while the ledger row and the text itself stay.
 * The manual "Send notification" texts are read from jobber_text_messages
 * by their template opening.
 */
class ListTbpRemindedVisits extends Command
{
    protected const TIMEZONE = 'America/Chicago';

    /**
     * The 14-day tier is the earliest reminder; a couple of days of slack
     * covers a late run.
     */
    protected const LEDGER_LOOKBACK_DAYS = 16;

    protected const REMINDER_EVENTS = ['tenant_job_reminder_sms', 'tenant_job_reminder_email'];

    /**
     * Ledger property naming the jobber_text_messages row a reminder was
     * sent as (SendJobReminders passes it in $extra).
     */
    protected const MESSAGE_ID_PROPERTY = 'jobber_text_message_id';

    protected const DATE_PATTERN = '/(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+([A-Z][a-z]+\s+\d{1,2},\s+\d{4})/';

    protected $signature = 'tbp:reminded-visits
                            {--from= : First visit date to include (Y-m-d, America/Chicago); defaults to today}
                            {--to= : Last visit date to include (Y-m-d)}
                            {--replies-only : Only tenants who texted back after the reminder}
                            {--csv= : Write the rows as CSV to this path (storage/app relative), or - for standard output}';

    protected $description = 'List the tenants that received a TBP visit reminder for upcoming visits, with any reply';

    public function handle(): int
    {
        $from = $this->parseDate($this->option('from'), Carbon::now(self::TIMEZONE)->startOfDay());
        $to = $this->parseDate($this->option('to'), null);

        if ($from === false || $to === false) {
            $this->error('Dates must use the Y-m-d format, for example 2026-10-15.');

            return self::FAILURE;
        }

        $scanFrom = $from->copy()->subDays(self::LEDGER_LOOKBACK_DAYS);
        $ourNumbers = $this->ourNumbers();

        $sends = $this->ledgerSends($scanFrom, $ourNumbers)
            ->merge($this->manualNotices($scanFrom, $ourNumbers))
            ->filter(function (array $send) use ($from, $to): bool {
                if ($send['visit_date'] === null) {
                    return true;
                }

                return $send['visit_date']->gte($from) && ($to === null || $send['visit_date']->lte($to));
            });

        $rows = $this->groupSends($sends);

        if ($this->option('replies-only')) {
            $rows = $rows->filter(fn (array $row): bool => $row['replied'] === 'yes');
        }

        $rows = $rows->sortBy([
            fn (array $a, array $b): int => strcmp($a['visit_date'], $b['visit_date']),
            fn (array $a, array $b): int => strcmp($a['job_number'], $b['job_number']),
            fn (array $a, array $b): int => strcmp($a['recipient'], $b['recipient']),
        ])->values();

        $csvPath = $this->option('csv');

        if ($csvPath !== null && $csvPath !== '') {
            return $this->writeCsv($rows, (string) $csvPath);
        }

        $this->info(sprintf(
            'TBP reminders for visits from %s%s',
            $from->toDateString(),
            $to !== null ? ' to '.$to->toDateString() : ' onward',
        ));

        if ($rows->isEmpty()) {
            $this->warn('No reminded tenants found in that window.');

            return self::SUCCESS;
        }

        $this->table(array_values($this->columns()), $rows->map(fn (array $row): array => array_map(
            fn (string $key): string => (string) $row[$key],
            array_keys($this->columns()),
        ))->all());

        $this->line(sprintf(
            '%d tenant/visit rows across %d jobs; %d replied.',
            $rows->count(),
            $rows->pluck('job_id')->unique()->count(),
            $rows->where('replied', 'yes')->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return [
            'job_number' => 'Job #',
            'job_title' => 'Job title',
            'client' => 'Jobber client',
            'property' => 'Property',
            'visit_date' => 'Visit date',
            'tiers' => 'Reminders',
            'channel' => 'Channel',
            'recipient' => 'Recipient',
            'last_sent_at' => 'Last reminder',
            'visit_now' => 'Visit now',
            'replied' => 'Replied',
            'reply_at' => 'Reply at',
            'reply' => 'Reply',
        ];
    }

    protected function parseDate(mixed $value, ?Carbon $default): Carbon|false|null
    {
        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $value, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Numbers texts go out from: the Twilio roster and the configured THMP and
     * maintenance numbers. ledgerSends() adds whatever number the logged
     * reminders were actually sent from.
     *
     * @return array<int, string> last-ten-digit keys
     */
    protected function ourNumbers(): array
    {
        $numbers = collect([
            config('services.twilio.from'),
            config('services.twilio.maintenance_from'),
        ]);

        if (Schema::hasTable('twilio_phone_numbers')) {
            $numbers = $numbers->merge(TwilioPhoneNumber::query()->pluck('phone_number'));
        }

        return $numbers
            ->map(fn ($number): string => $this->digitsKey((string) $number))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One entry per ledger-logged reminder send.
     *
     * @param  array<int, string>  $ourNumbers
     * @return Collection<int, array<string, mixed>>
     */
    protected function ledgerSends(Carbon $scanFrom, array &$ourNumbers): Collection
    {
        $activities = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->whereIn('event', self::REMINDER_EVENTS)
            ->where('created_at', '>=', $scanFrom)
            ->orderBy('id')
            ->get();

        $messageIds = $activities
            ->map(fn (Activity $activity): int => (int) ($activity->properties[self::MESSAGE_ID_PROPERTY] ?? 0))
            ->filter()
            ->unique()
            ->values();

        $messages = $messageIds->isEmpty()
            ? collect()
            : JobberTextMessage::query()->whereIn('id', $messageIds)->get();

        foreach ($messages as $message) {
            $key = $this->digitsKey((string) $message->sender_number);

            if ($key !== '' && ! in_array($key, $ourNumbers, true)) {
                $ourNumbers[] = $key;
            }
        }

        return $activities->toBase()->map(function (Activity $activity): array {
            $properties = $activity->properties;
            $channel = (string) ($properties['channel'] ?? ($activity->event === 'tenant_job_reminder_email' ? 'email' : 'sms'));
            $text = (string) ($properties['message'] ?? $properties['subject'] ?? '');

            return [
                'source' => 'ledger',
                'job_id' => (int) ($properties['jobber_id'] ?? $activity->subject_id ?? 0),
                'job_number' => (string) ($properties['job_number'] ?? ''),
                'visit_id' => isset($properties['jobber_visit_id']) ? (int) $properties['jobber_visit_id'] : null,
                'visit_date' => $this->dateFromText($text),
                'channel' => $channel,
                'recipient' => (string) ($properties['recipient'] ?? ''),
                'sent_at' => $activity->created_at->copy(),
                'tier' => null,
            ];
        });
    }

    /**
     * Texts sent by hand from the Send notification button: the template
     * opening on an outbound row that no ledger entry claims.
     *
     * @param  array<int, string>  $ourNumbers
     * @return Collection<int, array<string, mixed>>
     */
    protected function manualNotices(Carbon $scanFrom, array $ourNumbers): Collection
    {
        if ($ourNumbers === []) {
            return collect();
        }

        $loggedIds = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('created_at', '>=', $scanFrom)
            ->get(['id', 'properties'])
            ->map(fn (Activity $activity): int => (int) ($activity->properties[self::MESSAGE_ID_PROPERTY] ?? 0))
            ->filter()
            ->values()
            ->all();

        $hasVisitColumn = JobberTextMessage::hasVisitColumn();

        return JobberTextMessage::query()
            ->where('created_at', '>=', $scanFrom)
            ->where('messages', 'like', 'Good day%')
            ->where('messages', 'like', '%Tenant Benefit Package%')
            ->when($loggedIds !== [], fn ($query) => $query->whereNotIn('id', $loggedIds))
            ->orderBy('id')
            ->get()
            ->toBase()
            ->filter(fn (JobberTextMessage $message): bool => in_array($this->digitsKey((string) $message->sender_number), $ourNumbers, true))
            ->map(fn (JobberTextMessage $message): array => [
                'source' => 'manual',
                'job_id' => (int) $message->jobber_id,
                'job_number' => '',
                'visit_id' => $hasVisitColumn && $message->jobber_visit_id !== null ? (int) $message->jobber_visit_id : null,
                'visit_date' => $this->dateFromText((string) $message->messages),
                'channel' => 'sms',
                'recipient' => (string) $message->receiver_number,
                'sent_at' => $message->created_at->copy(),
                'tier' => 'manual',
            ])
            ->values();
    }

    protected function dateFromText(string $text): ?Carbon
    {
        if (! preg_match(self::DATE_PATTERN, $text, $matches)) {
            return null;
        }

        try {
            return Carbon::parse($matches[1], self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One row per tenant per reminded visit date, with the reply (if any)
     * that arrived after the first reminder.
     *
     * @param  Collection<int, array<string, mixed>>  $sends
     * @return Collection<int, array<string, string|int>>
     */
    protected function groupSends(Collection $sends): Collection
    {
        if ($sends->isEmpty()) {
            return collect();
        }

        $groups = $sends->groupBy(fn (array $send): string => implode('|', [
            $send['job_id'],
            $send['visit_date']?->toDateString() ?? '?',
            $send['channel'] === 'email' ? strtolower($send['recipient']) : $this->digitsKey($send['recipient']),
        ]));

        $jobs = Jobber::query()
            ->with(['client', 'property'])
            ->whereIn('id', $sends->pluck('job_id')->unique()->filter()->values())
            ->get()
            ->keyBy('id');

        $visits = JobberVisit::query()
            ->whereIn('id', $sends->pluck('visit_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $messagesByJob = JobberTextMessage::query()
            ->whereIn('jobber_id', $jobs->keys())
            ->orderBy('id')
            ->get()
            ->groupBy('jobber_id');

        return $groups->map(function (Collection $group) use ($jobs, $visits, $messagesByJob): array {
            $first = $group->first();
            $job = $jobs->get($first['job_id']);
            $visitDate = $first['visit_date'];

            $firstSentAt = $group->min('sent_at');
            $lastSentAt = $group->max('sent_at');

            $tiers = $group->map(function (array $send) use ($visitDate): string {
                if ($send['tier'] !== null) {
                    return (string) $send['tier'];
                }

                if ($visitDate === null) {
                    return '?';
                }

                return (string) (int) $send['sent_at']->copy()->timezone(self::TIMEZONE)->startOfDay()->diffInDays($visitDate, false);
            })->unique()->sort(function (string $a, string $b): int {
                if (is_numeric($a) && is_numeric($b)) {
                    return (int) $b <=> (int) $a;
                }

                return is_numeric($a) ? -1 : (is_numeric($b) ? 1 : strcmp($a, $b));
            })->values()->implode(',');

            $reply = null;

            if ($first['channel'] === 'sms') {
                $recipientKey = $this->digitsKey($first['recipient']);

                $reply = $messagesByJob->get($first['job_id'], collect())
                    ->filter(fn (JobberTextMessage $message): bool => $this->digitsKey((string) $message->sender_number) === $recipientKey
                        && $message->created_at !== null
                        && $message->created_at->gt($firstSentAt))
                    ->last();
            }

            return [
                'job_id' => (int) $first['job_id'],
                'job_number' => (string) ($job?->job_number ?? $group->pluck('job_number')->filter()->first() ?? ''),
                'job_title' => (string) ($job?->title ?? ''),
                'client' => (string) ($job?->client?->name ?? ''),
                'property' => (string) ($job?->property?->full_address ?? ''),
                'visit_date' => $visitDate?->toDateString() ?? 'unknown',
                'tiers' => $tiers,
                'channel' => $group->pluck('channel')->unique()->sort()->implode(','),
                'recipient' => (string) $first['recipient'],
                'last_sent_at' => $lastSentAt->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i'),
                'visit_now' => $this->describeVisitNow($group, $visits, $visitDate),
                'replied' => $reply !== null ? 'yes' : ($first['channel'] === 'email' ? 'n/a (email)' : 'no'),
                'reply_at' => $reply?->created_at?->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i') ?? '',
                'reply' => $reply !== null ? Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $reply->messages)), 120) : '',
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $group
     * @param  Collection<int, JobberVisit>  $visits
     */
    protected function describeVisitNow(Collection $group, Collection $visits, ?Carbon $visitDate): string
    {
        $visitIds = $group->pluck('visit_id')->filter()->unique();

        if ($visitIds->isEmpty()) {
            return 'unknown (no visit id on the reminder)';
        }

        $existing = $visitIds->map(fn (int $id) => $visits->get($id))->filter()->first();

        if ($existing === null || $existing->start_at === null) {
            return 'unscheduled';
        }

        $currentDate = $existing->start_at->copy()->toDateString();

        if ($visitDate !== null && $currentDate === $visitDate->toDateString()) {
            return 'scheduled';
        }

        return 'moved to '.$currentDate;
    }

    /**
     * @param  Collection<int, array<string, string|int>>  $rows
     */
    protected function writeCsv(Collection $rows, string $path): int
    {
        $columns = $this->columns();

        if ($path === '-') {
            $handle = fopen('php://temp', 'r+');
        } else {
            if (! Str::startsWith($path, ['/', '\\']) && ! preg_match('/^[A-Za-z]:/', $path)) {
                $path = storage_path('app/'.$path);
            }

            $handle = fopen($path, 'w');
        }

        if ($handle === false) {
            $this->error('Could not open '.$path.' for writing.');

            return self::FAILURE;
        }

        fputcsv($handle, array_values($columns));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $key): string => (string) $row[$key], array_keys($columns)));
        }

        if ($path === '-') {
            rewind($handle);
            $this->output->write((string) stream_get_contents($handle));
            fclose($handle);

            return self::SUCCESS;
        }

        fclose($handle);
        $this->info(sprintf('%d rows written to %s', $rows->count(), $path));

        return self::SUCCESS;
    }

    protected function digitsKey(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
