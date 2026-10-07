<?php

namespace Tests\Feature;

use App\Jobs\CreateJobberJobForWorkOrder;
use App\Models\JobberToken;
use App\Models\OutsideCustomer;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Jobber job for a Crystal Creek Air work order: the outside customer
 * becomes their own Jobber client with their home as its property, created
 * on the first job and reused on every later one.
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
            'work_order_no' => 7,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'category' => 'HVAC',
            'type' => 'Capacitor Replacement',
            'description' => "AC not   cooling\nsince Monday",
            'location' => $customer->oneLineAddress(),
            'building_id' => null,
            'tenant_id' => null,
        ]);
    }

    /**
     * Fake clientCreate, propertyCreate and jobCreate; anything else is
     * unexpected. $clientProperties is what clientCreate answers under
     * client.properties.
     *
     * @param  array<int, array{id: string}>  $clientProperties
     */
    private function fakeJobber(array $clientProperties = [['id' => 'GID-PROP-NEW']]): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) use ($clientProperties) {
                $body = (string) $request->body();

                if (str_contains($body, 'clientCreate')) {
                    return Http::response(['data' => ['clientCreate' => [
                        'client' => ['id' => 'GID-CLIENT-NEW', 'properties' => $clientProperties],
                        'userErrors' => [],
                    ]]]);
                }

                if (str_contains($body, 'propertyCreate')) {
                    return Http::response(['data' => ['propertyCreate' => [
                        'properties' => [['id' => 'GID-PROP-LATER']],
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

    private function assertNotSent(string $mutation): void
    {
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), $mutation));
    }

    public function test_a_new_customer_becomes_their_own_client_with_their_home_then_the_job(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $client = $this->sentVariables('clientCreate')['input'];
        $this->assertSame('Pat', $client['firstName']);
        $this->assertSame('Customer', $client['lastName']);
        $this->assertSame([['description' => 'MAIN', 'primary' => true, 'number' => '+15125550100']], $client['phones']);
        $this->assertSame([['description' => 'MAIN', 'primary' => true, 'address' => 'pat@example.com']], $client['emails']);
        $this->assertSame([
            'street1' => '1234 Oak St',
            'city' => 'Cypress',
            'province' => 'TX',
            'postalCode' => '77429',
            'country' => 'US',
        ], $client['properties'][0]['address']);
        $this->assertNotSent('propertyCreate');

        $job = $this->sentVariables('jobCreate')['input'];
        $this->assertSame('GID-PROP-NEW', $job['propertyId']);
        $this->assertSame('Pat Customer - 1234 Oak St - Capacitor Replacement - #7', $job['title']);
        $this->assertSame(
            'HVAC - AC not cooling since Monday - Pat Customer - (512) 555-0100 - pat@example.com - 1234 Oak St, Cypress, TX 77429',
            $job['instructions']
        );
        $this->assertSame(['GID-THMP-USER'], $job['scheduling']['assignedTo']);

        $workOrder->refresh();
        $this->assertSame('GID-JOB-777', $workOrder->jobber_job_gid);
        $this->assertSame('https://secure.getjobber.com/work_orders/777', $workOrder->jobber_web_uri);
        $this->assertSame('GID-CLIENT-NEW', $workOrder->outsideCustomer->jobber_client_gid);
        $this->assertSame('GID-PROP-NEW', $workOrder->outsideCustomer->jobber_property_gid);
    }

    public function test_a_one_word_name_and_no_email_send_only_what_is_known(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder(['name' => 'Cher', 'email' => null]);

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $client = $this->sentVariables('clientCreate')['input'];
        $this->assertSame('Cher', $client['firstName']);
        $this->assertArrayNotHasKey('lastName', $client);
        $this->assertArrayNotHasKey('emails', $client);
        $this->assertSame('GID-JOB-777', $workOrder->fresh()->jobber_job_gid);
    }

    public function test_a_returning_customer_reuses_their_client_and_property(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder(['jobber_client_gid' => 'GID-CLIENT-OLD', 'jobber_property_gid' => 'GID-PROP-OLD']);

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertNotSent('clientCreate');
        $this->assertNotSent('propertyCreate');
        $this->assertSame('GID-PROP-OLD', $this->sentVariables('jobCreate')['input']['propertyId']);
        $this->assertSame('GID-JOB-777', $workOrder->fresh()->jobber_job_gid);
    }

    public function test_a_customer_whose_client_exists_but_has_no_property_gets_one_under_it(): void
    {
        $this->fakeJobber();
        $workOrder = $this->makeWorkOrder(['jobber_client_gid' => 'GID-CLIENT-OLD', 'jobber_property_gid' => null]);

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertNotSent('clientCreate');
        $this->assertSame('GID-CLIENT-OLD', $this->sentVariables('propertyCreate')['clientId']);
        $this->assertSame('GID-PROP-LATER', $this->sentVariables('jobCreate')['input']['propertyId']);
        $this->assertSame('GID-PROP-LATER', $workOrder->fresh()->outsideCustomer->jobber_property_gid);
    }

    public function test_a_client_created_without_a_property_gets_one_before_the_job(): void
    {
        $this->fakeJobber(clientProperties: []);
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertSame('GID-CLIENT-NEW', $this->sentVariables('propertyCreate')['clientId']);
        $this->assertSame('GID-PROP-LATER', $this->sentVariables('jobCreate')['input']['propertyId']);
        $customer = $workOrder->fresh()->outsideCustomer;
        $this->assertSame('GID-CLIENT-NEW', $customer->jobber_client_gid);
        $this->assertSame('GID-PROP-LATER', $customer->jobber_property_gid);
    }

    public function test_a_refused_client_stores_no_link_and_makes_no_job(): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) {
                if (str_contains((string) $request->body(), 'clientCreate')) {
                    return Http::response(['data' => ['clientCreate' => [
                        'client' => null,
                        'userErrors' => [['message' => 'First name is required', 'path' => ['firstName']]],
                    ]]]);
                }

                return Http::response(['errors' => [['message' => 'unexpected query']]], 400);
            },
        ]);
        $workOrder = $this->makeWorkOrder();

        CreateJobberJobForWorkOrder::dispatchSync($workOrder->id);

        $this->assertNotSent('jobCreate');
        $this->assertNull($workOrder->fresh()->jobber_job_gid);
        $this->assertNull($workOrder->fresh()->outsideCustomer->jobber_client_gid);
        $this->assertNull($workOrder->fresh()->outsideCustomer->jobber_property_gid);
    }

    public function test_user_errors_on_the_job_store_no_link_but_keep_the_client_and_property(): void
    {
        Http::fake([
            'api.getjobber.com/*' => function ($request) {
                $body = (string) $request->body();

                if (str_contains($body, 'clientCreate')) {
                    return Http::response(['data' => ['clientCreate' => [
                        'client' => ['id' => 'GID-CLIENT-NEW', 'properties' => [['id' => 'GID-PROP-NEW']]],
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
        // The client and property exist in Jobber now; a retry must not make a second pair.
        $this->assertSame('GID-CLIENT-NEW', $workOrder->fresh()->outsideCustomer->jobber_client_gid);
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
