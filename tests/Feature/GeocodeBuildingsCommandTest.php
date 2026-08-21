<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Services\GeocodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeBuildingsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function building(array $attributes = []): Building
    {
        static $id = 770001;

        return Building::query()->create(array_merge([
            'propertyware_id' => $id++,
            'name' => 'Meadow Breeze House',
            'address' => '12026 Meadow Breeze Dr',
            'city' => 'Cypress',
            'state_region' => 'TX',
            'postal_code' => '77429',
            'active' => true,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function censusMatch(float $lat = 29.968934, float $lng = -95.697215): array
    {
        return ['result' => ['addressMatches' => [[
            'matchedAddress' => '12026 MEADOW BREEZE DR, CYPRESS, TX, 77429',
            'coordinates' => ['x' => $lng, 'y' => $lat],
        ]]]];
    }

    public function test_a_match_saves_coordinates_and_stamps(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response($this->censusMatch())]);

        $building = $this->building();

        $this->artisan('geocode:buildings')->assertSuccessful();

        $building->refresh();
        // Census answers GIS-style: y is latitude, x is longitude.
        $this->assertEqualsWithDelta(29.968934, $building->latitude, 0.000001);
        $this->assertEqualsWithDelta(-95.697215, $building->longitude, 0.000001);
        $this->assertNotNull($building->geocoded_at);
        $this->assertSame(GeocodeService::assembleAddress($building), $building->geocoded_address);
    }

    public function test_a_no_match_is_marked_tried_with_null_coordinates(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response(['result' => ['addressMatches' => []]])]);

        $building = $this->building();

        $this->artisan('geocode:buildings')->assertSuccessful();

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
        $this->assertNotNull($building->geocoded_at);
    }

    public function test_a_transport_failure_leaves_the_row_untouched(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response('oops', 500)]);

        $building = $this->building();

        $this->artisan('geocode:buildings')->assertSuccessful();

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->geocoded_at, 'A failed request must stay retryable.');
    }

    public function test_a_waf_block_page_is_a_failure_not_a_no_match(): void
    {
        Http::fake([
            'geocoding.geo.census.gov/*' => Http::response(
                '<html><head><title>Request Rejected</title></head><body>The requested URL was rejected.</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $building = $this->building();

        $this->artisan('geocode:buildings')->assertSuccessful();

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->geocoded_at, 'A rate-limit block page must not be stamped as a definitive miss.');
    }

    public function test_consecutive_failures_abort_the_run_early(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response('oops', 500)]);

        for ($i = 0; $i < 15; $i++) {
            $this->building(['name' => "House {$i}"]);
        }

        $this->artisan('geocode:buildings')
            ->expectsOutputToContain('Aborted early')
            ->assertSuccessful();

        // 10 failures trip the breaker; the remaining 5 buildings are never
        // attempted. Each attempt makes 3 tries via the retry() wrapper.
        Http::assertSentCount(30);
    }

    public function test_an_unchanged_geocoded_building_sends_no_request(): void
    {
        Http::fake();

        $building = $this->building();
        $building->update([
            'latitude' => 29.9,
            'longitude' => -95.6,
            'geocoded_address' => GeocodeService::assembleAddress($building),
            'geocoded_at' => now()->subDays(90),
        ]);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSentCount(0);
    }

    public function test_a_changed_address_is_geocoded_again(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response($this->censusMatch(30.1, -95.1))]);

        $building = $this->building();
        $building->update([
            'latitude' => 29.9,
            'longitude' => -95.6,
            'geocoded_address' => '99 Old Address St, Cypress, TX 77429',
            'geocoded_at' => now()->subDay(),
        ]);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertEqualsWithDelta(30.1, $building->refresh()->latitude, 0.000001);
    }

    public function test_a_fresh_miss_is_skipped_but_an_old_miss_is_retried(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response($this->censusMatch())]);

        $freshMiss = $this->building(['name' => 'Fresh Miss']);
        $freshMiss->update([
            'geocoded_address' => GeocodeService::assembleAddress($freshMiss),
            'geocoded_at' => now()->subDays(5),
        ]);

        $oldMiss = $this->building(['name' => 'Old Miss', 'address' => '99 New Subdivision Way']);
        $oldMiss->update([
            'geocoded_address' => GeocodeService::assembleAddress($oldMiss),
            'geocoded_at' => now()->subDays(31),
        ]);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertNull($freshMiss->refresh()->latitude);
        $this->assertNotNull($oldMiss->refresh()->latitude);
    }

    public function test_force_geocodes_everything_again(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response($this->censusMatch())]);

        $building = $this->building();
        $building->update([
            'latitude' => 29.9,
            'longitude' => -95.6,
            'geocoded_address' => GeocodeService::assembleAddress($building),
            'geocoded_at' => now()->subDay(),
        ]);

        $this->artisan('geocode:buildings --force')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_dry_run_sends_nothing_and_writes_nothing(): void
    {
        Http::fake();

        $building = $this->building();

        $this->artisan('geocode:buildings --dry-run')
            ->expectsOutputToContain('would geocode Meadow Breeze House')
            ->assertSuccessful();

        Http::assertSentCount(0);
        $this->assertNull($building->refresh()->geocoded_at);
    }

    public function test_po_box_and_blank_addresses_are_marked_without_a_request(): void
    {
        Http::fake();

        $poBox = $this->building(['name' => 'PO Box House', 'address' => 'P.O. Box 123']);
        $blank = $this->building(['name' => 'Blank House', 'address' => '']);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSentCount(0);
        $this->assertNotNull($poBox->refresh()->geocoded_at);
        $this->assertNotNull($blank->refresh()->geocoded_at);
    }

    public function test_inactive_buildings_are_ignored(): void
    {
        Http::fake();

        $this->building(['active' => false]);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSentCount(0);
    }

    public function test_a_one_line_address_with_blank_city_is_parsed_into_parts(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response($this->censusMatch())]);

        $building = $this->building([
            'address' => '6341 Del Monte Dr, Houston, TX 77057',
            'city' => null,
            'state_region' => null,
            'postal_code' => null,
        ]);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'street=6341%20Del%20Monte%20Dr')
                && str_contains($request->url(), 'city=Houston')
                && str_contains($request->url(), 'zip=77057');
        });
        $this->assertSame(
            '6341 Del Monte Dr, Houston, TX 77057',
            $building->refresh()->geocoded_address
        );
    }

    public function test_zip_plus_four_is_truncated_and_a_missing_zip_is_omitted(): void
    {
        Http::fake(['geocoding.geo.census.gov/*' => Http::response($this->censusMatch())]);

        $this->building(['postal_code' => '77429-1234']);
        $this->building(['name' => 'No Zip House', 'postal_code' => null]);

        $this->artisan('geocode:buildings')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'zip=77429')
                && ! str_contains($request->url(), '77429-1234');
        });
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'zip='));
    }
}
