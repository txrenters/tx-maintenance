<?php

namespace Tests\Feature;

use App\Exceptions\JobberReconnectRequiredException;
use App\Jobs\CreateJobberJobForWorkOrder;
use App\Models\Building;
use App\Models\JobberToken;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class JobberJobCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jobber.job_create_enabled' => true,
            'services.jobber.api_version' => '2026-03-10',
            'services.jobber.graphql_url' => 'https://api.getjobber.com/api/graphql',
            'services.jobber.thmp_assignee_gid' => 'GID-THMP-USER',
        ]);

        // expires_at is set so the token service uses the stored token as-is
        // instead of refreshing before every call.
        JobberToken::query()->create([
            'access_token' => 'test-token',
            'refresh_token' => 'r',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function makeWorkOrder(string $buildingName = '6341 Del Monte Dr'): WorkOrder
    {
        $building = Building::query()->create([
            'propertyware_id' => 'PW-BLDG-1',
            'name' => $buildingName,
            'address' => $buildingName,
            'postal_code' => '77057',
        ]);

        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'mobile_phone' => '5125559999',
            'user_id' => User::factory()->create()->id,
        ]);

        return WorkOrder::factory()->create([
            'work_order_no' => 43361,
            'building_id' => 'PW-BLDG-1',
            'zone' => '2',
            'category' => 'Plumbing',
            'description' => "Water   dripping\nfrom the roof",
            'tenant_id' => $tenant->id,
        ]);
    }

    /** Fake both Jobber calls: property search then the jobCreate mutation. */
    private function fakeJobber(string $clientName = '6341 Del Monte Drive LLC'): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) use ($clientName) {
                $body = (string) $request->body();

                if (str_contains($body, 'jobCreate')) {
                    return Http::response(['data' => ['jobCreate' => [
                        'job' => ['id' => 'GID-JOB-999', 'jobberWebUri' => 'https://secure.getjobber.com/work_orders/999'],
                        'userErrors' => [],
                    ]]]);
                }

                return Http::response(['data' => ['properties' => ['edges' => [
                    ['node' => ['id' => 'GID-PROP-1', 'client' => ['name' => $clientName]]],
                ]]]]);
            },
        ]);
    }

    public function test_it_creates_the_job_and_stores_the_link(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $workOrder->refresh();
        $this->assertSame('GID-JOB-999', $workOrder->jobber_job_gid);
        $this->assertSame('https://secure.getjobber.com/work_orders/999', $workOrder->jobber_web_uri);
    }

    public function test_the_mutation_carries_the_expected_title_instructions_and_property(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();
            if (! str_contains($body, 'jobCreate')) {
                return false;
            }
            $vars = json_decode($body, true)['variables']['input'] ?? [];

            // mobile_phone is normalized to a leading country code by the Tenants model.
            return ($vars['propertyId'] ?? null) === 'GID-PROP-1'
                && ($vars['title'] ?? null) === '6341 Del Monte Dr - Zone 2 - Plumbing - #43361'
                && ($vars['instructions'] ?? null) === 'Water dripping from the roof - Dana Tenant - 15125559999'
                && ($vars['scheduling']['assignedTo'] ?? null) === ['GID-THMP-USER'];
        });
    }

    public function test_the_feature_gate_off_makes_no_jobber_call(): void
    {
        config(['services.jobber.job_create_enabled' => false]);
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertNothingSent();
        $this->assertNull($workOrder->fresh()->jobber_job_gid);
    }

    public function test_an_already_linked_work_order_is_skipped(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();
        $workOrder->update(['jobber_job_gid' => 'GID-EXISTING']);

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertNothingSent();
        $this->assertSame('GID-EXISTING', $workOrder->fresh()->jobber_job_gid);
    }

    public function test_no_matching_property_stores_no_link(): void
    {
        // Client name that does not match the building's first two words.
        $this->fakeJobber('815 Hollow Tree LLC');
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        // Property search happened, but no jobCreate and no link saved.
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'properties'));
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'jobCreate'));
        $this->assertNull($workOrder->fresh()->jobber_job_gid);
    }

    public function test_user_errors_from_jobber_store_no_link(): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) {
                if (str_contains((string) $request->body(), 'jobCreate')) {
                    return Http::response(['data' => ['jobCreate' => [
                        'job' => null,
                        'userErrors' => [['message' => 'Property is archived']],
                    ]]]);
                }

                return Http::response(['data' => ['properties' => ['edges' => [
                    ['node' => ['id' => 'GID-PROP-1', 'client' => ['name' => '6341 Del Monte Drive LLC']]],
                ]]]]);
            },
        ]);

        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertNull($workOrder->fresh()->jobber_job_gid);
    }

    public function test_a_401_triggers_a_token_refresh_and_replay_that_succeeds(): void
    {
        $oauthCalls = 0;

        Http::fake(function ($request) use (&$oauthCalls) {
            if (str_contains($request->url(), 'oauth/token')) {
                $oauthCalls++;

                return Http::response([
                    'access_token' => 'fresh-token',
                    'refresh_token' => 'fresh-refresh',
                    'expires_in' => 3600,
                ]);
            }

            // The stale token is rejected until the refresh swaps it out.
            if (($request->header('Authorization')[0] ?? '') !== 'Bearer fresh-token') {
                return Http::response([], 401);
            }

            if (str_contains((string) $request->body(), 'jobCreate')) {
                return Http::response(['data' => ['jobCreate' => [
                    'job' => ['id' => 'GID-JOB-999', 'jobberWebUri' => 'https://secure.getjobber.com/work_orders/999'],
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['data' => ['properties' => ['edges' => [
                ['node' => ['id' => 'GID-PROP-1', 'client' => ['name' => '6341 Del Monte Drive LLC']]],
            ]]]]);
        });

        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertSame('GID-JOB-999', $workOrder->fresh()->jobber_job_gid);
        $this->assertSame(1, $oauthCalls);

        // The rotated token pair was persisted.
        $token = JobberToken::query()->first();
        $this->assertSame('fresh-token', $token->access_token);
        $this->assertSame('fresh-refresh', $token->refresh_token);
    }

    public function test_a_dead_refresh_token_flags_reconnect_and_alerts_exactly_once(): void
    {
        $oauthCalls = 0;

        Http::fake(function ($request) use (&$oauthCalls) {
            if (str_contains($request->url(), 'oauth/token')) {
                $oauthCalls++;

                return Http::response(['error' => 'invalid_grant'], 401);
            }

            return Http::response([], 401);
        });

        $workOrder = $this->makeWorkOrder();

        // First run: GraphQL 401 -> refresh attempt 401 -> flagged dead + thrown
        // so the queued job fails and retries instead of silently giving up.
        try {
            CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);
            $this->fail('Expected JobberReconnectRequiredException was not thrown.');
        } catch (JobberReconnectRequiredException) {
        }

        $this->assertTrue(Cache::has('jobber:needs-reconnect'));
        $this->assertSame(1, Activity::query()->where('event', 'jobber_reconnect_required')->count());
        $this->assertSame(1, $oauthCalls);

        // Second run fails fast: no new refresh attempt, no duplicate alert.
        try {
            CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);
            $this->fail('Expected JobberReconnectRequiredException was not thrown.');
        } catch (JobberReconnectRequiredException) {
        }

        $this->assertSame(1, $oauthCalls);
        $this->assertSame(1, Activity::query()->where('event', 'jobber_reconnect_required')->count());
        $this->assertNull($workOrder->fresh()->jobber_job_gid);
    }
}
