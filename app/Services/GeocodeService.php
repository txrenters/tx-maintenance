<?php

namespace App\Services;

use App\Models\Building;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeocodeService
{
    /**
     * Resolve a street address to coordinates via the free US Census
     * geocoder. A null return means the request itself failed (worth
     * retrying later); found=false means the API answered and had no match
     * for the address.
     *
     * @return array{found: bool, lat: float|null, lng: float|null, matched: string|null}|null
     */
    public function geocode(string $street, ?string $city, ?string $state, ?string $zip): ?array
    {
        $query = [
            'street' => $street,
            'benchmark' => (string) config('services.census.benchmark'),
            'format' => 'json',
        ];

        if ($city !== null && trim($city) !== '') {
            $query['city'] = trim($city);
        }

        if ($state !== null && trim($state) !== '') {
            $query['state'] = trim($state);
        }

        // PropertyWare sometimes holds ZIP+4; the Census API wants 5 digits.
        $zip5 = substr(trim((string) $zip), 0, 5);
        if (strlen($zip5) === 5) {
            $query['zip'] = $zip5;
        }

        $response = $this->request()->get(
            config('services.census.geocoder_url').'/locations/address',
            $query
        );

        if (! $response->successful()) {
            Log::error('Census geocode request failed', [
                'street' => $street,
                'status_code' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $json = $response->json();

        // The Census WAF answers rate-limited requests with an HTML block
        // page and HTTP 200. Anything that is not the documented JSON shape
        // is a failure to retry later, never a definitive no-match.
        if (! is_array($json) || ! array_key_exists('result', $json)) {
            Log::error('Census geocode returned a non-JSON response (rate limited?)', [
                'street' => $street,
                'body' => substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $match = $json['result']['addressMatches'][0] ?? null;

        if (! is_array($match)) {
            return ['found' => false, 'lat' => null, 'lng' => null, 'matched' => null];
        }

        // Census returns GIS-style coordinates: x is longitude, y is latitude.
        return [
            'found' => true,
            'lat' => (float) $match['coordinates']['y'],
            'lng' => (float) $match['coordinates']['x'],
            'matched' => $match['matchedAddress'] ?? null,
        ];
    }

    /**
     * Structured address parts for a building, handling both shapes in the
     * data: the common street-only address with separate city/state/zip
     * columns, and the legacy one-line "street, City, ST 77001" stored
     * entirely in the address column with the other fields blank. Returns
     * null for buildings with no street address or a PO Box (not a physical
     * service location).
     *
     * address_cont is deliberately excluded: in PropertyWare it holds
     * unit/apt noise that degrades Census matching without moving the
     * coordinate.
     *
     * @return array{street: string, city: string|null, state: string, zip: string|null}|null
     */
    public static function addressParts(Building $building): ?array
    {
        $street = trim((string) $building->address);

        if ($street === '' || preg_match('/^\s*p\.?\s*o\.?\s*box/i', $street)) {
            return null;
        }

        $city = trim((string) $building->city);

        if ($city !== '') {
            return [
                'street' => $street,
                'city' => $city,
                'state' => trim((string) $building->state_region) ?: 'TX',
                'zip' => trim((string) $building->postal_code) ?: null,
            ];
        }

        $pieces = array_map('trim', explode(',', $street));
        $last = $pieces[count($pieces) - 1];

        if (count($pieces) >= 3 && preg_match('/^([A-Za-z]{2})(?:\s+(\d{5}))?$/', $last, $matches)) {
            return [
                'street' => implode(', ', array_slice($pieces, 0, count($pieces) - 2)),
                'city' => $pieces[count($pieces) - 2],
                'state' => strtoupper($matches[1]),
                'zip' => $matches[2] ?? null,
            ];
        }

        return [
            'street' => $street,
            'city' => null,
            'state' => 'TX',
            'zip' => trim((string) $building->postal_code) ?: null,
        ];
    }

    /**
     * The exact input string a building is geocoded under, doubling as the
     * staleness key: when a building's address fields change, the assembled
     * string no longer matches the stored geocoded_address and the nightly
     * command re-geocodes it.
     */
    public static function assembleAddress(Building $building): ?string
    {
        $parts = self::addressParts($building);

        if ($parts === null) {
            return null;
        }

        $zip5 = substr((string) $parts['zip'], 0, 5);

        return implode(', ', array_filter([
            $parts['street'],
            $parts['city'],
            trim($parts['state'].' '.$zip5),
        ]));
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'TXWorkOrderMaintenance/1.0 (property coverage map)',
        ])->retry(3, 300, function (Throwable $exception): bool {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && in_array($exception->response->status(), [500, 502, 503, 504], true));
        }, throw: false);
    }
}
