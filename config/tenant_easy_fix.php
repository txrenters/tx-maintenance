<?php

/*
|--------------------------------------------------------------------------
| Tenant easy-fix library
|--------------------------------------------------------------------------
|
| The Maintenance handbook's "TENANT EASY-FIX" sheet (26 rows, 2026-09-23),
| one entry per row, plus the tenant-owned appliances the landlord is not
| responsible for. Read by App\Ai\TenantEasyFixCriteria, which decides at
| intake whether a new work order gets the "here is how to fix it yourself"
| text (with the handbook's how-to video) instead of the generic "we received
| your request".
|
| Matching is deliberately conservative: a work order must hit an item's
| PropertyWare category or one of its keyword phrases, and must NOT contain
| any exclusion phrase (the item's own, or the global ones below, which are
| the signs that the same symptom is really a leak, a hazard or a broken part
| a vendor has to see). The emergency criteria always win.
|
| An item with no `video_url` is recognised (for the audit and the AI chip on
| the work order) but never texted about: the tenant message is built around
| the video, so it must not go out without one. Rows whose handbook link is a
| YouTube SEARCH page rather than a video, and rows that overlap the
| emergency rules (AC/heat out, furnace out, gas pilot), are left without a
| link on purpose until operations picks a video / gives the go.
|
| `tip` is the handbook's "Tenant can try first" column, verbatim.
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
        // A damaged part, a hole, or "needs to be repaired/replaced" is a
        // vendor job whatever else the description mentions (a technician's
        // inspection note about AC ductwork also mentioned an exhaust fan).
        'damaged', 'damage to', 'hole in', 'holes in', 'needs to be repaired', 'needs to be replaced', 'needs repair', 'needs replacing', 'needs replacement', 'recommend assessing', 'recommend replacing',
    ],

    /*
    | A description longer than this is a list of several issues (a move-in
    | punch list, a follow-up on everything outstanding), not one small
    | request, so it never gets the one-item text. Recognised for the audit
    | and the AI chip only.
    */
    'max_words' => 80,

    /*
    | Easy-fix items, in the handbook's row order. `pw_categories` are the
    | PropertyWare Category values that trigger the item on their own (only
    | the unambiguous ones). `label` is what the messages call it ("your
    | request about the {label}").
    */
    'items' => [
        [
            // Handbook row 1
            'key' => 'gfci_outlet',
            'label' => 'outlet',
            'pw_categories' => [],
            'keywords' => ['outlet not working', 'outlets not working', 'outlet stopped working', 'outlets stopped working', 'outlet is dead', 'outlets are dead', 'outlet has no power', 'outlets have no power', 'no power to the outlet', 'no power to the outlets', 'no power at the outlet', 'outlet is not working', 'outlets are not working', 'plug not working', 'plugs not working', 'gfci', 'gfi', 'ground fault', 'bathroom outlet', 'bathroom outlets', 'kitchen outlet', 'kitchen outlets', 'garage outlet', 'garage outlets', 'outside outlet', 'outdoor outlet', 'patio outlet', 'outlet with the buttons', 'outlet with buttons', 'reset button on the outlet'],
            'exclude' => ['loose', 'hanging out', 'pulled out', 'burnt', 'burning', 'hot to the touch', 'buzzing', 'keeps tripping', 'trips again', 'tripping again', 'no power at all', 'whole house', 'entire house', 'entire home', 'whole home', 'all outlets', 'all the outlets', 'every outlet', 'microwave', 'oven', 'stove', 'range', 'dishwasher', 'washer', 'dryer', 'refrigerator', 'fridge', 'freezer', 'ac', 'a/c', 'air conditioner', 'water heater', 'garage door'],
            'video_url' => 'https://www.youtube.com/watch?v=OUR0GBrDmSg',
            'tip' => 'Check/reset GFCI',
        ],
        [
            // Handbook row 2
            'key' => 'tripped_breaker',
            'label' => 'tripped breaker',
            'pw_categories' => [],
            'keywords' => ['breaker', 'breakers', 'tripped', 'trips', 'breaker box', 'circuit', 'no power in', 'no power to the', 'lost power in', 'power out in', 'power went out in', 'part of the house', 'half the house', 'half of the house', 'one side of the house', 'some of the lights', 'lights and outlets', 'power lost to'],
            // An appliance that trips the breaker is an appliance problem.
            'exclude' => ['keeps tripping', 'trips again', 'tripping again', 'keeps flipping', 'trips every', 'trips when', 'always trips', 'always gives', 'buzzing', 'hot to the touch', 'warm to the touch', 'burnt', 'burning', 'panel is hot', 'no power at all', 'no power in the house', 'no power to the house', 'whole house', 'entire house', 'entire home', 'whole home', 'power outage', 'microwave', 'oven', 'stove', 'range', 'dishwasher', 'washer', 'dryer', 'refrigerator', 'fridge', 'freezer', 'ac', 'a/c', 'air conditioner', 'water heater', 'garage door'],
            'video_url' => 'https://www.youtube.com/watch?v=Lb2UhcnK7n8',
            'tip' => 'Check/reset tripped breaker',
        ],
        [
            // Handbook row 3 - the handbook links a YouTube search page, not a
            // video, so this is recognised but not texted until a video is picked.
            'key' => 'light_bulb',
            'label' => 'light bulb',
            'pw_categories' => [],
            'keywords' => ['light bulb', 'light bulbs', 'bulb', 'bulbs', 'bulb out', 'bulb is out', 'bulbs are out', 'bulb burned out', 'bulb burnt out', 'bulb blew', 'bulb blown'],
            // "fixture" is deliberately not excluded: PropertyWare files a
            // bulb under the "Light Fixture" category, and that word is in
            // the scanned text. A bulb in the description is a bulb.
            'exclude' => ['flicker', 'flickering', 'flickers', 'wiring', 'wire', 'wires', 'ceiling fan', 'socket', 'switch', 'buzzing', 'humming', 'all the lights', 'every light', 'half the house', 'no lights', 'no power', 'fixture fell', 'fixture is loose', 'fixture loose', 'fixture hanging', 'fixture broke', 'fixture is broke'],
            'video_url' => null,
            'tip' => 'Replace bulb with correct type/wattage',
        ],
        [
            // Handbook row 4
            'key' => 'faucet_aerator',
            'label' => 'faucet',
            'pw_categories' => [],
            'keywords' => ['low water pressure', 'low pressure', 'water pressure is low', 'pressure is low', 'low flow', 'faucet flow', 'weak flow', 'weak pressure', 'barely any water', 'water comes out slow', 'water comes out slowly', 'just a trickle', 'trickle', 'trickles', 'aerator', 'faucet screen'],
            'exclude' => ['no water', 'whole house', 'entire house', 'all faucets', 'every faucet', 'all the faucets', 'shower', 'tub', 'toilet', 'leak', 'leaks', 'dripping', 'drips', 'hot water', 'water heater', 'main', 'street', 'city'],
            'video_url' => 'https://www.youtube.com/watch?v=2l8STssse5w',
            'tip' => 'Remove and clean faucet aerator/screen',
        ],
        [
            // Handbook row 5
            'key' => 'disposal_not_working',
            'label' => 'garbage disposal',
            'pw_categories' => ['Garbage Disposal'],
            'keywords' => ['garbage disposal', 'disposal', 'disposer', 'disposal unit'],
            // A jammed or humming one is row 6.
            'exclude' => ['dripping', 'drips', 'leak', 'leaks', 'replace the disposal', 'new disposal', 'disposal replaced', 'wiring', 'wire', 'wires', 'doorbell', 'smoke', 'smoking', 'burnt', 'burning', 'jammed', 'jam', 'stuck', 'humming', 'hums', 'grinding', 'rattling'],
            'video_url' => 'https://www.youtube.com/watch?v=hV_hCdDFQs8',
            'tip' => 'Check GFCI/breaker and press disposal reset button',
        ],
        [
            // Handbook row 6
            'key' => 'disposal_jammed',
            'label' => 'garbage disposal',
            'pw_categories' => [],
            'keywords' => ['disposal jammed', 'disposal is jammed', 'jammed disposal', 'disposal jams', 'disposal stuck', 'disposal is stuck', 'disposal got stuck', 'disposal humming', 'disposal hums', 'disposal is humming', 'disposal just hums', 'disposal makes a humming', 'disposal only hums', 'stuck in the disposal', 'stuck in the garbage disposal', 'stuck in disposal', 'something in the disposal', 'something in the garbage disposal', 'disposal won\'t turn', 'disposal wont turn', 'disposal not turning', 'disposal is not turning', 'disposal blades', 'disposal grinding', 'disposal is grinding', 'disposal rattling', 'jammed garbage disposal', 'garbage disposal jammed', 'garbage disposal is jammed', 'garbage disposal stuck', 'garbage disposal is stuck', 'garbage disposal humming', 'garbage disposal is humming', 'garbage disposal hums'],
            'exclude' => ['dripping', 'drips', 'leak', 'leaks', 'replace the disposal', 'new disposal', 'disposal replaced', 'wiring', 'wire', 'wires', 'doorbell', 'smoke', 'smoking', 'burnt', 'burning'],
            'video_url' => 'https://www.youtube.com/watch?app=desktop&v=J0OByRuoYM0',
            'tip' => 'Only use the manufacturer\'s safe reset/un-jamming procedure; never put hands inside',
        ],
        [
            // Handbook row 7
            'key' => 'smoke_detector_battery',
            'label' => 'smoke detector',
            'pw_categories' => ['Smoke Detectors', 'Smoke Detector', 'Smoke Alarm', 'Smoke Alarms'],
            'keywords' => ['smoke detector', 'smoke detectors', 'smoke alarm', 'smoke alarms', 'co detector', 'carbon monoxide detector', 'detector chirping', 'detector beeping', 'alarm chirping', 'chirping', 'chirps', 'detector battery', 'detector batteries'],
            'exclude' => ['hardwired', 'hard wired', 'hard-wired', 'alarm system', 'security alarm', 'keeps going off', 'going off', 'went off', 'fire', 'not fitted', 'isnt fitted', 'isn\'t fitted', 'correct spot', 'wrong spot', 'wrong place', 'install', 'installed', 'new detector', 'new smoke', 'replace the detector', 'replace the smoke'],
            'video_url' => 'https://www.youtube.com/watch?v=qSPENd-XTPo',
            'tip' => 'Replace batteries and test detector',
        ],
        [
            // Handbook row 8
            'key' => 'thermostat_batteries',
            'label' => 'thermostat',
            'pw_categories' => [],
            'keywords' => ['thermostat blank', 'thermostat is blank', 'thermostat went blank', 'thermostat screen', 'thermostat display', 'thermostat dead', 'thermostat is dead', 'thermostat not turning on', 'thermostat won\'t turn on', 'thermostat wont turn on', 'thermostat will not turn on', 'thermostat off', 'thermostat is off', 'thermostat battery', 'thermostat batteries', 'thermostat not working', 'thermostat isn\'t working', 'thermostat stopped working', 'thermostat unresponsive'],
            'exclude' => ['no ac', 'no a/c', 'no air', 'not cooling', 'not heating', 'no heat', 'no cool air', 'blowing hot', 'blowing warm', 'not blowing', 'ac not working', 'a/c not working', 'ac is not working', 'unit not working', 'unit is not working', 'outside unit', 'compressor', 'frozen', 'ice', 'wires', 'wiring', 'off the wall'],
            'video_url' => 'https://www.youtube.com/watch?v=QFd5jGL6vpM',
            'tip' => 'Replace thermostat batteries',
        ],
        [
            // Handbook row 9 - gas appliance and a search-page link: recognised
            // only, never texted, until operations says otherwise.
            'key' => 'water_heater_pilot',
            'label' => 'water heater pilot light',
            'pw_categories' => [],
            'keywords' => ['pilot light', 'pilot is out', 'pilot went out', 'pilot out', 'pilot light is out', 'pilot light went out', 'relight the pilot', 'relight', 're-light'],
            'exclude' => ['gas', 'smell', 'leak', 'leaking', 'no hot water in the house'],
            'video_url' => null,
            'tip' => 'Only if the specific equipment/manual permits tenant relighting',
        ],
        [
            // Handbook row 10 - overlaps the emergency rules (no AC in a Houston
            // summer, no heat in a freeze): recognised only until operations
            // gives the go. Handbook video: https://www.youtube.com/watch?v=IKySBZ9esWQ
            'key' => 'ac_heat_not_working',
            'label' => 'AC or heat',
            'pw_categories' => [],
            // AC/heat context required: a bare "not heating" is a dryer or an
            // oven as often as a furnace.
            'keywords' => ['ac not working', 'a/c not working', 'ac is not working', 'a/c is not working', 'ac stopped working', 'a/c stopped working', 'ac not cooling', 'a/c not cooling', 'ac is not cooling', 'a/c is not cooling', 'air conditioner not cooling', 'air conditioning not cooling', 'house not cooling', 'house is not cooling', 'not cooling the house', 'ac blowing warm', 'ac blowing hot', 'a/c blowing warm', 'a/c blowing hot', 'ac is blowing warm', 'ac is blowing hot', 'not blowing cold air', 'no cold air', 'ac not turning on', 'ac won\'t turn on', 'ac wont turn on', 'a/c not turning on', 'heat not working', 'heater not working', 'heat is not working', 'heater is not working', 'heater not heating', 'heat not coming on', 'heat won\'t come on', 'heat wont come on', 'not heating the house', 'house not heating'],
            'exclude' => ['frozen', 'ice', 'iced', 'leaking', 'water', 'outside unit', 'compressor', 'noise', 'loud', 'smell', 'burning', 'smoke', 'oven', 'stove', 'dryer', 'washer', 'water heater', 'fridge', 'refrigerator', 'dishwasher', 'pool', 'spa'],
            'video_url' => null,
            'tip' => 'Check thermostat, filter, breaker and power; replace dirty filter',
        ],
        [
            // Handbook row 11
            'key' => 'hvac_filter',
            'label' => 'HVAC filter',
            'pw_categories' => [],
            'keywords' => ['air filter', 'air filters', 'ac filter', 'ac filters', 'a/c filter', 'a/c filters', 'hvac filter', 'hvac filters', 'furnace filter', 'filter change', 'change the filter', 'change filter', 'change filters', 'replace the filter', 'replace filter', 'replace filters', 'filter replacement', 'new filter', 'new filters', 'dirty filter', 'dirty filters', 'filter is dirty', 'filters are dirty', 'filter needs', 'blocked filter', 'clogged filter'],
            'exclude' => ['not cooling', 'no ac', 'no a/c', 'no air', 'not working', 'frozen', 'ice', 'leaking', 'water leaking', 'dripping', 'blowing hot', 'blowing warm', 'not blowing', 'water filter', 'fridge filter', 'refrigerator filter', 'pool filter', 'compressor', 'outside unit', 'cover', 'grill', 'grille', 'grid', 'vent', 'return', 'falling', 'falls', 'fell', 'magnet', 'magnets', 'sink', 'shower', 'toilet', 'drain'],
            'video_url' => 'https://www.youtube.com/watch?v=IKySBZ9esWQ',
            'tip' => 'Replace filter with correct size',
        ],
        [
            // Handbook row 12
            'key' => 'clogged_toilet',
            'label' => 'toilet',
            'pw_categories' => [],
            'keywords' => ['toilet clogged', 'clogged toilet', 'toilet is clogged', 'toilet won\'t flush', 'toilet wont flush', 'toilet will not flush', 'toilet not flushing', 'toilet does not flush', 'toilet doesn\'t flush', 'toilet backed up', 'toilet is backed up', 'toilet stopped up', 'toilet is stopped up', 'toilet is blocked', 'toilet blocked'],
            'exclude' => ['only toilet', 'one bathroom', 'both toilets', 'all toilets', 'every toilet', 'all the toilets', 'sewage', 'sewer', 'leak', 'leaks', 'water on the floor', 'base of the toilet', 'plunged', 'plunger did', 'tried plunging', 'tried a plunger', 'snaked', 'snake', 'gurgling', 'tub', 'shower', 'sink'],
            'video_url' => 'https://www.youtube.com/watch?v=RTbxnNRkqnA',
            'tip' => 'Use a toilet plunger properly',
        ],
        [
            // Handbook row 13
            'key' => 'clogged_sink',
            'label' => 'sink drain',
            'pw_categories' => ['Clogged Sink'],
            'keywords' => ['clogged sink', 'sink clogged', 'sink is clogged', 'sink drain', 'sink is slow', 'sink draining slow', 'sink draining slowly', 'sink drains slow', 'sink drains slowly', 'sink is draining slow', 'sink is draining slowly', 'sink not draining', 'sink won\'t drain', 'sink wont drain', 'sink will not drain', 'sink backed up', 'sink is backed up', 'sink is stopped up', 'sink stopped up', 'water sits in the sink', 'sink is blocked', 'sink blocked', 'slow drain', 'slow draining', 'slow to drain', 'draining slow', 'draining slowly', 'draining very slow', 'draining very slowly', 'not draining', 'won\'t drain', 'wont drain', 'water is not going down', 'not going down', 'clog', 'clogged'],
            'exclude' => ['toilet', 'tub', 'bathtub', 'shower', 'main line', 'mainline', 'main drain', 'all drains', 'all the drains', 'every drain', 'multiple drains', 'both sinks', 'every sink', 'all sinks', 'gurgling', 'gurgle', 'snaked', 'snake', 'plumber', 'drano', 'draino', 'liquid plumber', 'tried', 'garbage disposal', 'disposal', 'water heater', 'washer', 'washing machine', 'dishwasher', 'dryer', 'ac', 'a/c', 'condensation', 'condensate', 'gutter', 'gutters', 'roof', 'downspout', 'yard', 'french drain', 'stopper', 'smells', 'smell', 'shower head', 'showerhead', 'faucet'],
            'video_url' => 'https://www.youtube.com/watch?v=4u0sMRKb0NY',
            'tip' => 'Remove visible debris and plunge/clear simple clog',
        ],
        [
            // Handbook row 14
            'key' => 'slow_shower_drain',
            'label' => 'shower or tub drain',
            'pw_categories' => [],
            'keywords' => ['shower drain', 'tub drain', 'bathtub drain', 'shower draining slow', 'shower draining slowly', 'shower is draining slow', 'shower is draining slowly', 'tub draining slow', 'tub draining slowly', 'tub is draining slow', 'tub is draining slowly', 'shower drains slow', 'shower drains slowly', 'tub drains slow', 'tub drains slowly', 'shower not draining', 'tub not draining', 'shower won\'t drain', 'shower wont drain', 'tub won\'t drain', 'tub wont drain', 'shower clogged', 'shower is clogged', 'tub clogged', 'tub is clogged', 'bathtub clogged', 'bathtub is clogged', 'clogged shower', 'clogged tub', 'clogged bathtub', 'water pooling in the shower', 'standing water in the shower', 'standing water in the tub', 'water sits in the tub', 'water sits in the shower', 'hair in the drain', 'shower backed up', 'tub backed up', 'shower is backed up', 'tub is backed up'],
            'exclude' => ['toilet', 'sink', 'main line', 'mainline', 'main drain', 'all drains', 'all the drains', 'every drain', 'multiple drains', 'gurgling', 'gurgle', 'snaked', 'snake', 'plumber', 'drano', 'draino', 'tried', 'sewage', 'shower head', 'showerhead', 'faucet', 'no water', 'hot water', 'tile', 'grout', 'caulk', 'smell', 'smells'],
            'video_url' => 'https://www.youtube.com/watch?v=qCElivlac8k',
            'tip' => 'Remove hair/debris from drain',
        ],
        [
            // Handbook row 15 - overlaps the emergency rules (no heat in a
            // freeze): recognised only until operations gives the go.
            // Handbook video: https://www.youtube.com/watch?v=XyYlQm0xVBY
            'key' => 'furnace_not_working',
            'label' => 'furnace',
            'pw_categories' => [],
            'keywords' => ['furnace not working', 'furnace is not working', 'furnace won\'t', 'furnace wont', 'furnace not turning on', 'furnace stopped', 'furnace won\'t come on', 'furnace wont come on', 'heater not turning on', 'heater won\'t turn on', 'heater wont turn on', 'heat won\'t come on', 'heat wont come on', 'heat will not come on', 'furnace'],
            'exclude' => ['gas smell', 'smell', 'burning', 'smoke', 'carbon monoxide', 'noise', 'loud', 'banging'],
            'video_url' => null,
            'tip' => 'Confirm payment for natural gas bill/Reset Furnace',
        ],
        [
            // Handbook row 16 (first video; the handbook's second, descaling,
            // is https://www.youtube.com/watch?v=s7bBbrcIpaE)
            'key' => 'dishwasher_not_cleaning',
            'label' => 'dishwasher',
            'pw_categories' => [],
            'keywords' => ['dishwasher not cleaning', 'dishwasher is not cleaning', 'dishwasher isn\'t cleaning', 'dishwasher doesn\'t clean', 'dishwasher does not clean', 'dishwasher not washing', 'dishwasher is not washing', 'dishes come out dirty', 'dishes are still dirty', 'dishes still dirty', 'dishes are dirty', 'dishes not clean', 'dishes not getting clean', 'dishes aren\'t clean', 'film on the dishes', 'residue on the dishes', 'dishwasher leaves'],
            'exclude' => ['leak', 'leaks', 'not draining', 'won\'t drain', 'wont drain', 'standing water', 'water in the bottom', 'not starting', 'won\'t start', 'wont start', 'won\'t turn on', 'wont turn on', 'no power', 'error code', 'burning', 'smoke', 'smell', 'door'],
            'video_url' => 'https://youtu.be/btbHjqDTWLM',
            'tip' => 'Clean filter; descale',
        ],
        [
            // Handbook row 17
            'key' => 'dishwasher_not_draining',
            'label' => 'dishwasher',
            'pw_categories' => [],
            'keywords' => ['dishwasher not draining', 'dishwasher is not draining', 'dishwasher won\'t drain', 'dishwasher wont drain', 'dishwasher does not drain', 'dishwasher doesn\'t drain', 'dishwasher isn\'t draining', 'water in the bottom of the dishwasher', 'standing water in the dishwasher', 'water left in the dishwasher', 'dishwasher full of water', 'dishwasher has water', 'dishwasher is full of water', 'water sitting in the dishwasher', 'dishwasher backed up'],
            'exclude' => ['leak', 'leaks', 'not starting', 'won\'t start', 'wont start', 'error code', 'burning', 'smoke', 'smell', 'door'],
            'video_url' => 'https://www.youtube.com/watch?v=tajBDbFkDDQ',
            'tip' => 'Check/clean filter and make sure sink/disposal drain is clear',
        ],
        [
            // Handbook row 18
            'key' => 'dishwasher_not_starting',
            'label' => 'dishwasher',
            'pw_categories' => [],
            'keywords' => ['dishwasher not starting', 'dishwasher is not starting', 'dishwasher won\'t start', 'dishwasher wont start', 'dishwasher does not start', 'dishwasher doesn\'t start', 'dishwasher not turning on', 'dishwasher won\'t turn on', 'dishwasher wont turn on', 'dishwasher is dead', 'dishwasher has no power', 'dishwasher no power', 'dishwasher not working', 'dishwasher is not working', 'dishwasher isn\'t working', 'dishwasher stopped working', 'dishwasher won\'t run', 'dishwasher wont run', 'dishwasher not running'],
            'exclude' => ['leak', 'leaks', 'not draining', 'won\'t drain', 'wont drain', 'error code', 'burning', 'smoke', 'smell', 'door won\'t close', 'door wont close', 'door does not close', 'latch'],
            'video_url' => 'https://www.youtube.com/watch?v=GGTueTi33dg',
            'tip' => 'Make sure door is fully latched; check breaker/GFCI',
        ],
        [
            // Handbook row 19
            'key' => 'running_toilet',
            'label' => 'running toilet',
            'pw_categories' => [],
            'keywords' => ['toilet running', 'running toilet', 'toilet keeps running', 'toilet is running', 'toilet won\'t stop running', 'toilet wont stop running', 'toilet will not stop running', 'toilet keeps filling', 'toilet constantly running', 'toilet runs', 'toilet tank running', 'tank keeps running', 'tank keeps filling', 'flapper', 'toilet handle', 'flush handle', 'handle is loose', 'handle loose', 'handle is stuck', 'handle stuck', 'toilet chain', 'flush chain', 'fill valve'],
            'exclude' => ['leak', 'leaks', 'water on the floor', 'base of the toilet', 'around the toilet', 'wobbles', 'wobbly', 'loose from the floor', 'rocking', 'tank cracked', 'bowl cracked', 'sewage', 'overflow', 'clogged'],
            'video_url' => 'https://www.youtube.com/watch?v=DoqzGyC92GQ',
            'tip' => 'Check flapper, flush chain and make sure handle isn\'t stuck',
        ],
        [
            // Handbook row 20 - only reached when the refrigerator is
            // property-provided (a tenant-owned one gets the responsibility text).
            'key' => 'refrigerator_not_cooling',
            'label' => 'refrigerator',
            'pw_categories' => [],
            'keywords' => ['refrigerator not cooling', 'fridge not cooling', 'refrigerator is not cooling', 'fridge is not cooling', 'refrigerator isn\'t cooling', 'fridge isn\'t cooling', 'refrigerator not cold', 'fridge not cold', 'refrigerator is not cold', 'fridge is not cold', 'refrigerator warm', 'fridge warm', 'fridge is warm', 'refrigerator is warm', 'refrigerator stopped cooling', 'fridge stopped cooling', 'refrigerator not working', 'fridge not working', 'refrigerator is not working', 'fridge is not working', 'refrigerator stopped working', 'fridge stopped working', 'refrigerator won\'t turn on', 'fridge won\'t turn on', 'refrigerator wont turn on', 'fridge wont turn on', 'fridge not turning on', 'refrigerator not turning on', 'freezer not freezing', 'freezer not cold', 'freezer is not cold', 'freezer not working', 'fridge is dead', 'refrigerator is dead', 'fridge has no power', 'refrigerator has no power'],
            'exclude' => ['leak', 'leaks', 'water on the floor', 'ice maker', 'icemaker', 'water line', 'water dispenser', 'noise', 'loud', 'buzzing', 'smell', 'burning', 'door won\'t close', 'door wont close', 'seal', 'gasket', 'compressor'],
            'video_url' => 'https://www.youtube.com/watch?v=Xj2jEaL_D_w',
            'tip' => 'Check power, plug, breaker/GFCI and temperature setting',
        ],
        [
            // Handbook row 21 (video supplied by Earl 2026-09-23; the
            // sheet's own link was a search page).
            'key' => 'dryer_not_heating',
            'label' => 'dryer',
            'pw_categories' => [],
            'keywords' => ['dryer not heating', 'dryer is not heating', 'dryer isn\'t heating', 'dryer not drying', 'dryer is not drying', 'dryer isn\'t drying', 'dryer no heat', 'dryer has no heat', 'dryer not getting hot', 'dryer runs but', 'clothes still wet', 'clothes come out wet', 'clothes are still wet', 'clothes come out damp', 'dryer takes forever', 'dryer takes hours', 'dryer takes several cycles'],
            'exclude' => ['burning', 'smoke', 'smell', 'vent', 'venting', 'duct', 'gas', 'not starting', 'won\'t start', 'wont start', 'no power', 'not turning on', 'won\'t turn on', 'wont turn on', 'noise', 'squeal', 'squealing', 'thump', 'thumping', 'banging'],
            'video_url' => 'https://youtu.be/umSXSNeNPf0',
            'tip' => 'Clean lint filter, confirm proper settings and avoid overloading',
        ],
        [
            // Handbook row 22 (video supplied by Earl 2026-09-23; the
            // sheet's own link was a search page).
            'key' => 'washer_not_starting',
            'label' => 'washer',
            'pw_categories' => [],
            'keywords' => ['washer not starting', 'washer is not starting', 'washer won\'t start', 'washer wont start', 'washing machine not starting', 'washing machine won\'t start', 'washing machine wont start', 'washer not turning on', 'washer won\'t turn on', 'washer wont turn on', 'washing machine not turning on', 'washing machine won\'t turn on', 'washing machine wont turn on', 'washer has no power', 'washer no power', 'washer is dead', 'washer not working', 'washer is not working', 'washing machine not working', 'washing machine is not working', 'washer stopped working', 'washing machine stopped working', 'washer won\'t fill', 'washer wont fill', 'washer not filling', 'washer is not filling', 'no water coming into the washer', 'no water going into the washer'],
            'exclude' => ['leak', 'leaks', 'water on the floor', 'not draining', 'won\'t drain', 'wont drain', 'not spinning', 'won\'t spin', 'wont spin', 'noise', 'banging', 'shaking', 'smell', 'burning', 'smoke', 'hookup', 'hookups', 'hook up', 'hook-up', 'valve', 'valves', 'standpipe'],
            'video_url' => 'https://youtu.be/YdWuLw15xhk',
            'tip' => 'Check door/lid, power, breaker and water supply',
        ],
        [
            // Handbook row 23 (video supplied by Earl 2026-09-23; the
            // sheet's own link was a search page).
            'key' => 'garage_door',
            'label' => 'garage door',
            'pw_categories' => [],
            'keywords' => ['garage door not working', 'garage door is not working', 'garage door won\'t open', 'garage door wont open', 'garage door won\'t close', 'garage door wont close', 'garage door will not open', 'garage door will not close', 'garage door opener', 'garage door remote', 'garage remote', 'garage door not opening', 'garage door not closing', 'garage door stopped', 'garage door is stuck', 'garage door stuck', 'garage door won\'t go', 'garage door wont go', 'garage door sensor', 'garage door sensors', 'garage door keypad', 'garage door button'],
            'exclude' => ['off track', 'off the track', 'off its track', 'spring', 'springs', 'cable', 'cables', 'bent', 'dent', 'dented', 'crashed', 'hit the door', 'backed into', 'ran into', 'panel', 'panels', 'fell', 'roller', 'rollers', 'noise', 'loud', 'grinding'],
            'video_url' => 'https://youtu.be/E0je1HfGyaM',
            'tip' => 'Check opener power, remote batteries and safety sensors',
        ],
        [
            // Handbook row 24 (video supplied by Earl 2026-09-23; the
            // sheet's own link was a search page).
            'key' => 'water_shutoff_valve',
            'label' => 'water shutoff valve',
            'pw_categories' => [],
            'keywords' => ['no water to the toilet', 'no water to the sink', 'no water to toilet', 'no water to sink', 'toilet won\'t fill', 'toilet wont fill', 'toilet not filling', 'toilet is not filling', 'toilet tank not filling', 'toilet tank won\'t fill', 'toilet tank wont fill', 'no water in the toilet', 'no water in the tank', 'sink has no water', 'no water from the faucet', 'no water coming out of the faucet', 'no water from the sink', 'faucet has no water', 'shutoff valve', 'shut off valve', 'shut-off valve', 'supply valve', 'valve under the sink', 'valve behind the toilet'],
            'exclude' => ['no water in the house', 'no water at all', 'whole house', 'entire house', 'entire home', 'whole home', 'leak', 'leaks', 'leaking', 'burst', 'hot water', 'water heater', 'shower', 'tub'],
            'video_url' => 'https://youtu.be/L-cTL7JJr8E',
            'tip' => 'Check that local shutoff valve hasn\'t been accidentally closed',
        ],
        [
            // Handbook row 25
            'key' => 'bathroom_fan',
            'label' => 'bathroom exhaust fan',
            'pw_categories' => [],
            'keywords' => ['bathroom fan', 'bathroom exhaust fan', 'exhaust fan', 'bath fan', 'vent fan', 'bathroom vent', 'fan is noisy', 'fan is loud', 'fan dirty', 'fan is dirty', 'fan makes noise', 'fan makes a noise', 'fan rattles', 'fan rattling', 'fan is rattling', 'dusty fan', 'fan full of dust'],
            'exclude' => ['ceiling fan', 'not working', 'is not working', 'stopped working', 'won\'t turn on', 'wont turn on', 'doesn\'t turn on', 'does not turn on', 'not turning on', 'no power', 'burning', 'smoke', 'smell', 'fell', 'kitchen', 'stove', 'range hood', 'hood', 'replacing', 'replace', 'replaced', 'install', 'installing', 'installed', 'duct', 'ducts', 'ductwork', 'draft'],
            'video_url' => 'https://www.youtube.com/watch?v=9G_z_6OF3hU',
            'tip' => 'Clean visible dust/debris from grille',
        ],
        [
            // Handbook row 26 - the handbook's link for this row is the bathroom
            // exhaust fan search page (copied from row 25), so no video until it
            // is corrected; recognised, not texted.
            'key' => 'stove_burner',
            'label' => 'stove burner',
            'pw_categories' => [],
            'keywords' => ['burner won\'t light', 'burner wont light', 'burner will not light', 'burner not lighting', 'burner won\'t ignite', 'burner wont ignite', 'burner not igniting', 'burner doesn\'t light', 'burner does not light', 'burner isn\'t lighting', 'igniter clicks', 'igniter keeps clicking', 'keeps clicking', 'clicking but', 'clicks but', 'stove burner', 'burner not working', 'burner is not working', 'one burner', 'one of the burners', 'burner won\'t turn on', 'burner wont turn on', 'burner won\'t come on', 'burner wont come on'],
            'exclude' => ['all burners', 'all the burners', 'none of the burners', 'no burners', 'oven', 'electric', 'glass top', 'coil', 'coils', 'element', 'smell', 'gas'],
            'video_url' => null,
            'tip' => 'Check burner cap placement and clean food/debris around igniter',
        ],
    ],

    /*
    | Tenant-owned appliances. A request about one of these is the tenant's
    | responsibility when the property's PropertyWare "Included Appliances"
    | field is filled in and does NOT list it; when it is listed (or the field
    | is blank), the request falls through to the handbook rows above (rows
    | 20-22). `included_needles` are matched against that field after any
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

    /*
    | "Included Appliances" values that are placeholders, not an answer:
    | PropertyWare's default "Not Completed" is the single most common value
    | (218 of the buildings synced locally), and "Yes" says nothing. They are
    | treated the same as a blank field: ownership unknown, nobody is told
    | the appliance is theirs.
    */
    'unknown_appliances_values' => ['not completed', 'not complete', 'incomplete', 'yes', 'tbd', 'to be determined', 'unknown', 'update', 'pending', 'see lease', 'per lease', '?', '—'],
];
