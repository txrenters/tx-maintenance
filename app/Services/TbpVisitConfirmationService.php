<?php

namespace App\Services;

use App\Ai\Agents\TbpVisitConfirmationJudgeAgent;
use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Whether a tenant confirmed their TBP visit, read by the AI from the
 * tenant's own text replies on the visit's Jobber job. Decided per tenant
 * phone number, so a roommate who never replied still gets the notice
 * asking them to confirm (Earl, 2026-10-23).
 *
 * No reply, the judge switched off, no AI provider, an AI error or a verdict
 * below the confidence bar all mean "not confirmed": the tenant gets the
 * full notice again, exactly as before this existed.
 */
class TbpVisitConfirmationService
{
    /** Replies older than this many days before the visit belong to an earlier visit. */
    public const LOOKBACK_DAYS = 15;

    /** The newest messages of the thread the AI reads. */
    private const THREAD_LIMIT = 20;

    public function enabled(): bool
    {
        return (bool) config('services.ai.tbp_confirmation_judge') && AiSettings::ready();
    }

    /**
     * @return array{confirmed: bool, reason: string}
     */
    public function judge(JobberVisit $visit, string $phone): array
    {
        if (! $this->enabled()) {
            return ['confirmed' => false, 'reason' => 'judge_off'];
        }

        $digits = $this->lastTenDigits($phone);

        if ($digits === '') {
            return ['confirmed' => false, 'reason' => 'no_number'];
        }

        $thread = $this->thread($visit, $digits);
        $replies = $thread->filter(fn (JobberTextMessage $message): bool => $this->lastTenDigits((string) $message->sender_number) === $digits);

        if ($replies->isEmpty()) {
            return ['confirmed' => false, 'reason' => 'no_reply'];
        }

        try {
            $response = (new TbpVisitConfirmationJudgeAgent)->prompt($this->prompt($visit, $thread, $digits), timeout: 30);
        } catch (\Throwable $exception) {
            Log::warning('TBP visit confirmation AI judge failed; treating as not confirmed.', [
                'jobber_visit_id' => $visit->id,
                'error' => $exception->getMessage(),
            ]);

            return ['confirmed' => false, 'reason' => 'ai_unavailable'];
        }

        $confirmed = (bool) data_get($response, 'confirmed', false);
        $confidence = max(0, min(100, (int) data_get($response, 'confidence', 0)));
        $why = Str::limit(trim((string) data_get($response, 'reason', '')), 200);
        $minimum = (int) config('services.ai.tbp_confirmation_min_confidence', 75);

        if (! $confirmed) {
            return ['confirmed' => false, 'reason' => "ai_not_confirmed ({$confidence}%): ".$why];
        }

        if ($confidence < $minimum) {
            return ['confirmed' => false, 'reason' => "ai_unsure ({$confidence}%): ".$why];
        }

        return ['confirmed' => true, 'reason' => "ai_confirmed ({$confidence}%): ".$why];
    }

    /**
     * The texts between us and this number on the visit's Jobber job since
     * the 14-day notice window opened, oldest first.
     *
     * @return Collection<int, JobberTextMessage>
     */
    private function thread(JobberVisit $visit, string $digits): Collection
    {
        $since = Carbon::parse($visit->start_at)->subDays(self::LOOKBACK_DAYS);

        return JobberTextMessage::query()
            ->where('jobber_id', $visit->jobber_job_id)
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($digits): void {
                $query->where('sender_number', 'like', '%'.$digits)
                    ->orWhere('receiver_number', 'like', '%'.$digits);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::THREAD_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * @param  Collection<int, JobberTextMessage>  $thread
     */
    private function prompt(JobberVisit $visit, Collection $thread, string $digits): string
    {
        $lines = $thread->map(function (JobberTextMessage $message) use ($digits): string {
            $who = $this->lastTenDigits((string) $message->sender_number) === $digits ? 'them' : 'us';
            $when = Carbon::parse($message->created_at)->timezone('America/Chicago')->format('M j g:ia');

            return "[{$when}] {$who}: ".Str::limit(trim((string) $message->messages), 600);
        });

        return implode("\n", [
            'Visit date: '.Carbon::parse($visit->start_at)->timezone('America/Chicago')->format('l, F j, Y'),
            '',
            'Thread:',
            ...$lines->all(),
        ]);
    }

    private function lastTenDigits(string $number): string
    {
        return substr((string) preg_replace('/\D/', '', $number), -10);
    }
}
