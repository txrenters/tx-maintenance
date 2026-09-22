<?php

/*
|--------------------------------------------------------------------------
| Tenant easy-fix library
|--------------------------------------------------------------------------
|
| The list of small repairs the Maintenance handbook says a tenant handles
| themselves, and the tenant-owned appliances the landlord is not responsible
| for. Read by App\Ai\TenantEasyFixCriteria, which decides at intake whether a
| new work order gets the "here is how to fix it yourself" text (with the
| handbook's how-to video) instead of the generic "we received your request".
|
| Matching is deliberately conservative: a work order must hit an item's
| PropertyWare category or one of its keyword phrases, and must NOT contain
| any exclusion phrase (the item's own, or the global ones below, which are
| the signs that the same symptom is really a leak, a hazard or a broken part
| a vendor has to see). The emergency criteria always win.
|
| An item with no `video_url` is recognised (for the audit and the AI chip on
| the work order) but never texted about: the tenant message is built around
| the video, so it must not go out without one. Fill the links in from the
| handbook.
|
| Keywords and exclusions are matched as whole words/phrases, case-insensitive,
| against the description, type and category.
|
*/

return [

    /*
    | Phrases that mean "this is not a tenant fix, whatever else matched".
    | Phrases, never bare words: a bare "smoke" would kill the smoke-detector
    | item and a bare "leak" every faucet drip.
    */
    'global_exclusions' => [
        'leaking', 'leak under', 'leaks under', 'water under', 'water damage', 'water on the floor',
        'flood', 'flooding', 'flooded', 'overflow', 'overflowing', 'over flow', 'over flowing', 'spewing',
        'sewage', 'sewer', 'raw sewage', 'backing up into', 'mold', 'mould',
        'smell of smoke', 'smoke coming', 'smoke in the', 'smoke inside', 'burning smell', 'burnt smell', 'smells like burning',
        'sparking', 'sparks', 'spark', 'exposed wire', 'exposed wiring', 'shock', 'shocked', 'melted', 'melting', 'smoking',
        'gas smell', 'smell gas', 'smells like gas', 'gas leak', 'carbon monoxide',
        'broken', 'cracked', 'fell off', 'fell out', 'fell down', 'came off', 'hanging', 'missing',
        'already tried', 'tried resetting', 'tried the reset', 'reset did not', 'reset didn\'t', 'reset does not', 'reset doesn\'t',
        'still not working after', 'keeps happening', 'again and again', 'getting worse', 'gotten worse',
        // A follow-up on an unserviced request is not a new request.
        'still haven\'t', 'still havent', 'still hasn\'t', 'still hasnt', 'still has not', 'still have not', 'not been serviced',
        'no one has come', 'nobody has come', 'no one came', 'nobody came', 'following up', 'follow up on', 'work orders',
        // Fitting, mounting or moving a part is a vendor job.
        'fitted', 'mounted', 'remount', 're-mount', 'relocate', 'relocated', 'moved to', 'move it to', 'falling apart', 'shorts out', 'short out', 'shorting',
    ],

    /*
    | A description longer than this is a list of several issues (a move-in
    | punch list, a follow-up on everything outstanding), not one small
    | request, so it never gets the one-item text. Recognised for the audit
    | and the AI chip only.
    */
    'max_words' => 80,

    /*
    | Easy-fix items. `pw_categories` are the PropertyWare Category values that
    | trigger the item on their own (only the unambiguous ones: a "Light
    | Fixture" category is a fixture repair, not a bulb). `label` is what the
    | messages call it ("your request about the {label}").
    */
    'items' => [
        [
            'key' => 'disposal_jammed',
            'label' => 'garbage disposal',
            'pw_categories' => ['Garbage Disposal'],
            'keywords' => ['garbage disposal', 'disposal', 'disposer', 'disposal unit'],
            'exclude' => ['dripping', 'drips', 'leak', 'leaks', 'replace the disposal', 'new disposal', 'disposal replaced', 'wiring', 'wire', 'wires', 'doorbell', 'smoke', 'smoking', 'burnt', 'burning'],
            'video_url' => null,
            'tip' => 'With the switch off, press the red reset button on the bottom of the disposal, then run cold water and try it again.',
        ],
        [
            'key' => 'light_bulb',
            'label' => 'light bulb',
            'pw_categories' => [],
            'keywords' => ['light bulb', 'light bulbs', 'bulb', 'bulbs', 'bulb out', 'bulb is out', 'bulbs are out', 'bulb burned out', 'bulb burnt out', 'bulb blew', 'bulb blown'],
            // "fixture" is deliberately not excluded: PropertyWare files a
            // bulb under the "Light Fixture" category, and that word is in
            // the scanned text. A bulb in the description is a bulb.
            'exclude' => ['flicker', 'flickering', 'flickers', 'wiring', 'wire', 'wires', 'ceiling fan', 'socket', 'switch', 'buzzing', 'humming', 'all the lights', 'every light', 'half the house', 'no lights', 'no power', 'fixture fell', 'fixture is loose', 'fixture loose', 'fixture hanging', 'fixture broke', 'fixture is broke'],
            'video_url' => null,
            'tip' => 'Any hardware or grocery store carries the same bulb - take the old one with you to match the base and wattage.',
        ],
        [
            'key' => 'smoke_detector_battery',
            'label' => 'smoke detector',
            'pw_categories' => ['Smoke Detectors', 'Smoke Detector', 'Smoke Alarm', 'Smoke Alarms'],
            'keywords' => ['smoke detector', 'smoke detectors', 'smoke alarm', 'smoke alarms', 'detector chirping', 'detector beeping', 'alarm chirping', 'chirping', 'chirps', 'detector battery', 'detector batteries'],
            'exclude' => ['hardwired', 'hard wired', 'hard-wired', 'co detector', 'co alarm', 'co2', 'alarm system', 'security alarm', 'keeps going off', 'going off', 'went off', 'fire', 'not fitted', 'isnt fitted', 'isn\'t fitted', 'correct spot', 'wrong spot', 'wrong place', 'install', 'installed', 'new detector', 'new smoke', 'replace the detector', 'replace the smoke'],
            'video_url' => null,
            'tip' => 'A chirp every minute or so means the battery is low - twist the cover off, swap in a fresh 9V (or AA, check the label) and press the test button.',
        ],
        [
            'key' => 'tripped_breaker',
            'label' => 'tripped breaker',
            'pw_categories' => [],
            'keywords' => ['breaker', 'breakers', 'tripped', 'trips', 'breaker box', 'circuit', 'no power in', 'no power to the', 'lost power in', 'power out in', 'outlets not working', 'outlet not working', 'outlets stopped working', 'outlet stopped working', 'plugs not working', 'plug not working'],
            // An appliance that trips the breaker is an appliance problem.
            'exclude' => ['keeps tripping', 'trips again', 'tripping again', 'keeps flipping', 'trips every', 'trips when', 'always trips', 'always gives', 'buzzing', 'hot to the touch', 'warm to the touch', 'burnt', 'burning', 'panel is hot', 'no power at all', 'no power in the house', 'no power to the house', 'whole house', 'entire house', 'entire home', 'whole home', 'power outage', 'microwave', 'oven', 'stove', 'range', 'dishwasher', 'washer', 'dryer', 'refrigerator', 'fridge', 'freezer', 'ac', 'a/c', 'air conditioner', 'water heater', 'garage door'],
            'video_url' => null,
            'tip' => 'At the breaker box, find the switch that sits between ON and OFF, push it fully OFF and then back ON.',
        ],
        [
            'key' => 'gfci_outlet',
            'label' => 'GFCI outlet',
            'pw_categories' => [],
            'keywords' => ['gfci', 'gfi', 'ground fault', 'bathroom outlet', 'bathroom outlets', 'kitchen outlet', 'kitchen outlets', 'garage outlet', 'garage outlets', 'outside outlet', 'outdoor outlet', 'patio outlet', 'outlet with the buttons', 'outlet with buttons', 'reset button on the outlet'],
            'exclude' => ['loose', 'hanging out', 'pulled out', 'burnt', 'burning', 'hot to the touch', 'buzzing', 'keeps tripping', 'trips again', 'tripping again', 'no power at all', 'whole house', 'entire house'],
            'video_url' => null,
            'tip' => 'Press RESET on the outlet with the two buttons (often in the bathroom, kitchen or garage) - it resets every outlet on that line.',
        ],
        [
            'key' => 'clogged_drain',
            'label' => 'clogged drain',
            'pw_categories' => ['Clogged Sink', 'Clogged Drain'],
            'keywords' => ['clogged sink', 'clogged drain', 'sink clogged', 'sink is clogged', 'drain clogged', 'drain is clogged', 'clogged tub', 'tub clogged', 'tub is clogged', 'clogged shower', 'shower clogged', 'shower drain', 'slow drain', 'slow draining', 'slow to drain', 'very slow to drain', 'draining slow', 'draining slowly', 'draining very slow', 'draining very slowly', 'drains slow', 'drains slowly', 'drains very slow', 'drains very slowly', 'not draining', 'won\'t drain', 'wont drain', 'will not drain', 'water is not going down', 'not going down', 'won\'t go down', 'wont go down', 'water sits in the sink', 'sink backed up', 'sink is backed up', 'tub backed up', 'clog', 'clogged'],
            'exclude' => ['toilet', 'main line', 'mainline', 'main drain', 'all drains', 'all the drains', 'every drain', 'multiple drains', 'both sinks', 'every sink', 'all sinks', 'gurgling', 'gurgle', 'snaked', 'snake', 'plumber', 'drano', 'draino', 'liquid plumber', 'tried', 'garbage disposal', 'disposal', 'water heater', 'washer', 'washing machine', 'dishwasher', 'dryer', 'ac', 'a/c', 'condensation', 'condensate', 'gutter', 'gutters', 'roof', 'downspout', 'yard', 'french drain', 'stopper', 'smells', 'smell', 'shower head', 'showerhead', 'faucet'],
            'video_url' => null,
            'tip' => 'A plunger or a cheap plastic drain snake from any hardware store clears most sink and tub clogs in a minute - please skip chemical drain cleaners, they damage the pipes.',
        ],
        [
            'key' => 'clogged_toilet',
            'label' => 'clogged toilet',
            'pw_categories' => [],
            'keywords' => ['toilet clogged', 'clogged toilet', 'toilet is clogged', 'toilet won\'t flush', 'toilet wont flush', 'toilet will not flush', 'toilet not flushing', 'toilet does not flush', 'toilet doesn\'t flush', 'toilet backed up', 'toilet is backed up', 'toilet stopped up', 'toilet is stopped up'],
            'exclude' => ['only toilet', 'one bathroom', 'both toilets', 'all toilets', 'every toilet', 'all the toilets', 'sewage', 'sewer', 'leak', 'leaks', 'water on the floor', 'base of the toilet', 'plunged', 'plunger did', 'tried plunging', 'tried a plunger', 'snaked', 'snake', 'gurgling', 'tub', 'shower'],
            'video_url' => null,
            'tip' => 'A flange plunger (the one with the extra flap) and a few firm pushes clears nearly every toilet clog.',
        ],
        [
            'key' => 'running_toilet',
            'label' => 'running toilet',
            'pw_categories' => [],
            'keywords' => ['toilet running', 'running toilet', 'toilet keeps running', 'toilet is running', 'toilet won\'t stop running', 'toilet wont stop running', 'toilet will not stop running', 'toilet keeps filling', 'toilet constantly running', 'toilet runs', 'flapper', 'toilet handle', 'flush handle', 'handle is loose', 'handle loose', 'toilet chain', 'fill valve'],
            'exclude' => ['leak', 'leaks', 'water on the floor', 'base of the toilet', 'around the toilet', 'wobbles', 'wobbly', 'loose from the floor', 'rocking', 'tank cracked', 'bowl cracked', 'sewage', 'overflow'],
            'video_url' => null,
            'tip' => 'Lift the tank lid and check the chain is hooked to the flapper and the flapper sits flat - nine times out of ten that is the whole fix.',
        ],
        [
            'key' => 'thermostat_batteries',
            'label' => 'thermostat',
            'pw_categories' => [],
            'keywords' => ['thermostat blank', 'thermostat is blank', 'thermostat went blank', 'thermostat screen', 'thermostat display', 'thermostat dead', 'thermostat is dead', 'thermostat not turning on', 'thermostat won\'t turn on', 'thermostat wont turn on', 'thermostat will not turn on', 'thermostat off', 'thermostat is off', 'thermostat battery', 'thermostat batteries', 'thermostat not working', 'thermostat isn\'t working', 'thermostat stopped working', 'thermostat unresponsive'],
            'exclude' => ['no ac', 'no a/c', 'no air', 'not cooling', 'not heating', 'no heat', 'no cool air', 'blowing hot', 'blowing warm', 'not blowing', 'ac not working', 'a/c not working', 'ac is not working', 'unit not working', 'unit is not working', 'outside unit', 'compressor', 'frozen', 'ice', 'wires', 'wiring', 'off the wall', 'hanging'],
            'video_url' => null,
            'tip' => 'Most thermostats take two AA batteries behind the faceplate - pull it straight off the wall, swap them, and the screen comes back.',
        ],
        [
            'key' => 'ac_filter',
            'label' => 'AC filter',
            'pw_categories' => [],
            'keywords' => ['air filter', 'air filters', 'ac filter', 'ac filters', 'a/c filter', 'a/c filters', 'hvac filter', 'furnace filter', 'filter change', 'change the filter', 'change filter', 'change filters', 'replace the filter', 'replace filter', 'replace filters', 'filter replacement', 'new filter', 'new filters', 'dirty filter', 'dirty filters', 'filter is dirty', 'filters are dirty', 'filter needs'],
            'exclude' => ['not cooling', 'no ac', 'no a/c', 'no air', 'not working', 'frozen', 'ice', 'leaking', 'water leaking', 'dripping', 'blowing hot', 'blowing warm', 'not blowing', 'water filter', 'fridge filter', 'refrigerator filter', 'pool filter', 'compressor', 'outside unit', 'cover', 'grill', 'grille', 'grid', 'vent', 'return', 'falling', 'falls', 'fell', 'magnet', 'magnets', 'sink', 'shower', 'toilet', 'drain'],
            'video_url' => null,
            'tip' => 'The size is printed on the edge of the old filter - any hardware store carries it, and the arrow on the new one points toward the unit.',
        ],
    ],

    /*
    | Tenant-owned appliances. A request about one of these is the tenant's
    | responsibility when the property's PropertyWare "Included Appliances"
    | field is filled in and does NOT list it. A property whose field is blank
    | or never synced is left alone (flagged for staff, nobody messaged).
    | `included_needles` are matched against that field after any
    | "connections/hookups" wording is stripped, so "washer/dryer connections"
    | never reads as a washer being included.
    */
    'appliances' => [
        [
            'key' => 'appliance_washer',
            'label' => 'washer',
            'keywords' => ['washer', 'washers', 'washing machine', 'washing machines', 'clothes washer', 'laundry machine'],
            'exclude' => ['hookup', 'hookups', 'hook up', 'hook-up', 'connection', 'connections', 'valve', 'valves', 'standpipe', 'drain pipe', 'drain line', 'water line', 'supply line', 'faucet', 'spigot', 'outlet', 'breaker', 'no power', 'behind the washer', 'wall behind', 'pressure washer', 'power washer', 'dishwasher'],
            'included_needles' => ['washer', 'washing machine', 'washer and dryer', 'washer/dryer', 'w/d'],
        ],
        [
            'key' => 'appliance_dryer',
            'label' => 'dryer',
            'keywords' => ['dryer', 'dryers', 'clothes dryer', 'tumble dryer'],
            'exclude' => ['vent', 'vents', 'venting', 'duct', 'ducts', 'ductwork', 'outlet', 'breaker', 'no power', 'gas line', 'gas', 'hookup', 'hookups', 'hook up', 'hook-up', 'connection', 'connections', 'hair dryer', 'behind the dryer', 'wall behind'],
            'included_needles' => ['dryer', 'washer and dryer', 'washer/dryer', 'w/d'],
        ],
        [
            'key' => 'appliance_refrigerator',
            'label' => 'refrigerator',
            'keywords' => ['refrigerator', 'refrigerators', 'fridge', 'freezer', 'ice maker', 'icemaker'],
            'exclude' => ['water line', 'supply line', 'outlet', 'breaker', 'no power', 'behind the fridge', 'behind the refrigerator', 'wall behind', 'wine fridge', 'mini fridge', 'garage fridge', 'garage refrigerator'],
            'included_needles' => ['refrigerator', 'fridge', 'refrig'],
        ],
    ],

    /*
    | "Included Appliances" values that mean the property provides nothing.
    */
    'no_appliances_values' => ['none', 'n/a', 'na', 'no', 'not applicable', 'nothing', '-'],
];
