<?php

namespace App\Services;

use App\Models\Building;
use Illuminate\Support\Str;

/**
 * Matches the "Property:" address read off an HOA notice to a Building in the
 * system. The street number is the strong signal (a notice for 10107 must not
 * land on 10170); street-word overlap breaks ties. Returns a best guess plus a
 * few alternates so staff can confirm or correct the match on screen.
 */
class HoaPropertyMatcher
{
    /**
     * @return array{
     *     best: ?array{id: int, name: string, score: int},
     *     candidates: array<int, array{id: int, name: string, score: int}>
     * }
     */
    public function match(?string $address): array
    {
        $empty = ['best' => null, 'candidates' => []];

        if (blank($address)) {
            return $empty;
        }

        $houseNumber = $this->houseNumber($address);
        $streetTokens = $this->streetTokens($address);

        if ($houseNumber === null) {
            return $empty;
        }

        // Only consider buildings whose name/address carries the same house
        // number — keeps the scan fast on a large portfolio.
        $buildings = Building::query()
            ->where(fn ($q) => $q->where('name', 'LIKE', '%'.$houseNumber.'%')
                ->orWhere('address', 'LIKE', '%'.$houseNumber.'%'))
            ->orderBy('name')
            ->limit(50)
            ->get(['propertyware_id', 'name', 'address']);

        $scored = [];

        foreach ($buildings as $building) {
            $haystack = trim(($building->name ?? '').' '.($building->address ?? ''));
            $haystackNumbers = $this->allNumbers($haystack);

            // The house number must appear as a standalone number, not as a
            // coincidental substring of a zip or another figure.
            if (! in_array($houseNumber, $haystackNumbers, true)) {
                continue;
            }

            $buildingTokens = $this->streetTokens($haystack);
            $overlap = count(array_intersect($streetTokens, $buildingTokens));

            $scored[] = [
                'id' => (int) $building->propertyware_id,
                'name' => (string) $building->name,
                'score' => 100 + $overlap, // house-number hit is the floor; overlap ranks
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $candidates = array_slice($scored, 0, 5);

        return [
            'best' => $candidates[0] ?? null,
            'candidates' => $candidates,
        ];
    }

    private function houseNumber(string $address): ?string
    {
        return preg_match('/\b(\d{1,6})\b/', $address, $matches) ? $matches[1] : null;
    }

    /**
     * @return array<int, string>
     */
    private function allNumbers(string $text): array
    {
        preg_match_all('/\b(\d{1,6})\b/', $text, $matches);

        return $matches[1];
    }

    /**
     * The distinctive street words: lowercased, punctuation stripped, digits and
     * common street/geography noise removed so "10107 Mariposa Green Ct" reduces
     * to {mariposa, green, ct}.
     *
     * @return array<int, string>
     */
    private function streetTokens(string $text): array
    {
        $noise = ['tx', 'texas', 'houston', 'cypress', 'suite', 'ste', 'apt', 'unit', 'no', 'account', 'demo'];

        return collect(preg_split('/[^a-z0-9]+/', Str::lower($text)))
            ->filter(fn ($token) => filled($token) && ! ctype_digit($token) && strlen($token) > 1)
            ->reject(fn ($token) => in_array($token, $noise, true))
            ->unique()
            ->values()
            ->all();
    }
}
