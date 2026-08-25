<?php

namespace Tests\Feature;

use App\Models\JobberProperty;
use App\Models\JobberToken;
use App\Models\JobberVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class JobberWebhookOptionalFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.jobber.client_secret' => self::SECRET]);

        JobberToken::query()->create([
            'access_token' => 'stored-token',
            'refresh_token' => 'stored-refresh',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function postWebhook(string $topic, string $itemId): TestResponse
    {
        $payload = ['data' => ['webHookEvent' => ['topic' => $topic, 'itemId' => $itemId]]];
        $signature = base64_encode(hash_hmac('sha256', json_encode($payload), self::SECRET, true));

        return $this->withHeaders(['X-Jobber-Hmac-SHA256' => $signature])
            ->postJson('/api/jobber/webhook', $payload);
    }

    /**
     * A visit as the visit(id:) query returns it, with its job, client and
     * property inline.
     *
     * @param  array<string, mixed>  $address
     * @param  array<string, mixed>|null  $assignedUsers
     * @return array<string, mixed>
     */
    private function visitPayload(array $address, ?array $assignedUsers): array
    {
        $visit = [
            'id' => 'visit-hook-1',
            'title' => 'Zone 2 - Move out inspection',
            'visitStatus' => 'ACTIVE',
            'duration' => 0,
            'instructions' => null,
            'startAt' => '2026-08-24T00:00:00-05:00',
            'endAt' => null,
            'completedAt' => null,
            'job' => [
                'id' => 'job-hook-1',
                'jobNumber' => 20003,
                'title' => 'Zone 2 - Move out inspection',
                'jobStatus' => 'active',
                'jobType' => 'ONE_OFF',
                'total' => 0,
                'willClientBeAutomaticallyCharged' => false,
                'instructions' => null,
                'jobberWebUri' => 'https://secure.getjobber.com/jobs/20003',
                'bookingConfirmationSentAt' => null,
                'startAt' => null,
                'endAt' => null,
                'completedAt' => null,
                'createdAt' => null,
                'updatedAt' => null,
            ],
            'client' => [
                'id' => 'client-hook-1',
                'firstName' => 'Pat',
                'lastName' => 'Owner',
                'companyName' => null,
                'name' => 'Pat Owner',
                'secondaryName' => null,
                'title' => null,
                'balance' => 0,
                'jobberWebUri' => 'https://secure.getjobber.com/clients/1',
                'emails' => [],
            ],
            'property' => [
                'id' => 'property-hook-1',
                'isBillingAddress' => false,
                'jobberWebUri' => 'https://secure.getjobber.com/properties/1',
                'address' => $address,
            ],
        ];

        if ($assignedUsers !== null) {
            $visit['assignedUsers'] = $assignedUsers;
        }

        return ['data' => ['visit' => $visit]];
    }

    public function test_a_visit_webhook_stores_assignees_and_property_coordinates(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response($this->visitPayload(
            [
                'street' => '21227 Teal Lovegrass Ln',
                'city' => 'Cypress',
                'province' => 'TX',
                'postalCode' => '77433',
                'country' => 'USA',
                'coordinates' => ['latitude' => 29.95, 'longitude' => -95.75],
            ],
            ['nodes' => [['id' => 'gid://Jobber/User/42', 'name' => ['full' => 'Jimmie Gendke']]]]
        ))]);

        $this->postWebhook('VISIT_CREATE', 'visit-hook-1')->assertOk();

        Http::assertSent(function ($request) {
            $query = $request->data()['query'];

            return str_contains($query, 'assignedUsers(first: 2)')
                && str_contains($query, 'coordinates { latitude longitude }');
        });

        $property = JobberProperty::query()->where('jobber_id', 'property-hook-1')->firstOrFail();
        $this->assertSame('21227 Teal Lovegrass Ln', $property->street);
        $this->assertEqualsWithDelta(29.95, $property->latitude, 0.000001);
        $this->assertEqualsWithDelta(-95.75, $property->longitude, 0.000001);

        $visit = JobberVisit::query()->where('jobber_id', 'visit-hook-1')->firstOrFail();
        $this->assertSame([['id' => 'gid://Jobber/User/42', 'name' => 'Jimmie Gendke']], $visit->assigned_to);
    }

    public function test_a_rejected_optional_field_is_retried_without_and_the_webhook_still_lands(): void
    {
        Http::fakeSequence('api.getjobber.com/api/graphql')
            ->push(['errors' => [['message' => "Field 'coordinates' doesn't exist on type 'PropertyAddress'"]]])
            ->push($this->visitPayload(
                ['street' => '21227 Teal Lovegrass Ln', 'city' => 'Cypress', 'province' => 'TX', 'postalCode' => '77433', 'country' => 'USA'],
                ['nodes' => []]
            ));

        $this->postWebhook('VISIT_CREATE', 'visit-hook-1')->assertOk();

        $queries = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['query']);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('coordinates {', $queries[0]);
        $this->assertStringNotContainsString('coordinates', $queries[1], 'The retry leaves the rejected field out.');

        $property = JobberProperty::query()->where('jobber_id', 'property-hook-1')->firstOrFail();
        $this->assertNull($property->latitude);
        $this->assertTrue(JobberVisit::query()->where('jobber_id', 'visit-hook-1')->exists(), 'The visit still imports.');
    }

    public function test_a_bad_signature_is_rejected_before_any_jobber_call(): void
    {
        Http::fake();

        $this->withHeaders(['X-Jobber-Hmac-SHA256' => 'nope'])
            ->postJson('/api/jobber/webhook', ['data' => ['webHookEvent' => ['topic' => 'VISIT_CREATE', 'itemId' => 'visit-hook-1']]])
            ->assertStatus(401);

        Http::assertSentCount(0);
    }
}
