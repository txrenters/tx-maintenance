<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The stored Jobber refresh token is dead (or missing), so no API call can
 * succeed until a human re-authorizes the app at /jobber-connect. Callers use
 * this to fail fast instead of hammering Jobber with doomed requests.
 */
class JobberReconnectRequiredException extends RuntimeException
{
    public function __construct(string $message = 'Jobber connection lost. Please reconnect to Jobber at /jobber-connect.')
    {
        parent::__construct($message);
    }
}
