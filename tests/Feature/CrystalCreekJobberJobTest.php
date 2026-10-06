<?php

namespace Tests\Feature;

use App\Jobs\CreateJobberJobForWorkOrder;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberToken;
use App\Models\OutsideCustomer;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The Jobber job for a Crystal Creek Air work order: a property under the
 * "Crystal Creek Air, LLC" client, created when the customer is new and
 * reused when they are not.
 */
class CrystalCreekJobberJobTest extends TestCase
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
            'services.jobber.crystal_creek_client_gid' => 'GID-CCA-CLIENT',
        ]);

        JobberToken::query()->create([
            'access_token' => 'test-token',
            'refresh_token' => 'r',
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function makeWorkOrder(array $customer = []): WorkOrder
    {
        $customer = OutsideCustomer::factory()->create(array_merge([
            'name' => 'Pat Customer',
            'phone' => '+15125550100',
            'email' => 'pat@example.com',
            'street' => '1234 Oak St',
            'city' => 'Cypress',
            'state' => 'TX',
            'postal_code' => '77429',
        ], $customer));

        return WorkOrder::factory()->create([
            'work_order_no' => 7000001,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'category' => 'HVAC',
            'description' => "AC not   cooling\nsince Monday",
            'location' => $customer->oneLineAddress(),
            'building_id' => null,
            'tenant_id' => null,
        ]);
    }

    /** Fake propertyCreate and jobCreate; anything else is unexpected. */
    private function fakeJobber(): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) {
                $body = (string) $request->body();

                if (str_contains($body, 'propertyCreate')) {
                    return Http::response(['data' => ['propertyCreate' => [
                        'properties' => [['id' => 'GID-PROP-NEW']],
                        'userErrors' => [],
                    ]]]);
                }

                if (str_contains($body, 'jobCreate')) {
                    return Http::response(['data' => ['jobCreate' => [
                        'job' => ['id' => 'GID-JOB-777', 'jobberWebUri' => 'https://secure.getjobber.com/work_orders/777'],
                        'userErrors' => [],
                    ]]]);
                }

                return Http::response(['errors' => [['message' => 'unexpected query']]], 400);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function sentVariables(string $mutation): array
    {
        $vars = [];

        Http::assertSent(function ($request) use ($mutation, &$vars) {
            $body = (string) $request->body();
            if (! str_contains($body, $mutation)) {
                return false;
            }
            $vars = json_decode($body, true)['variables'] ?? [];

            return true;
        });

        return $vars;
    }

    public function test_a_new_customer_gets_a_property_under_the_crystal_creek_client_then_the_job(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $property = $this->sentVariables('propertyCreate');
        $this->assertSame('GID-CCA-CLIENT', $property['clientId']);
        $this->assertSame([
            'street1' => '1234 Oak St',
            'city' => 'Cypress',
            'province' => 'TX',
            'postalCode' => '77429',
            'country' => 'US',
        ], $property['input']['properties'][0]['address']);

        $job = $this->sentVariables('jobCreate')['input'];
        $this->assertSame('GID-PROP-NEW', $job['propertyId']);
        $this->assertSame('Pat Customer - 1234 Oak St - HVAC - #7000001', $job['title']);
        $this->assertSame(
            'AC not cooling since Monday - Pat Customer - (512) 555-0100 - pat@example.com - 1234 Oak St, Cypress, TX 77429',
            $job['instructions']
        );
        $this->assertSame(['GID-THMP-USER'], $job['scheduling']['assignedTo']);

        $workOrder->refresh();
        $this->assertSame('GID-JOB-777', $workOrder->jobber_job_gid);
        $this->assertSame('https://secure.getjobber.com/work_orders/777', $workOrder->jobber_web_uri);
        $this->assertSame('GID-PROP-NEW', $workOrder->outsideCustomer->jobber_property_gid);
        $this->assertSame('GID-CCA-CLIENT', $workOrder->outsideCustomer->jobber_client_gid);
    }

    public function test_a_returning_customer_reuses_their_property_and_no_property_is_created(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder(['jobber_property_gid' => 'GID-PROP-OLD', 'jobber_client_gid' => 'GID-CCA-CLIENT']);

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'propertyCreate'));
        $this->assertSame('GID-PROP-OLD', $this->sentVariables('jobCreate')['input']['propertyId']);
        $this->assertSame('GID-JOB-777', $workOrder->fresh()->jobber_job_gid);
    }

    public function test_a_property_the_office_already_made_in_jobber_at_the_same_address_is_reused(): void
    {
        $this->fakeJobber();

        $client = JobberClient::query()->create([
            'jobber_id' => 'GID-CCA-CLIENT',
            'name' => 'Crystal Creek Air, LLC',
            'jobber_web_uri' => 'https://secure.getjobber.com/clients/1',
        ]);
        JobberProperty::query()->create([
            'jobber_id' => 'GID-PROP-HANDMADE',
            'jobber_client_id' => $client->id,
            'street' => '1234 Oak St ',
            'city' => 'Cypress',
            'province' => 'TX',
            'postal_code' => '77429',
        ]);

        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'propertyCreate'));
        $this->assertSame('GID-PROP-HANDMADE', $this->sentVariables('jobCreate')['input']['propertyId']);
        $this->assertSame('GID-PROP-HANDMADE', $workOrder->fresh()->outsideCustomer->jobber_property_gid);
    }

    public function test_the_client_is_found_in_the_mirror_when_not_configured(): void
    {
        config(['services.jobber.crystal_creek_client_gid' => null]);
        JobberClient::query()->create([
            'jobber_id' => 'GID-CCA-FROM-MIRROR',
            'name' => 'Crystal Creek Air, LLC',
            'company_name' => 'Crystal Creek Air, LLC',
            'jobber_web_uri' => 'https://secure.getjobber.com/clients/1',
        ]);
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertSame('GID-CCA-FROM-MIRROR', $this->sentVariables('propertyCreate')['clientId']);
    }

    public function test_an_unknown_client_throws_so_the_queued_job_retries_instead_of_going_quiet(): void
    {
        config(['services.jobber.crystal_creek_client_gid' => null]);
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        $this->expectException(RuntimeException::class);

        try {
            CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);
        } finally {
            Http::assertNothingSent();
            $this->assertNull($workOrder->fresh()->jobber_job_gid);
        }
    }

    public function test_a_refused_property_stores_no_link(): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) {
                if (str_contains((string) $request->body(), 'propertyCreate')) {
                    return Http::response(['data' => ['propertyCreate' => [
                        'properties' => [],
                        'userErrors' => [['message' => 'Street is required', 'path' => ['address', 'street1']]],
                    ]]]);
                }

                return Http::response(['errors' => [['message' => 'unexpected query']]], 400);
            },
        ]);
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'jobCreate'));
        $this->assertNull($workOrder->fresh()->jobber_job_gid);
        $this->assertNull($workOrder->fresh()->outsideCustomer->jobber_property_gid);
    }

    public function test_user_errors_on_the_job_store_no_link_but_keep_the_property(): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) {
                $body = (string) $request->body();

                if (str_contains($body, 'propertyCreate')) {
                    return Http::response(['data' => ['propertyCreate' => [
                        'properties' => [['id' => 'GID-PROP-NEW']],
                        'userErrors' => [],
                    ]]]);
                }

                return Http::response(['data' => ['jobCreate' => [
                    'job' => null,
                    'userErrors' => [['message' => 'Property is archived']],
                ]]]);
            },
        ]);
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertNull($workOrder->fresh()->jobber_job_gid);
        // The property exists in Jobber now; the retry must not create a second one.
        $this->assertSame('GID-PROP-NEW', $workOrder->fresh()->outsideCustomer->jobber_property_gid);
    }

    public function test_the_feature_gate_off_makes_no_jobber_call(): void
    {
        config(['services.jobber.job_create_enabled' => false]);
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        Http::assertNothingSent();
    }
}
