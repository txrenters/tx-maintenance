<?php

namespace Tests\Unit;

use App\Ai\TenantEasyFixCriteria;
use Tests\TestCase;

class TenantEasyFixCriteriaTest extends TestCase
{
    private function scan(string $text, ?string $category = null): ?string
    {
        return TenantEasyFixCriteria::scan(strtolower($text), $category)['key'] ?? null;
    }

    public function test_a_jammed_or_humming_disposal_is_handbook_row_6(): void
    {
        foreach ([
            'Garbage disposal is humming but not turning',
            'The disposal is jammed, something is stuck in it',
            'Garbage disposal jammed',
        ] as $text) {
            $this->assertSame('disposal_jammed', $this->scan($text), $text);
        }
    }

    public function test_a_dead_disposal_is_handbook_row_5(): void
    {
        foreach ([
            'disposal stopped working, makes no sound when I flip the switch',
            'Kitchen garbage disposal not working',
        ] as $text) {
            $this->assertSame('disposal_not_working', $this->scan($text), $text);
        }
    }

    public function test_the_propertyware_category_alone_matches_a_disposal(): void
    {
        $this->assertSame('disposal_not_working', $this->scan('Not working', 'Garbage Disposal'));
        $this->assertSame('smoke_detector_battery', $this->scan('', 'Smoke Detectors'));
        $this->assertSame('clogged_sink', $this->scan('', 'Clogged Sink'));
    }

    public function test_the_library_mirrors_the_handbook_s_26_rows(): void
    {
        $this->assertCount(26, TenantEasyFixCriteria::items());

        // Rows whose handbook link is a search page, or that overlap the
        // emergency rules, carry no video and are never texted.
        foreach (['light_bulb', 'water_heater_pilot', 'ac_heat_not_working', 'furnace_not_working', 'dryer_not_heating', 'washer_not_starting', 'garage_door', 'water_shutoff_valve', 'stove_burner'] as $key) {
            $this->assertNull(TenantEasyFixCriteria::items()[$key]['video_url'], $key);
        }

        foreach (TenantEasyFixCriteria::items() as $key => $item) {
            $this->assertStringNotContainsString('results?search_query', (string) $item['video_url'], $key);
        }
    }

    public function test_a_light_fixture_category_alone_is_not_a_bulb(): void
    {
        // Fixture repair is a vendor job; only a bulb in the text qualifies.
        $this->assertNull($this->scan('Light in the hallway not working', 'Light Fixture'));
        $this->assertSame('light_bulb', $this->scan('Light bulb in the hallway burned out', 'Light Fixture'));
    }

    public function test_a_leaking_disposal_is_not_an_easy_fix(): void
    {
        foreach ([
            'Garbage disposal leaking under the sink',
            'Disposal is dripping water into the cabinet',
            'The garbage disposal leaks when I run it',
            'Disposal jammed and there is water under the sink',
        ] as $text) {
            $this->assertNull($this->scan($text), $text);
        }
    }

    public function test_hazards_and_emergencies_are_never_easy_fixes(): void
    {
        foreach ([
            'Garbage disposal sparking when turned on',
            'Breaker tripped and there is a burning smell from the panel',
            'Smoke detector going off, smoke coming from the kitchen',
            'Breaker tripped, no power in the house at all',
            'Disposal jammed and the AC is completely out, no a/c in this heat',
        ] as $text) {
            $this->assertNull($this->scan($text), $text);
        }
    }

    public function test_a_fix_the_tenant_already_tried_is_not_repeated(): void
    {
        $this->assertNull($this->scan('Disposal humming, already tried the reset button'));
        $this->assertNull($this->scan('Breaker keeps tripping every time I turn on the dryer'));
    }

    public function test_the_other_handbook_items_match_their_wording(): void
    {
        $this->assertSame('smoke_detector_battery', $this->scan('Smoke detector chirping every minute in the hallway'));
        $this->assertSame('tripped_breaker', $this->scan('Power lost to part of the house, the bedroom lights and outlets are out'));
        $this->assertSame('gfci_outlet', $this->scan('Bathroom outlet stopped working, the GFCI one'));
        $this->assertSame('faucet_aerator', $this->scan('Kitchen faucet has very low water pressure, just a trickle'));
        $this->assertSame('clogged_sink', $this->scan('Bathroom sink is draining very slowly'));
        $this->assertSame('slow_shower_drain', $this->scan('The shower drain is slow, water pools around my feet'));
        $this->assertSame('clogged_toilet', $this->scan('Toilet is clogged and will not flush'));
        $this->assertSame('running_toilet', $this->scan('The toilet keeps running after flushing'));
        $this->assertSame('thermostat_batteries', $this->scan('Thermostat screen is blank'));
        $this->assertSame('hvac_filter', $this->scan('Need a new air filter for the AC, the old one is dirty'));
        $this->assertSame('dishwasher_not_draining', $this->scan('Dishwasher not draining, water in the bottom after every cycle'));
        $this->assertSame('dishwasher_not_starting', $this->scan('Dishwasher won\'t start at all'));
        $this->assertSame('dishwasher_not_cleaning', $this->scan('Dishes come out dirty every time'));
        $this->assertSame('refrigerator_not_cooling', $this->scan('Refrigerator stopped cooling yesterday'));
        $this->assertSame('bathroom_fan', $this->scan('Bathroom exhaust fan is really noisy and dusty'));
        $this->assertSame('garage_door', $this->scan('Garage door remote does not open the door anymore'));
        $this->assertSame('stove_burner', $this->scan('Front left stove burner won\'t light, it just clicks'));
        $this->assertSame('water_shutoff_valve', $this->scan('No water to the toilet, the tank will not fill'));
    }

    public function test_the_recognised_only_rows_are_not_sendable(): void
    {
        $this->assertSame('ac_heat_not_working', $this->scan('AC not cooling very well'));
        $this->assertFalse(TenantEasyFixCriteria::isSendable('ac_heat_not_working'));

        $this->assertSame('furnace_not_working', $this->scan('Furnace not turning on'));
        $this->assertFalse(TenantEasyFixCriteria::isSendable('furnace_not_working'));

        $this->assertSame('water_heater_pilot', $this->scan('Water heater pilot light went out'));
        $this->assertFalse(TenantEasyFixCriteria::isSendable('water_heater_pilot'));

        // "Not heating" on a dryer or an oven is not the AC/heat row.
        $this->assertNotSame('ac_heat_not_working', $this->scan('Dryer is not heating anymore'));
        $this->assertNotSame('ac_heat_not_working', $this->scan('Oven not heating up to temperature'));

        // A dead AC in summer or no heat in a freeze stays an emergency.
        $this->assertNull($this->scan('No AC at all, the house is 95 degrees'));
        $this->assertNull($this->scan('No heat and it is freezing outside'));
    }

    public function test_a_clogged_toilet_that_overflows_or_is_the_only_one_is_not(): void
    {
        $this->assertNull($this->scan('Toilet clogged and overflowing onto the floor'));
        $this->assertNull($this->scan('Toilet is clogged, it is the only toilet in the house'));
    }

    /**
     * Real descriptions the first audit over local data matched wrongly.
     */
    public function test_the_audit_s_false_positives_stay_out(): void
    {
        foreach ([
            'Clogged roof gutters. The water over flow.',
            'The sink drainer is clogged badly - smells, water is not coming through. The stopper is falling apart as it is last century old.',
            'We cant use microwave. It always gives issues for the breaker so idk if that has anything to do with it.',
            'Smoke alarm isnt fitted correctly, can it be moved to the correct spot',
            'Microwave shorts out the breaker and has no handle. Stove vent just vents right back into the kitchen. Backyard fence in need of repair.',
            'Its been 2 weeks, and doorbell wiring and garbage disposal work orders still havent been serviced',
            'A/C filter grid cover needs to properly mounted or replaced. Keeps falling as it is being held with weak magnets',
            'The master shower is draining slowly. I can get it to go down with a plunger, but the problem is getting worse with time.',
            'Sink in guest bathroom is stopped up. Seems as though water backup. Main bathroom shower head is spewing water. Air filters need to get replaced since',
        ] as $text) {
            $this->assertNull($this->scan($text), $text);
        }
    }

    public function test_a_long_multi_issue_description_never_gets_the_one_item_text(): void
    {
        $list = 'The garbage disposal is not working and the kitchen sink is clogged on the side with the garbage disposal. '
            .'Also, the movers arrived and while they were bringing in the couch they scratched the hallway wall, the second bedroom door does not close, '
            .'the blinds in the living room are missing two slats, the back gate latch is loose, the patio light does not come on, '
            .'the dishwasher rack is rusty and the upstairs bathroom fan is very loud. Please send someone to look at all of these.';

        $this->assertGreaterThan(80, str_word_count($list));
        $this->assertNull($this->scan($list));
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_NONE, TenantEasyFixCriteria::assessAppliance(strtolower($list.' The refrigerator is also warm.'), [['fieldName' => 'Included Appliances', 'value' => 'None']])['status']);

        // Under the cap, the same disposal wording matches.
        $this->assertSame('disposal_not_working', $this->scan('The garbage disposal is not working.'));
    }

    public function test_unrelated_requests_match_nothing(): void
    {
        foreach ([
            'Water heater is leaking in the garage',
            'Front door lock is broken and will not lock',
            'Roof shingles blew off in the storm',
            'Fence panel in the backyard is leaning over',
        ] as $text) {
            $this->assertNull($this->scan($text), $text);
        }
    }

    public function test_an_item_without_a_video_is_recognised_but_not_sendable(): void
    {
        $this->assertSame('light_bulb', $this->scan('Kitchen light bulb burned out'));

        $this->assertFalse(TenantEasyFixCriteria::isSendable('light_bulb'));

        $this->withVideo('light_bulb');

        $this->assertTrue(TenantEasyFixCriteria::isSendable('light_bulb'));
        $this->assertTrue(TenantEasyFixCriteria::isSendable('disposal_jammed'));
        $this->assertFalse(TenantEasyFixCriteria::isSendable('not_a_key'));
    }

    public function test_every_configured_video_link_is_a_youtube_link(): void
    {
        foreach (TenantEasyFixCriteria::items() as $key => $item) {
            if ($item['video_url'] === null) {
                continue;
            }

            $this->assertMatchesRegularExpression('#^https://(www\.)?(youtube\.com|youtu\.be)/#', $item['video_url'], $key);
        }
    }

    public function test_a_tenant_owned_washer_is_flagged_when_the_property_lists_only_a_fridge(): void
    {
        $fields = [['fieldName' => 'Included Appliances', 'value' => 'refrigerator', 'dataType' => 'Text']];

        $result = TenantEasyFixCriteria::assessAppliance('washing machine will not spin', $fields);

        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_TENANT_OWNED, $result['status']);
        $this->assertSame('appliance_washer', $result['key']);
        $this->assertSame('refrigerator', $result['included_value']);
    }

    public function test_an_included_appliance_stays_the_property_s_problem(): void
    {
        $fields = [['fieldName' => 'Included Appliances', 'value' => 'Refrigerator, washer and dryer, dishwasher,disposer, microwave']];

        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_INCLUDED, TenantEasyFixCriteria::assessAppliance('washer not spinning', $fields)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_INCLUDED, TenantEasyFixCriteria::assessAppliance('the dryer is not heating', $fields)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_INCLUDED, TenantEasyFixCriteria::assessAppliance('fridge not cooling', $fields)['status']);
    }

    public function test_washer_dryer_connections_do_not_count_as_an_included_washer(): void
    {
        $fields = [['fieldName' => 'Included Appliances', 'value' => 'Refrigerator, washer/dryer connections']];

        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_TENANT_OWNED, TenantEasyFixCriteria::assessAppliance('my washer is not spinning', $fields)['status']);
        // A leaking one might be the hookup or be damaging the floor: staff look, nobody is texted.
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_NONE, TenantEasyFixCriteria::assessAppliance('my washer is leaking soap everywhere', $fields)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_TENANT_OWNED, TenantEasyFixCriteria::assessAppliance('dryer stopped heating', $fields)['status']);
    }

    public function test_a_none_value_means_every_appliance_is_the_tenant_s(): void
    {
        $fields = [['fieldName' => 'Included Appliances', 'value' => 'None']];

        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_TENANT_OWNED, TenantEasyFixCriteria::assessAppliance('refrigerator not cooling', $fields)['status']);
    }

    public function test_a_missing_or_blank_field_leaves_ownership_unknown(): void
    {
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_UNKNOWN, TenantEasyFixCriteria::assessAppliance('refrigerator not cooling', null)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_UNKNOWN, TenantEasyFixCriteria::assessAppliance('refrigerator not cooling', [])['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_UNKNOWN, TenantEasyFixCriteria::assessAppliance('refrigerator not cooling', [['fieldName' => 'Included Appliances', 'value' => '  ']])['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_UNKNOWN, TenantEasyFixCriteria::assessAppliance('refrigerator not cooling', [['fieldName' => 'Paint Color', 'value' => 'Agreeable Gray']])['status']);
    }

    public function test_a_hookup_or_a_dishwasher_is_not_a_tenant_appliance(): void
    {
        $fields = [['fieldName' => 'Included Appliances', 'value' => 'None']];

        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_NONE, TenantEasyFixCriteria::assessAppliance('washer hookup valve is leaking', $fields)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_NONE, TenantEasyFixCriteria::assessAppliance('dishwasher not draining', $fields)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_NONE, TenantEasyFixCriteria::assessAppliance('dryer vent needs cleaning', $fields)['status']);
        $this->assertSame(TenantEasyFixCriteria::APPLIANCE_NONE, TenantEasyFixCriteria::assessAppliance('kitchen sink is clogged', $fields)['status']);
    }

    public function test_keys_and_labels_from_the_ai_are_normalised(): void
    {
        $this->assertSame('disposal_jammed', TenantEasyFixCriteria::normalizeKey('disposal_jammed'));
        $this->assertSame('disposal_jammed', TenantEasyFixCriteria::normalizeKey(' Disposal-Jammed '));
        $this->assertSame('disposal_not_working', TenantEasyFixCriteria::normalizeKey('garbage disposal'));
        $this->assertSame('appliance_washer', TenantEasyFixCriteria::normalizeKey('appliance washer'));
        $this->assertNull(TenantEasyFixCriteria::normalizeKey('roof'));
        $this->assertNull(TenantEasyFixCriteria::normalizeKey(null));
    }

    public function test_the_agent_instructions_name_every_item(): void
    {
        $instructions = implode("\n", TenantEasyFixCriteria::agentInstructions());

        foreach (array_keys(TenantEasyFixCriteria::items()) as $key) {
            $this->assertStringContainsString($key, $instructions);
        }

        $this->assertStringContainsString('appliance_washer', $instructions);
    }

    private function withVideo(string $key, string $url = 'https://youtu.be/example'): void
    {
        $items = config('tenant_easy_fix.items');

        foreach ($items as $index => $item) {
            if ($item['key'] === $key) {
                $items[$index]['video_url'] = $url;
            }
        }

        config(['tenant_easy_fix.items' => $items]);
    }
}
