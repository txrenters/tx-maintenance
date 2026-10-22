<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberTextMessage;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The work order's Tenant tab also lists the tenant's texts with the THMP
 * number - TBP notices and replies on Jobber jobs, and this work order's
 * vendor-tenant thread - read-only, for staff (2026-10-23: staff could not
 * find Rikki's TBP replies from WO #41454).
 */
class TenantThmpTextsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_PHONE = '+13468236941';

    private const THMP_PHONE = '+12816999281';

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function workOrderForTenant(string $phone = '(346) 823-6941'): WorkOrder
    {
        $tenant = Tenants::factory()->create([
            'first_name' => 'Rikki',
            'last_name' => 'Lobato',
            'mobile_phone' => $phone,
            'home_phone' => null,
        ]);

        return WorkOrder::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function jobberJob(): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '123 Main St',
            'jobber_web_uri' => 'https://example.test',
        ]);
        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '10550',
            'title' => 'Zone 2 - Q4 TBP',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function jobberText(Jobber $job, string $from, string $to, string $body): JobberTextMessage
    {
        return JobberTextMessage::query()->create([
            'messages' => $body,
            'sender_number' => $from,
            'receiver_number' => $to,
            'jobber_id' => $job->id,
        ]);
    }

    public function test_staff_see_the_tbp_notice_the_reply_and_the_vendor_tenant_thread(): void
    {
        $workOrder = $this->workOrderForTenant();
        $job = $this->jobberJob();
        $this->jobberText($job, self::THMP_PHONE, self::TENANT_PHONE, 'Dear Rikki, As part of your Tenant Benefit Package...');
        $this->jobberText($job, self::TENANT_PHONE, self::THMP_PHONE, 'Sounds good');
        Conversation::query()->create([
            'message' => 'They can unlock all of us will be at work and school.',
            'conversation_type' => 'vendor_tenant',
            'sender_number' => self::TENANT_PHONE,
            'receiver_number' => self::THMP_PHONE,
            'work_order_id' => $workOrder->id,
        ]);

        $response = $this->actingAs($this->user('woc'))
            ->getJson(route('work_order.tenant_thmp_texts', $workOrder))
            ->assertOk();

        $texts = collect($response->json('thmp_texts'));
        $this->assertCount(3, $texts);
        $this->assertTrue($texts->contains(fn ($text) => ($text['messages'] ?? null) === 'Sounds good'
            && str_contains($text['source_label'], 'Jobber job #10550')));
        $this->assertTrue($texts->contains(fn ($text) => ($text['message'] ?? null) === 'They can unlock all of us will be at work and school.'
            && str_contains($text['source_label'], 'this work order')));
    }

    public function test_another_tenants_jobber_texts_are_not_listed(): void
    {
        $workOrder = $this->workOrderForTenant();
        $job = $this->jobberJob();
        $this->jobberText($job, self::THMP_PHONE, '+15125550000', 'Dear Someone Else...');

        $response = $this->actingAs($this->user('admin'))
            ->getJson(route('work_order.tenant_thmp_texts', $workOrder))
            ->assertOk();

        $this->assertSame([], $response->json('thmp_texts'));
    }

    public function test_a_work_order_without_a_tenant_number_lists_nothing_from_jobber(): void
    {
        $workOrder = WorkOrder::factory()->create(['tenant_id' => null]);
        $this->jobberText($this->jobberJob(), self::THMP_PHONE, self::TENANT_PHONE, 'Dear Rikki...');

        $this->actingAs($this->user('woc'))
            ->getJson(route('work_order.tenant_thmp_texts', $workOrder))
            ->assertOk()
            ->assertJsonCount(0, 'thmp_texts');
    }

    public function test_tenants_and_vendors_cannot_read_it(): void
    {
        $workOrder = $this->workOrderForTenant();

        // Forbidden, or not found when the work order scope hides it outright.
        foreach (['vendor', 'tenant'] as $role) {
            $status = $this->actingAs($this->user($role))
                ->getJson(route('work_order.tenant_thmp_texts', $workOrder))
                ->status();

            $this->assertContains($status, [403, 404], "A {$role} login must not read the THMP texts.");
        }
    }
}
