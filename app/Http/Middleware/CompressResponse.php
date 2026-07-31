<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip text responses when the client supports it. The production nginx does
 * not compress anything, so multi-hundred-KB Inertia board payloads ship raw;
 * compressing in PHP keeps the fix inside the codebase instead of requiring a
 * server config change. If nginx compression is ever enabled it will skip
 * responses that already carry a Content-Encoding header, so the two cannot
 * double-compress.
 */
class CompressResponse
{
    /** Bodies smaller than this are not worth the CPU spent compressing. */
    private const MIN_BYTES = 1024;

    private const COMPRESSIBLE_TYPES = [
        'text/html',
        'application/json',
        'text/plain',
        'text/css',
        'application/javascript',
        'text/javascript',
        'image/svg+xml',
        'application/xml',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! str_contains((string) $request->header('Accept-Encoding'), 'gzip')) {
            return $response;
        }

        // Streamed and file responses have no in-memory body to swap out.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        if (! $this->isCompressibleType((string) $response->headers->get('Content-Type'))) {
            return $response;
        }

        $compressed = gzencode($content, 5);

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->set('Vary', 'Accept-Encoding', false);

        return $response;
    }

    private function isCompressibleType(string $contentType): bool
    {
        foreach (self::COMPRESSIBLE_TYPES as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }

        return false;
    }
}
