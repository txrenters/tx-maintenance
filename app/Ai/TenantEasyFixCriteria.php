<?php

namespace App\Ai;

use Illuminate\Support\Str;

/**
 * Single source of truth for what counts as a tenant easy fix: a small
 * repair the Maintenance handbook says the tenant handles themselves, with a
 * how-to video. The library itself lives in config/tenant_easy_fix.php so
 * the handbook's items, keywords and video links can be edited without
 * touching this logic.
 *
 * Shared by the intake automation (which decides deterministically, from
 * keywords and the PropertyWare category, whether the tenant gets the how-to
 * text instead of the generic confirmation), by the work order classification
 * agent (which surfaces the ones the keywords missed for staff), and by the
 * easy-fix audit command. Same shape as EmergencyCriteria.
 *
 * Whether an item is the owner's to repair or a non-realty item under the
 * lease (a washer, dryer or refrigerator provided as-is) is deliberately not
 * judged here: the video goes out either way, and the WOC decides by hand
 * from "Checking for Tenant Easy Fix" (Earl, 2026-09-23).
 */
class TenantEasyFixCriteria
{
    /**
     * The easy-fix items, keyed by their `key`.
     *
     * @return array<string, array{key: string, label: string, pw_categories: array<int, string>, keywords: array<int, string>, exclude: array<int, string>, video_url: ?string, tip: string}>
     */
    public static function items(): array
    {
        return collect((array) config('tenant_easy_fix.items', []))
            ->filter(fn ($item) => is_array($item) && filled($item['key'] ?? null))
            ->keyBy('key')
            ->map(fn (array $item) => $item + ['pw_categories' => [], 'keywords' => [], 'exclude' => [], 'video_url' => null, 'tip' => '', 'label' => $item['key']])
            ->all();
    }

    /**
     * One item by key, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function item(?string $key): ?array
    {
        if (blank($key)) {
            return null;
        }

        return self::items()[$key] ?? null;
    }

    /**
     * Whether an easy-fix item can be texted about: the tenant message is
     * built around the handbook's how-to video, so an item without one is
     * recognised (audit, AI chip) but never messaged.
     */
    public static function isSendable(?string $key): bool
    {
        $item = self::item($key);

        return $item !== null && filled($item['video_url'] ?? null);
    }

    /**
     * Scan lowered work order text (description + type + category) for an
     * easy-fix item. Deterministic: a PropertyWare category listed on the item
     * or one of its keyword phrases, with no exclusion phrase (the item's own
     * or the global list) and no emergency signal.
     *
     * @return array{key: string, matched: array<int, string>}|null
     */
    public static function scan(string $loweredText, ?string $category = null): ?array
    {
        if (self::isTooLong($loweredText) || self::hasGlobalExclusion($loweredText) || EmergencyCriteria::scan($loweredText)['is_emergency']) {
            return null;
        }

        $normalizedCategory = self::normalize($category);
        $best = null;

        foreach (self::items() as $key => $item) {
            $categoryHit = $normalizedCategory !== ''
                && in_array($normalizedCategory, array_map([self::class, 'normalize'], $item['pw_categories']), true);

            $matched = array_values(array_filter(
                $item['keywords'],
                fn (string $needle) => self::keywordMatches($loweredText, $needle)
            ));

            if (! $categoryHit && $matched === []) {
                continue;
            }

            if (self::anyMatches($loweredText, $item['exclude'])) {
                continue;
            }

            if ($categoryHit) {
                array_unshift($matched, 'category:'.$category);
            }

            // Most evidence wins; ties keep library order.
            if ($best === null || count($matched) > count($best['matched'])) {
                $best = ['key' => $key, 'matched' => $matched];
            }
        }

        return $best;
    }

    /**
     * Instruction block for the work order classification agent.
     *
     * @return array<int, string>
     */
    public static function agentInstructions(): array
    {
        $lines = [
            'You also assess whether the work order is a TENANT EASY FIX: a small repair the tenant is expected to handle themselves per the Maintenance handbook, for which we text them a how-to video instead of dispatching a vendor.',
            'The easy-fix items and what qualifies:',
        ];

        foreach (self::items() as $key => $item) {
            $lines[] = $key.' ('.$item['label'].'): '.implode(', ', array_slice($item['keywords'], 0, 6)).'.';
        }

        $lines[] = 'It is NOT an easy fix when the same symptom involves a leak, water damage, sparking, burning, a broken or missing part, a hazard, a whole-house outage, or when the tenant says they already tried the reset or fix. When in doubt, set is_tenant_easy_fix to false: a wrong easy-fix label leaves a real repair unattended.';
        $lines[] = 'Set easy_fix_key to the matching item key from the list above when is_tenant_easy_fix is true, otherwise null.';

        return $lines;
    }

    /**
     * Map a free-form key returned by the AI onto the library, or null.
     */
    public static function normalizeKey(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        $wanted = Str::of($key)->lower()->trim()->replace(['-', ' '], '_')->toString();

        foreach (array_keys(self::items()) as $canonical) {
            if ($canonical === $wanted) {
                return $canonical;
            }
        }

        foreach (self::items() as $canonical => $item) {
            if (Str::lower($item['label']) === Str::lower(trim($key))) {
                return $canonical;
            }
        }

        return null;
    }

    private static function hasGlobalExclusion(string $loweredText): bool
    {
        return self::anyMatches($loweredText, (array) config('tenant_easy_fix.global_exclusions', []));
    }

    /**
     * A long description is a list of several issues (a move-in punch list,
     * a follow-up on everything outstanding), never one small request.
     */
    private static function isTooLong(string $loweredText): bool
    {
        $max = (int) config('tenant_easy_fix.max_words', 80);

        return $max > 0 && str_word_count($loweredText) > $max;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function anyMatches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (self::keywordMatches($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Letters and digits only, lowercased, so PropertyWare's spelling
     * variants ("Smoke Detectors " vs "smoke-detectors") compare equal.
     */
    private static function normalize(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower((string) $value));
    }

    public static function keywordMatches(string $haystack, string $needle): bool
    {
        $needle = trim($needle);

        if ($needle === '') {
            return false;
        }

        $pattern = '/(?<![a-z0-9])'.preg_quote(Str::lower($needle), '/').'(?![a-z0-9])/i';

        return (bool) preg_match($pattern, $haystack);
    }
}
