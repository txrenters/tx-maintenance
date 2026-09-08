<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * PropertyWare rejected the API credentials for a resource (HTTP 401/403).
 * The key is valid but its scope does not cover what was asked for, so no
 * amount of retrying or paging will succeed until someone widens the key's
 * permissions in PropertyWare. Callers use this to fail fast instead of
 * walking every offset into the same denial.
 */
class PropertyWareAccessDeniedException extends RuntimeException
{
    public function __construct(string $resource, string $detail = '')
    {
        parent::__construct(rtrim(sprintf(
            'PropertyWare denied access to %s. The API key does not have permission for this resource. %s',
            $resource,
            $detail,
        )));
    }
}
