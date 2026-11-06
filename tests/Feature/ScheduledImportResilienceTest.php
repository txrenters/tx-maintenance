<?php

namespace Tests\Feature;

use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A PropertyWare outage must cost the scheduled importers one quiet run, not
 * a crash. Until 2026-11 both fetches returned the string "Error: ..." on
 * failure, which the commands then count()ed — a TypeError that killed the
 * whole run eight times in October 2026 on PropertyWare 500s and Bad Gateways.
 */
class ScheduledImportResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_soap_failure_yields_an_empty_list_not_an_error_string(): void
    {
        $service = $this->partialMock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('initiate')->once()->andThrow(new \Exception('Bad Gateway'));
        });

        Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'SOAP request failed: Bad Gateway'));

        $this->assertSame([], $service->getWorkOrders());
    }

    public function test_a_rest_failure_yields_an_empty_list_not_an_error_string(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Resolving timed out'));

        Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'REST API request failed'));

        $this->assertSame([], app(PropertyWareService::class)->getWorkOrdersViaRestAPI());
    }

    public function test_the_fast_lane_survives_a_propertyware_outage(): void
    {
        $this->partialMock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('initiate')->once()->andThrow(new \Exception('Internal Server Error'));
        });

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once()->with('No work orders returned from Propertyware API.');

        $this->artisan('import:work-orders')->assertExitCode(0);
    }

    public function test_the_status_sync_survives_a_propertyware_outage(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Resolving timed out'));

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once()->with('No work orders returned from Propertyware API.');

        $this->artisan('update:work-orders-status')->assertExitCode(0);
    }
}
