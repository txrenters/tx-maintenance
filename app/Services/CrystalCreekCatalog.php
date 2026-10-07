<?php

namespace App\Services;

/**
 * What the office may pick when creating a Crystal Creek work order: the
 * two categories the crew takes outside calls for, and the fixed "Scope of
 * Work" list under each, as the maintenance coordinator gave them (10-08).
 * The form offers these and the request refuses anything else, so the
 * Jobber job title and later reports always carry one of these spellings.
 */
final class CrystalCreekCatalog
{
    public const HVAC = 'HVAC';

    public const PEST_CONTROL = 'Pest Control';

    /**
     * Group label => items. The HVAC list came as a priced catalogue with
     * headings; the headings are kept as groups so the dropdown stays
     * readable at ninety-odd entries.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const SCOPES = [
        self::HVAC => [
            'Service' => [
                'Diagnostic / Service Call (Business Hours)',
                'Emergency Diagnostic (After Hours)',
                'Trip Charge Only',
                'Digital Thermostat Replacement',
                'Smart Thermostat Installation (Customer Supplied)',
                'Capacitor Replacement',
                'Contactor Replacement',
                'Hard Start Kit Installation',
                'Condenser Fan Motor Replacement',
                'Condenser Fan Blade',
                'Blower Motor Replacement (PSC)',
                'ECM Blower Motor Replacement',
                'Blower Wheel Cleaning',
                'Condensate Drain Flush',
                'Float Safety Switch Installation',
                'Secondary Drain Pan Safety Switch',
                'Primary Float Switch',
                'Condensate Pump Replacement',
                'Disconnect Replacement',
                'Breaker Replacement',
                'Fuses',
                'Low-Voltage Wiring Repair',
                'High-Voltage Wiring Repair',
                'Transformer Replacement',
                'Relay Replacement',
                'Sequencer Replacement',
                'Defrost Control Board',
                'Control Board Replacement',
                'Pressure Switch Replacement',
                'Limit Switch Replacement',
                'Flame Sensor Cleaning',
                'Flame Sensor Replacement',
                'Ignitor Replacement',
                'Gas Valve Replacement',
                'Inducer Motor Replacement',
            ],
            'Refrigerant Services' => [
                'Electronic Leak Search',
                'Nitrogen Pressure Test',
                'Refrigerant Recovery',
                'R-410A Refrigerant',
                'R-32 Refrigerant',
                'R-454B Refrigerant',
                'R-22 Refrigerant (if applicable)',
            ],
            'Coil and Compressor Repairs' => [
                'Evaporator Coil Replacement',
                'Condenser Coil Replacement',
                'Compressor Replacement',
                'Filter Drier Replacement',
                'TXV Replacement',
                'Piston Metering Device',
            ],
            'Airflow and Ductwork' => [
                'Plenum Sealing',
                'Return Plenum Fabrication',
                'Supply Plenum Fabrication',
                'Insulated Flex Duct Replacement',
                'Metal Duct Repair',
                'Register Replacement',
                'Return Grille Replacement',
                'Air Balancing',
                'Attic Duct Repair',
            ],
            'Maintenance' => [
                'HVAC Tune-Up',
                'Condenser Coil Cleaning',
                'Evaporator Coil Cleaning (Accessible)',
                'Complete Indoor Air Handler Cleaning',
                'Standard Filter Replacement During Scheduled Inspection',
                'Standalone Filter Replacement Service',
            ],
            'Equipment Replacement' => [
                'Air Handler Replacement',
                'Condenser Replacement',
                'Furnace Replacement',
                'Complete Split-System Replacement',
                'Single-Zone Mini-Split Installation',
            ],
            'Miscellaneous' => [
                'UV Light Installation',
                'HVAC Surge Protector',
                'Attic Safety Platform',
                'Drain Pan Replacement',
            ],
        ],
        self::PEST_CONTROL => [
            '' => [
                'Pest Control Inspection',
                'General Pest Treatment (Interior & Exterior)',
                'Exterior Perimeter Treatment',
                'Interior Pest Treatment',
                'Ant Treatment',
                'Cockroach Treatment',
                'Spider Treatment',
                'Flea Treatment',
                'Wasp Nest Removal',
                'Rodent Inspection',
                'Rodent Bait Station Installation (Up to 4 Stations)',
                'Rodent Trap Setup',
                'Rodent Exclusion (Minor Entry Points)',
                'Bee Removal (Accessible Swarm)',
                'Termite Inspection',
                'Localized Termite Treatment',
                'Quarterly Preventive Pest Service',
                'Heavy Infestation Treatment',
            ],
        ],
    ];

    /** @return array<int, string> */
    public static function categories(): array
    {
        return array_keys(self::SCOPES);
    }

    /**
     * The catalogue's own spelling of a category, matched without regard to
     * case or surrounding whitespace; null when it is not one of ours.
     */
    public static function canonicalCategory(?string $category): ?string
    {
        $wanted = mb_strtolower(trim((string) $category));

        foreach (self::categories() as $known) {
            if (mb_strtolower($known) === $wanted) {
                return $known;
            }
        }

        return null;
    }

    public static function isCategory(?string $category): bool
    {
        return self::canonicalCategory($category) !== null;
    }

    /**
     * Group label => items for one category; an empty array for a category
     * that is not ours. Pest Control is one group with a blank label.
     *
     * @return array<string, array<int, string>>
     */
    public static function scopesFor(?string $category): array
    {
        $known = self::canonicalCategory($category);

        return $known === null ? [] : self::SCOPES[$known];
    }

    /** @return array<int, string> */
    public static function allScopesFor(?string $category): array
    {
        return array_merge(...array_values(self::scopesFor($category) ?: [[]]));
    }

    /** Whether $scope is one of the items listed under $category (exact text, trimmed). */
    public static function isScopeOf(?string $category, ?string $scope): bool
    {
        return in_array(trim((string) $scope), self::allScopesFor($category), true);
    }

    /**
     * The shape the create dialog receives: the categories in order and,
     * per category, the groups as {label, items} so the dropdown can show
     * headings without knowing the catalogue.
     *
     * @return array{categories: array<int, string>, scopes: array<string, array<int, array{label: string, items: array<int, string>}>>}
     */
    public static function forForm(): array
    {
        $scopes = [];

        foreach (self::SCOPES as $category => $groups) {
            foreach ($groups as $label => $items) {
                $scopes[$category][] = ['label' => $label, 'items' => $items];
            }
        }

        return ['categories' => self::categories(), 'scopes' => $scopes];
    }
}
