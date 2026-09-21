<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Jobber's rate limiter, in one place.
 *
 * Jobber prices every query up front, answers each call with its cost
 * accounting (points charged, points left, refill per second), and reports a
 * rate limit as HTTP 200 with a THROTTLED error code rather than a 429. This
 * waits those out, sizes the wait from Jobber's own numbers, and paces the
 * next call so a caller paging through results does not bounce off the
 * limiter to learn what it could have read from the last response.
 *
 * Extracted from ImportJobberJobs, which owned all of this privately, so the
 * note sync gets the same behaviour instead of a second copy that drifts.
 * The command keeps the page-shrinking on top: a query priced over the
 * ceiling is never retried here, because no wait can help it — the caller
 * asks for less instead, which lastRequestOverCeiling() is the cue for.
 */
class JobberGraphqlClient
{
    /**
     * Longest single pause while waiting for Jobber's rate-limit bucket to
     * refill; anything longer means something other than the limiter.
     */
    private const MAX_WAIT_SECONDS = 60;

    private const THROTTLE_ATTEMPTS = 5;

    private const THROTTLE_BACKOFF_SECONDS = 5;

    /**
     * Set when the last request was refused for costing more than the
     * ceiling — the cue to shrink the page rather than wait.
     */
    private bool $lastRequestOverCeiling = false;

    /**
     * Called with the seconds waited and the attempt number each time a
     * throttled request is retried. A console command hands this its own
     * warn() so an operator watching a long import still sees the pauses;
     * the service itself has no console to write to.
     *
     * @var (callable(int, int): void)|null
     */
    private $onThrottleWait = null;

    public function __construct(private JobberTokenService $tokens) {}

    /**
     * Report each throttle wait to the caller, for commands that show it.
     *
     * @param  (callable(int, int): void)|null  $callback  seconds, attempt
     */
    public function reportThrottleWaits(?callable $callback): void
    {
        $this->onThrottleWait = $callback;
    }

    /**
     * POST a GraphQL body, waiting and retrying while Jobber reports the
     * request as throttled. Null on a failed request, on a query priced over
     * the ceiling, or once the retries are spent.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    public function post(array $body): ?array
    {
        $this->lastRequestOverCeiling = false;

        for ($attempt = 1; $attempt <= self::THROTTLE_ATTEMPTS; $attempt++) {
            $response = $this->tokens->graphql($body);

            if ($response->failed()) {
                Log::error('Failed to fetch jobs:', ['response' => $response->body()]);

                return null;
            }

            $json = $response->json();

            if (! self::isThrottled($json)) {
                $this->paceForNextRequest($json);

                return $json;
            }

            $cost = $json['extensions']['cost'] ?? [];
            $requested = $cost['requestedQueryCost'] ?? null;
            $ceiling = $cost['throttleStatus']['maximumAvailable'] ?? null;

            if (is_numeric($requested) && is_numeric($ceiling) && $requested > $ceiling) {
                $this->lastRequestOverCeiling = true;
                Log::error('Jobber query costs more than the rate-limit ceiling', ['cost' => $cost]);

                return null;
            }

            $wait = $this->secondsUntilAffordable($cost, $requested) ?? self::THROTTLE_BACKOFF_SECONDS * $attempt;

            if ($this->onThrottleWait !== null) {
                ($this->onThrottleWait)($wait, $attempt);
            }

            Log::warning('Jobber throttled the jobs query', [
                'attempt' => $attempt,
                'wait_seconds' => $wait,
                'message' => $json['errors'][0]['message'] ?? null,
                'cost' => $cost,
            ]);

            sleep($wait);
        }

        Log::error('Gave up on the Jobber jobs query after repeated throttling');

        return null;
    }

    /**
     * Whether the last post() was refused for costing more than the ceiling,
     * rather than for any other reason a null can mean.
     */
    public function lastRequestOverCeiling(): bool
    {
        return $this->lastRequestOverCeiling;
    }

    /**
     * Jobber reports a rate limit as HTTP 200 with a THROTTLED error code.
     *
     * @param  array<string, mixed>|null  $json
     */
    public static function isThrottled(?array $json): bool
    {
        foreach ($json['errors'] ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    /**
     * After a successful page, pause just long enough that the next page of
     * the same cost fits the bucket, instead of bouncing off the limiter
     * and spending a retry to learn that.
     *
     * @param  array<string, mixed>  $json
     */
    private function paceForNextRequest(array $json): void
    {
        $cost = $json['extensions']['cost'] ?? null;

        if (! is_array($cost)) {
            return;
        }

        // Jobber admits a query on its requested (reserved) cost, not the
        // smaller amount it ends up charging — measured on prod: 6,555
        // requested, 3,094 charged — so the next page must fit the former.
        $wait = $this->secondsUntilAffordable($cost, $cost['requestedQueryCost'] ?? $cost['actualQueryCost'] ?? null);

        if ($wait !== null && $wait > 0) {
            sleep($wait);
        }
    }

    /**
     * Seconds until the bucket holds enough points for a query of the given
     * cost, from Jobber's own accounting — null when the response did not
     * carry the numbers. Zero when it already fits.
     *
     * @param  array<string, mixed>  $cost
     */
    private function secondsUntilAffordable(array $cost, mixed $needed): ?int
    {
        $available = $cost['throttleStatus']['currentlyAvailable'] ?? null;
        $restoreRate = $cost['throttleStatus']['restoreRate'] ?? null;

        if (! is_numeric($needed) || ! is_numeric($available) || ! is_numeric($restoreRate) || $restoreRate <= 0) {
            return null;
        }

        if ($available >= $needed) {
            return 0;
        }

        return (int) min(self::MAX_WAIT_SECONDS, max(1, ceil(($needed - $available) / $restoreRate) + 1));
    }
}
