<?php

namespace App\Ai;

use Illuminate\Support\Str;

/**
 * Single source of truth for what counts as a maintenance emergency.
 *
 * The criteria were provided by operations (Chana, July 2026) and are shared
 * by the AI classification agent, the keyword-based heuristic fallback, and
 * (in the future) the AI receptionist, so the definition can never drift
 * between consumers.
 */
class EmergencyCriteria
{
    public const DEFINITION = 'An emergency maintenance issue is any condition that threatens the safety of occupants, '
        .'causes active damage to the property, renders the property uninhabitable, or creates a significant risk of '
        .'further damage if not addressed immediately.';

    /**
     * Emergency categories with the qualifying conditions for each.
     *
     * @return array<string, array<int, string>>
     */
    public static function categories(): array
    {
        return [
            'Water' => [
                'Active water leaks causing damage',
                'Burst water lines',
                'Overflowing toilets that cannot be stopped',
                'Flooding from plumbing failures',
                'Sewer backups inside the property',
            ],
            'Electrical' => [
                'Burning smell from outlets or electrical panels',
                'Sparking outlets, switches, or wiring',
                'Electrical fire hazards',
                'Complete power outage affecting only the property (not utility-wide outages)',
            ],
            'HVAC' => [
                'Complete loss of air conditioning when indoor temperatures create health risks (especially during Houston summers)',
                'Complete loss of heat during freezing temperatures',
                'Carbon monoxide concerns from heating equipment',
                'Refrigerant leaks creating safety concerns',
            ],
            'Fire & Smoke' => [
                'Active fire',
                'Smoke inside the property',
                'Smoke detector issues indicating possible fire',
                'Burnt electrical components',
            ],
            'Gas' => [
                'Gas odor inside or around the property',
                'Suspected gas leaks',
                'Damaged gas lines',
            ],
            'Security' => [
                'Broken exterior doors that cannot be secured',
                'Broken locks preventing secure occupancy',
                'Broken windows creating an immediate security risk',
                'Forced entry or burglary damage',
            ],
            'Structural' => [
                'Ceiling collapse',
                'Roof collapse',
                'Major storm damage exposing the interior',
                'Significant foundation or structural failures',
                'Large trees fallen on the structure',
            ],
            'Health & Safety' => [
                'Carbon monoxide alarm activation',
                'Significant mold caused by active flooding',
                'No functioning toilet in a single-bathroom home',
                'Situations creating immediate health hazards',
            ],
        ];
    }

    /**
     * Issues that are urgent maintenance but explicitly NOT emergencies.
     *
     * @return array<int, string>
     */
    public static function nonEmergencies(): array
    {
        return [
            'Dripping faucets',
            'Running toilets (unless overflowing)',
            'Garbage disposal not working',
            'One appliance not working',
            'One sink clogged',
            'Minor roof leaks during dry weather',
            'Pest sightings',
            'Cosmetic issues',
            'Air conditioning operating but not cooling to tenant preference',
            'Loss of hot water (urgent, but typically not an emergency)',
        ];
    }

    /**
     * Instruction block for AI agents that need to apply these criteria.
     *
     * @return array<int, string>
     */
    public static function agentInstructions(): array
    {
        $lines = [
            'You also assess whether the work order is a maintenance EMERGENCY using these exact criteria:',
            self::DEFINITION,
            'Emergency categories and their qualifying conditions:',
        ];

        foreach (self::categories() as $category => $conditions) {
            $lines[] = $category.': '.implode('; ', $conditions).'.';
        }

        $lines[] = 'The following are urgent maintenance issues but NOT emergencies: '.implode('; ', self::nonEmergencies()).'.';
        $lines[] = 'You MUST always decide is_emergency one way or the other — your label is applied automatically with no human review, so be careful and judge strictly by the criteria above, not by the tone or urgency of the tenant\'s wording.';
        $lines[] = 'When a detail is missing, judge by the most likely real-world reading for a Houston rental property. In particular: complete loss of air conditioning during hot months is typically an emergency in Houston even when the tenant does not state the indoor temperature, per the criteria; a leak described without saying it stopped should be treated as active.';
        $lines[] = 'If, after applying the criteria, the text still genuinely supports either reading, prefer is_emergency=true when the ambiguity involves possible active damage or occupant safety (missing a real emergency is worse than over-flagging one), and is_emergency=false when the ambiguity is only about convenience or comfort.';
        $lines[] = 'When it is an emergency, set emergency_category to the single best matching category name from the list above; otherwise set it to null.';
        $lines[] = 'Set emergency_confidence from 0 to 100 based on how clearly the work order text supports the assessment.';
        $lines[] = 'Set emergency_reason to one short sentence grounded in the work order text.';

        return $lines;
    }

    /**
     * Keyword needles per category used by the heuristic fallback when the AI
     * provider is unavailable, with a conservative confidence per category.
     * The fallback leans toward flagging: a matched signal labels the work
     * order Emergency (missing a real one is worse than over-flagging), and
     * staff can always correct the label from the work order page.
     *
     * @return array<string, array{confidence: int, keywords: array<int, string>}>
     */
    public static function heuristicRules(): array
    {
        return [
            'Gas' => [
                'confidence' => 85,
                'keywords' => ['gas leak', 'gas odor', 'gas smell', 'smell gas', 'smelling gas', 'gas line damage'],
            ],
            'Fire & Smoke' => [
                'confidence' => 85,
                'keywords' => ['on fire', 'caught fire', 'active fire', 'house fire', 'smoke in', 'smoke inside', 'smoke coming', 'smell of smoke', 'smelling smoke', 'burnt outlet', 'burnt wiring'],
            ],
            'Water' => [
                'confidence' => 80,
                'keywords' => ['flood', 'flooding', 'flooded', 'burst pipe', 'busted pipe', 'pipe burst', 'burst water line', 'water line burst', 'line burst', 'sewage', 'sewer backup', 'sewer back up', 'water everywhere', 'water pouring', 'active leak', 'active water leak', 'slab leak', 'overflowing', 'toilet overflowing', 'overflowing toilet'],
            ],
            'Electrical' => [
                'confidence' => 80,
                'keywords' => ['sparking', 'sparks', 'burning smell', 'exposed wire', 'exposed wiring', 'power outage', 'no power at all', 'no power in the house', 'no power to the house', 'panel smoking'],
            ],
            'Structural' => [
                'confidence' => 80,
                'keywords' => ['ceiling collapse', 'ceiling collapsed', 'ceiling fell', 'roof collapse', 'roof collapsed', 'tree fell', 'tree on the', 'storm damage', 'foundation failure'],
            ],
            'Health & Safety' => [
                'confidence' => 80,
                'keywords' => ['carbon monoxide', 'co alarm', 'co detector going off', 'only toilet', 'no working toilet', 'no functioning toilet'],
            ],
            'HVAC' => [
                'confidence' => 70,
                'keywords' => ['no ac', 'no a/c', 'no air conditioning', 'ac completely out', 'a/c not working at all', 'no heat', 'heater not working at all', 'refrigerant leak'],
            ],
            'Security' => [
                'confidence' => 70,
                'keywords' => ['break in', 'break-in', 'broke in', 'burglary', 'cannot lock', "can't lock", 'unable to lock', 'door will not close', 'door will not lock', 'broken window'],
            ],
        ];
    }

    /**
     * Scan lowered work order text for emergency signals.
     *
     * @return array{is_emergency: bool, category: ?string, confidence: int, matched: array<int, string>}
     */
    public static function scan(string $loweredText): array
    {
        $bestCategory = null;
        $bestConfidence = 0;
        $bestMatched = [];

        foreach (self::heuristicRules() as $category => $rule) {
            $matched = array_values(array_filter(
                $rule['keywords'],
                fn (string $needle) => self::keywordMatches($loweredText, $needle)
            ));

            if ($matched !== [] && ($rule['confidence'] > $bestConfidence || count($matched) > count($bestMatched))) {
                $bestCategory = $category;
                $bestConfidence = $rule['confidence'];
                $bestMatched = $matched;
            }
        }

        return [
            'is_emergency' => $bestCategory !== null,
            'category' => $bestCategory,
            'confidence' => $bestCategory !== null ? $bestConfidence : 40,
            'matched' => $bestMatched,
        ];
    }

    /**
     * Map a free-form category returned by the AI onto the canonical list.
     */
    public static function normalizeCategory(?string $category): ?string
    {
        if (blank($category)) {
            return null;
        }

        foreach (array_keys(self::categories()) as $canonical) {
            if (Str::lower($canonical) === Str::lower(trim($category))) {
                return $canonical;
            }
        }

        return $category;
    }

    private static function keywordMatches(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/i';

        return (bool) preg_match($pattern, $haystack);
    }
}
