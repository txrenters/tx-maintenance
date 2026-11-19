<?php

namespace App\Services\InspectionApp;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * The inspection app answered, and the answer was no. Carries the app's own
 * stable error code, its message and its request id (what to quote when
 * asking the other developer), and says whether trying again later could
 * change anything: a 5xx, a 429 or a timeout can; a 4xx cannot.
 */
class InspectionAppRefusedException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        public readonly ?string $appMessage,
        public readonly ?string $requestId,
    ) {
        parent::__construct(sprintf(
            'The inspection app refused the work order (HTTP %d%s)%s%s',
            $status,
            $errorCode ? ' '.$errorCode : '',
            $appMessage ? ': '.$appMessage : '',
            $requestId ? ' [request '.$requestId.']' : '',
        ));
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        return new self(
            $response->status(),
            isset($body['code']) ? (string) $body['code'] : null,
            isset($body['message']) ? (string) $body['message'] : (trim($response->body()) !== '' && ! $body ? mb_substr(trim($response->body()), 0, 200) : null),
            isset($body['requestId']) ? (string) $body['requestId'] : ($response->header('x-request-id') ?: null),
        );
    }

    public function retryable(): bool
    {
        return $this->status >= 500 || in_array($this->status, [408, 425, 429], true);
    }
}
