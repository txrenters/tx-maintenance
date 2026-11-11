<?php

namespace App\Services\InspectionApp;

use Illuminate\Support\Facades\Http;

/**
 * The courier to the technicians' inspection app (the other developer's;
 * pages at inspection.texasrenters.com, the API at
 * inspection-api.texasrenters.com). Talks only to its third-party gateway,
 * with the API key the app issued us and the signature its gateway demands
 * on every write:
 *
 *   x-api-key    <key id>.<secret>
 *   x-timestamp  Unix seconds, within five minutes of the app's clock
 *   x-signature  hex HMAC-SHA256 over "METHOD\npath\ntimestamp\nsha256hex(body)"
 *                keyed with the secret half of the key
 *
 * The body is signed byte for byte, so it is encoded once here and sent as
 * is. No Bearer header: the app reads one as a session token and refuses
 * it. A redirect is never followed; the app's login page is a refusal.
 * Off whenever the URL or the key is blank.
 */
class InspectionAppClient
{
    private const TIMEOUT_SECONDS = 10;

    public function enabled(): bool
    {
        return $this->baseUrl() !== null && filled(config('services.inspection_app.token'));
    }

    public function baseUrl(): ?string
    {
        $url = rtrim(trim((string) config('services.inspection_app.url')), '/');

        return $url === '' ? null : $url;
    }

    public function handoffPath(): string
    {
        return '/'.ltrim(trim((string) config('services.inspection_app.handoff_path', '/api/v1/gateway/work-orders')), '/');
    }

    /**
     * POST one work order to the gateway. Returns the app's decoded answer on
     * a 2xx; throws InspectionAppRefusedException on any other status, and
     * lets a connection failure (unreachable, timed out) propagate so the
     * queue retries it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendWorkOrder(array $payload, string $requestId): array
    {
        $token = (string) config('services.inspection_app.token');
        $path = $this->handoffPath();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        $response = Http::withHeaders([
            'x-api-key' => $token,
            'x-timestamp' => $timestamp,
            'x-signature' => self::signature($token, 'POST', $path, $timestamp, $body),
            'x-request-id' => $requestId,
        ])
            ->withBody($body, 'application/json')
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->baseUrl().$path);

        if (! $response->successful()) {
            throw InspectionAppRefusedException::fromResponse($response);
        }

        $answer = $response->json();

        return is_array($answer) ? $answer : [];
    }

    public static function signature(string $token, string $method, string $path, string $timestamp, string $body): string
    {
        $secret = str_contains($token, '.') ? substr($token, strpos($token, '.') + 1) : $token;

        return hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".hash('sha256', $body), $secret);
    }
}
