<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lease status straight from PropertyWare's saved-report JSON export.
 *
 * The REST API refuses /leases for our key (403, "Building and Maintenance"),
 * so leases come from the report feed instead. It carries no building id, only
 * the property's address as text, which is why every row has to be matched to
 * a building by normalized address.
 *
 * The same feed already drives SendJobReminders; the address normalizing here
 * mirrors that command so both agree on what counts as the same property.
 */
class PropertyWareLeaseReport
{
    /**
     * Positions in the report's flat record array. The export has no field
     * names, so a column moving in PropertyWare would silently change meaning
     * here -- sync:leases reports its match rate partly to make that visible.
     */
    private const COLUMN_STATUS = 2;

    private const COLUMN_TENANT_NAME = 3;

    private const COLUMN_ADDRESS = 4;

    private const FEED_URL = 'https://app.propertyware.com/pw/00a/4377411585/JSON?1ADhXAA&shardKey=182255624';

    /**
     * Every lease row in the report, or null when the feed could not be read.
     *
     * @return array<int, array{status: string, tenant_name: string, address: string, address_key: string}>|null
     */
    public function fetch(): ?array
    {
        try {
            $response = Http::timeout(120)->get(self::FEED_URL);

            if ($response->failed()) {
                Log::error('Failed to fetch the PropertyWare lease report', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $records = json_decode($response->body(), true, 512, JSON_INVALID_UTF8_IGNORE)['records'] ?? [];

            if (! is_array($records)) {
                return null;
            }

            return collect($records)
                ->map(fn ($record): ?array => $this->mapRecord((array) $record))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::error('PropertyWare lease report fetch failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $record
     * @return array{status: string, tenant_name: string, address: string, address_key: string}|null
     */
    private function mapRecord(array $record): ?array
    {
        $address = trim((string) ($record[self::COLUMN_ADDRESS] ?? ''));
        $status = trim((string) ($record[self::COLUMN_STATUS] ?? ''));

        // A row with no address cannot be tied to a property, and a row with no
        // status has nothing to show.
        if ($address === '' || $status === '') {
            return null;
        }

        return [
            'status' => $status,
            'tenant_name' => trim((string) ($record[self::COLUMN_TENANT_NAME] ?? '')),
            'address' => $address,
            'address_key' => $this->addressKey($address),
        ];
    }

    /**
     * The comparable form of an address: lowercased, punctuation dropped and
     * street suffixes collapsed to one spelling, so "6341 Del Monte Dr."
     * and "6341 DEL MONTE DRIVE" resolve to the same property.
     */
    public function addressKey(string $value): string
    {
        $streetSuffixes = [
            'avenue' => 'ave', 'ave' => 'ave',
            'boulevard' => 'blvd', 'blvd' => 'blvd',
            'circle' => 'cir', 'cir' => 'cir',
            'court' => 'ct', 'ct' => 'ct',
            'drive' => 'dr', 'dr' => 'dr',
            'highway' => 'hwy', 'hwy' => 'hwy',
            'lane' => 'ln', 'ln' => 'ln',
            'parkway' => 'pkwy', 'pkwy' => 'pkwy',
            'place' => 'pl', 'pl' => 'pl',
            'road' => 'rd', 'rd' => 'rd',
            'street' => 'st', 'st' => 'st',
            'terrace' => 'ter', 'ter' => 'ter',
            'trail' => 'trl', 'trl' => 'trl',
        ];

        $normalized = Str::of($value)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();

        return collect(explode(' ', $normalized))
            ->filter()
            ->map(fn (string $part): string => $streetSuffixes[$part] ?? $part)
            ->implode(' ');
    }

    /**
     * An address key with a trailing street suffix removed, so "1122 cascade
     * creek dr" also matches a property recorded as "1122 Cascade Creek".
     * PropertyWare is inconsistent about whether the suffix is part of a
     * property's name, so both spellings have to be comparable.
     *
     * Only a trailing suffix is dropped: "1500 Park Lane Ct" must not lose its
     * middle word.
     */
    public function withoutStreetSuffix(string $addressKey): string
    {
        $suffixes = ['ave', 'blvd', 'cir', 'ct', 'dr', 'hwy', 'ln', 'pkwy', 'pl', 'rd', 'st', 'ter', 'trl'];

        $parts = array_values(array_filter(explode(' ', $addressKey)));

        // A bare "123 Dr" would be left as just a number, which would collide
        // with every other property on the same block.
        if (count($parts) < 3) {
            return '';
        }

        if (! in_array(end($parts), $suffixes, true)) {
            return '';
        }

        array_pop($parts);

        return implode(' ', $parts);
    }
}
