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
     * Resolve many addresses in one request via the Census batch geocoder
     * (one CSV upload, up to 10k rows) — far friendlier to their firewall
     * than per-address calls.
     *
     * @param  array<int, array{street: string, city: string|null, state: string, zip: string|null}>  $rows  keyed by caller id
     * @return array<int, array{found: bool, lat: float|null, lng: float|null, matched: string|null}>|null
     *                                                                                                     null = the request itself failed (worth retrying later). A row
     *                                                                                                     missing from the returned array also counts as unresolved.
     */
    public function geocodeBatch(array $rows): ?array
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $id => $parts) {
            // PropertyWare sometimes holds ZIP+4; the Census API wants 5 digits.
            $zip5 = substr(trim((string) ($parts['zip'] ?? '')), 0, 5);
            fputcsv($handle, [
                $id,
                $parts['street'],
                $parts['city'] ?? '',
                $parts['state'] ?? '',
                strlen($zip5) === 5 ? $zip5 : '',
            ], ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $response = $this->request()
            ->timeout(300)
            ->attach('addressFile', $csv, 'addresses.csv')
            ->post(config('services.census.geocoder_url').'/locations/addressbatch', [
                'benchmark' => (string) config('services.census.benchmark'),
            ]);

        if (! $response->successful()) {
            Log::error('Census batch geocode request failed', [
                'rows' => count($rows),
                'status_code' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $body = $response->body();

        // The Census WAF answers rate-limited requests with an HTML block
        // page and HTTP 200. Anything that is not the documented CSV shape
        // is a failure to retry later, never a definitive no-match.
        if (str_starts_with(ltrim($body), '<')) {
            Log::error('Census batch geocode returned a non-CSV response (rate limited?)', [
                'rows' => count($rows),
                'body' => substr($body, 0, 200),
            ]);

            return null;
        }

        $results = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            // Response columns: id, input address, Match|No_Match|Tie,
            // Exact|Non_Exact, matched address, "lng,lat", tigerline, side.
            $fields = str_getcsv($line, ',', '"', '');
            $id = $fields[0] ?? null;
            if ($id === null || ! array_key_exists((int) $id, $rows)) {
                continue;
            }
            $status = $fields[2] ?? '';
            if ($status === 'Match' && isset($fields[5]) && str_contains($fields[5], ',')) {
                // Census returns GIS-style "longitude,latitude".
                [$lng, $lat] = array_map('floatval', explode(',', $fields[5], 2));
                $results[(int) $id] = ['found' => true, 'lat' => $lat, 'lng' => $lng, 'matched' => $fields[4] ?? null];
            } elseif (in_array($status, ['No_Match', 'Tie'], true)) {
                $results[(int) $id] = ['found' => false, 'lat' => null, 'lng' => null, 'matched' => null];
            }
        }

        return $results;
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
