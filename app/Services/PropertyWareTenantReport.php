<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The PropertyWare tenant report the Jobber TBP reminders read: one row per
 * lease contact carrying the building name, lease status, phones, email and
 * the Tenant Benefits Package flag (the record indexes below are the report's
 * column order, the same ones SendJobReminders uses).
 *
 * This wrapper reads the same report for the manual "Send notification"
 * button and offers LOOSE building matches - same street number plus a street
 * name that lines up - because a person confirms every recipient before a
 * text goes out. The automated reminder keeps its exact-match rule; nothing
 * here changes it.
 */
class PropertyWareTenantReport
{
    public const REPORT_URL = 'https://app.propertyware.com/pw/00a/4377411585/JSON?1ADhXAA&shardKey=182255624';

    public const COLUMN_LEASE_STATUS = 2;

    public const COLUMN_FULL_NAME = 3;

    public const COLUMN_BUILDING = 4;

    public const COLUMN_MOBILE_PHONE = 10;

    public const COLUMN_EMAIL = 11;

    public const COLUMN_WORK_PHONE = 12;

    public const COLUMN_HOME_PHONE = 13;

    public const COLUMN_ENROLLED_IN_TBP = 14;

    private const CACHE_KEY = 'propertyware_tenant_report_records';

    private const CACHE_TTL_SECONDS = 600;

    /**
     * Street-type words folded to the abbreviation PropertyWare and Jobber
     * each use inconsistently ("Rum River Court" vs "Rum River Ct").
     *
     * @var array<string, string>
     */
    private const STREET_SUFFIXES = [
        'avenue' => 'ave', 'ave' => 'ave',
        'boulevard' => 'blvd', 'blvd' => 'blvd',
        'brook' => 'brk', 'brk' => 'brk',
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
        'way' => 'way',
    ];

    /**
     * Every row of the report, cached briefly so a staff member opening a few
     * visits in a row does not re-download it each time. Fails OPEN to an
     * empty list: an outage must never stop the notice from being sent to a
     * number typed by hand.
     *
     * @return list<array<int, mixed>>
     */
    public function records(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
                $response = Http::timeout(60)->get(self::REPORT_URL);

                if ($response->failed()) {
                    throw new \RuntimeException('PropertyWare tenant report returned HTTP '.$response->status());
                }

                $records = json_decode($response->body(), true, 512, JSON_INVALID_UTF8_IGNORE)['records'] ?? [];

                return is_array($records) ? array_values($records) : [];
            });
        } catch (\Throwable $exception) {
            Log::warning('PropertyWare tenant report unavailable; offering no suggested recipients.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Lease contacts whose building looks like the Jobber client name, exact
     * matches first.
     *
     * @return list<array{name: string, phone: string|null, email: string|null, status: string, enrolled: bool, building: string, exact: bool}>
     */
    public function contactsForBuilding(string $jobberClientName): array
    {
        $wanted = self::normalize($jobberClientName);

        if ($wanted === '' || self::streetNumber($wanted) === null) {
            return [];
        }

        $contacts = [];

        foreach ($this->records() as $record) {
            $buildingLabel = trim((string) ($record[self::COLUMN_BUILDING] ?? ''));
            $building = self::normalize($buildingLabel);

            if (! self::looksLikeSameBuilding($wanted, $building)) {
                continue;
            }

            $phone = collect([
                $record[self::COLUMN_MOBILE_PHONE] ?? null,
                $record[self::COLUMN_HOME_PHONE] ?? null,
                $record[self::COLUMN_WORK_PHONE] ?? null,
            ])
                ->map(fn ($value): string => trim((string) $value))
                ->first(fn (string $value): bool => $value !== '');

            $email = trim((string) ($record[self::COLUMN_EMAIL] ?? ''));

            $contacts[] = [
                'name' => trim((string) ($record[self::COLUMN_FULL_NAME] ?? '')),
                'phone' => $phone ?: null,
                'email' => $email !== '' ? $email : null,
                'status' => trim((string) ($record[self::COLUMN_LEASE_STATUS] ?? '')),
                'enrolled' => strtolower(trim((string) ($record[self::COLUMN_ENROLLED_IN_TBP] ?? ''))) === 'yes',
                'building' => $buildingLabel,
                'exact' => $building === $wanted,
            ];
        }

        usort($contacts, fn (array $a, array $b): int => (int) $b['exact'] <=> (int) $a['exact']);

        return $contacts;
    }

    /**
     * Whether two normalized building references describe the same house:
     * identical, or the same street number with a street name that is a
     * prefix of the other ("17710 winnower" ~ "17710 winnower ln") or shares
     * its first word ("6510 gardners brk" ~ "6510 gardners brook").
     */
    public static function looksLikeSameBuilding(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        $number = self::streetNumber($a);

        if ($number === null || $number !== self::streetNumber($b)) {
            return false;
        }

        $aBase = self::withoutTrailingSuffix($a);
        $bBase = self::withoutTrailingSuffix($b);

        if (str_starts_with($aBase, $bBase) || str_starts_with($bBase, $aBase)) {
            return true;
        }

        $aWord = self::firstStreetWord($a);

        return $aWord !== null && $aWord === self::firstStreetWord($b);
    }

    /**
     * Lower-case, punctuation stripped, street types abbreviated.
     */
    public static function normalize(string $value): string
    {
        $parts = preg_split('/\s+/', trim(strtolower((string) preg_replace('/[^\pL\pN\s]/u', ' ', $value)))) ?: [];

        $parts = array_values(array_filter(
            array_map(fn (string $part): string => self::STREET_SUFFIXES[$part] ?? $part, $parts),
            fn (string $part): bool => $part !== '',
        ));

        return implode(' ', $parts);
    }

    private static function streetNumber(string $normalized): ?string
    {
        return preg_match('/^(\d+)\b/', $normalized, $matches) === 1 ? $matches[1] : null;
    }

    private static function withoutTrailingSuffix(string $normalized): string
    {
        $parts = explode(' ', $normalized);

        if (count($parts) > 2 && in_array(end($parts), self::STREET_SUFFIXES, true)) {
            array_pop($parts);
        }

        return implode(' ', $parts);
    }

    private static function firstStreetWord(string $normalized): ?string
    {
        $parts = explode(' ', $normalized);

        return $parts[1] ?? null;
    }
}
