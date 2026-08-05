<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AutomatedMessageLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_ledger_row_with_the_expected_encoding(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43704]);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'owner',
            'owner_appointment_sms',
            '+15125550000',
            $workOrder,
            'Hello, your appointment is set.',
            ['owner_id' => 7],
        );

        $activity = Activity::query()->where('log_name', AutomatedMessageLogService::LOG_NAME)->sole();

        $this->assertSame('owner:sms', $activity->description);
        $this->assertSame('owner_appointment_sms', $activity->event);
        $this->assertSame(WorkOrder::class, $activity->subject_type);
        $this->assertSame($workOrder->id, (int) $activity->subject_id);
        $this->assertSame('sms', $activity->properties['channel']);
        $this->assertSame('owner', $activity->properties['audience']);
        $this->assertSame('+15125550000', $activity->properties['recipient']);
        $this->assertSame($workOrder->id, $activity->properties['work_order_id']);
        $this->assertSame(43704, $activity->properties['work_order_no']);
        $this->assertSame('Hello, your appointment is set.', $activity->properties['message']);
        $this->assertSame(7, $activity->properties['owner_id']);
    }

    public function test_it_truncates_long_messages_and_accepts_a_null_subject(): void
    {
        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_EMAIL,
            'tenant',
            'tenant_job_reminder_email',
            'tenant@example.com',
            null,
            str_repeat('x', 600),
        );

        $activity = Activity::query()->where('log_name', AutomatedMessageLogService::LOG_NAME)->sole();

        $this->assertSame('tenant:email', $activity->description);
        $this->assertNull($activity->subject_type);
        $this->assertNull($activity->properties['work_order_id']);
        $this->assertLessThanOrEqual(503, strlen($activity->properties['message']));
        $this->assertStringEndsWith('...', $activity->properties['message']);
    }

    public function test_a_ledger_failure_is_swallowed_and_logged(): void
    {
        $this->mock(ActivityLogger::class, function ($mock) {
            $mock->shouldReceive('useLog')->andThrow(new \RuntimeException('ledger down'));
        });

        Log::shouldReceive('warning')->once();

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'vendor',
            'vendor_assignment_sms',
            '+15125551111',
        );

        $this->assertTrue(true); // reaching here means log() did not throw
    }

    public function test_the_tenant_portal_link_send_writes_a_ledger_row(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->easyFixWorkOrder();

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $activity = Activity::query()->where('log_name', AutomatedMessageLogService::LOG_NAME)->sole();

        $this->assertSame('tenant_portal_link_sms', $activity->event);
        $this->assertSame('tenant:sms', $activity->description);
        $this->assertSame($workOrder->id, (int) $activity->subject_id);
        $this->assertSame('+15125559999', $activity->properties['recipient']);
    }

    public function test_a_paused_automation_writes_no_ledger_row(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->easyFixWorkOrder();
        $workOrder->update(['paused_automations' => ['tenant']]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(0, Activity::query()->where('log_name', AutomatedMessageLogService::LOG_NAME)->count());
    }

    private function easyFixWorkOrder(): WorkOrder
    {
        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'mobile_phone' => '5125559999',
            'address' => '6341 Del Monte Dr',
            'user_id' => User::factory()->create()->id,
        ]);

        $status = ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'The service request is checking for an easy fix.'],
        );

        return WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 43361,
            'description' => 'There is water dripping from the roof',
            'tenant_id' => $tenant->id,
        ]);
    }
}
