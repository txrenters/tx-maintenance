<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Notifications\StaffActivityNotification;
use App\Services\AutomatedMessageLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_only_sees_woc_vendor_conversation_notifications(): void
    {
        Role::findOrCreate('vendor', 'web');
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);

        $user = User::factory()->create(['phone' => '5125551234']);
        $user->assignRole('vendor');
        $vendor = Vendor::create([
            'propertyware_id' => 'V-1', 'name' => 'Acme', 'is_active' => true, 'user_id' => $user->id,
        ]);

        $myWo = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 1]);
        $myWo->vendors()->attach($vendor->id);
        $otherWo = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 2]);

        // WOC <-> vendor conversation on the vendor's work order — visible.
        $vendorConvo = Conversation::create([
            'message' => 'hello vendor', 'work_order_id' => $myWo->id, 'conversation_type' => 'vendor',
        ]);
        $visible = Activity::create([
            'log_name' => 'default', 'description' => 'vendor-msg',
            'subject_type' => Conversation::class, 'subject_id' => $vendorConvo->id,
            'properties' => ['work_order_id' => $myWo->id],
        ]);

        // Tenant conversation on the SAME work order — must not leak to the vendor.
        $tenantConvo = Conversation::create([
            'message' => 'tenant talk', 'work_order_id' => $myWo->id, 'conversation_type' => 'tenant',
        ]);
        $tenantActivity = Activity::create([
            'log_name' => 'default', 'description' => 'tenant-msg',
            'subject_type' => Conversation::class, 'subject_id' => $tenantConvo->id,
            'properties' => ['work_order_id' => $myWo->id],
        ]);

        // Vendor conversation on a work order NOT assigned to this vendor.
        $otherConvo = Conversation::create([
            'message' => 'other', 'work_order_id' => $otherWo->id, 'conversation_type' => 'vendor',
        ]);
        $otherActivity = Activity::create([
            'log_name' => 'default', 'description' => 'other-wo',
            'subject_type' => Conversation::class, 'subject_id' => $otherConvo->id,
            'properties' => ['work_order_id' => $otherWo->id],
        ]);

        // Non-conversation activity on the vendor's work order (e.g. invoice upload).
        $unrelated = Activity::create([
            'log_name' => 'default', 'description' => 'unrelated',
            'properties' => ['work_order_id' => $myWo->id, 'message' => 'invoice uploaded'],
        ]);

        $response = $this->actingAs($user)->getJson('/notifications')->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($tenantActivity->id, $ids);
        $this->assertNotContains($otherActivity->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_the_bell_payload_carries_a_slim_subject_not_the_whole_row(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create()->assignRole('admin');

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 77]);
        $conversation = Conversation::create([
            'message' => 'private body text',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125551111',
            'twilio_sid' => 'SM123',
            'is_read' => false,
        ]);

        $activity = Activity::create([
            'log_name' => 'default', 'description' => 'msg',
            'subject_type' => Conversation::class, 'subject_id' => $conversation->id,
            'properties' => ['work_order_id' => $workOrder->id, 'message' => 'Alert', 'read' => false],
        ]);

        $row = collect($this->actingAs($user)->getJson('/notifications')->assertOk()->json())
            ->firstWhere('id', $activity->id);

        // Enough to identify and reopen the thread…
        $this->assertSame('tenant', $row['subject']['conversation_type']);
        $this->assertSame($workOrder->id, $row['subject']['work_order_id']);
        $this->assertSame('+15125550000', $row['subject']['sender_number']);
        $this->assertSame('+15125551111', $row['subject']['receiver_number']);

        // …and none of the row's bulk: message bodies and Twilio sids stay
        // off the wire — the whole-model subject made each poll megabytes.
        $this->assertArrayNotHasKey('message', $row['subject']);
        $this->assertArrayNotHasKey('twilio_sid', $row['subject']);

        // Relative time renders client-side from `timestamp`; a server-baked
        // string flipped every minute and defeated the poll's change guard.
        $this->assertArrayNotHasKey('time', $row);
        $this->assertArrayHasKey('timestamp', $row);
    }

    public function test_the_automated_message_ledger_stays_out_of_the_bell(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create()->assignRole('admin');

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43732]);

        AutomatedMessageLogService::log(
            'sms',
            'owner',
            'owner_service_request_sms',
            '+15125550000',
            $workOrder,
            'Hello, we received a new service request.',
        );

        $staffAlert = Activity::create([
            'log_name' => 'default', 'description' => 'EMERGENCY - Work Order #43732',
            'properties' => ['work_order_id' => $workOrder->id, 'message' => 'Emergency alert', 'read' => false],
        ]);

        $response = $this->actingAs($user)->getJson('/notifications')->assertOk();

        $rows = collect($response->json());

        $this->assertContains($staffAlert->id, $rows->pluck('id')->all());
        $this->assertSame(0, $rows->where('title', 'owner:sms')->count());
        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', AutomatedMessageLogService::LOG_NAME)
                ->whereIn('id', $rows->pluck('id'))
                ->count(),
        );
    }

    public function test_named_audit_logs_stay_out_of_the_bell(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create()->assignRole('admin');

        // The technician roster and the template editor keep audit trails
        // under their own log_name — bare "created"/"updated" rows that once
        // rendered as meaningless cards in every staff bell.
        $technicianAudit = Activity::create([
            'log_name' => 'technician', 'description' => 'updated',
            'properties' => ['name' => 'Amy Wilson', 'changes' => []],
        ]);
        $templateAudit = Activity::create([
            'log_name' => 'message_template', 'description' => 'updated',
            'properties' => ['template' => 'tenant_visit_reminder'],
        ]);

        $staffAlert = Activity::create([
            'log_name' => 'default', 'description' => 'Message Undelivered',
            'properties' => ['message' => 'Alert', 'read' => false],
        ]);
        $legacyRow = Activity::create([
            'description' => 'legacy', 'properties' => ['message' => 'written before log names'],
        ]);

        $ids = collect($this->actingAs($user)->getJson('/notifications')->assertOk()->json())
            ->pluck('id')->all();

        $this->assertContains($staffAlert->id, $ids);
        $this->assertContains($legacyRow->id, $ids);
        $this->assertNotContains($technicianAudit->id, $ids);
        $this->assertNotContains($templateAudit->id, $ids);
    }

    public function test_named_audit_logs_never_reach_the_desktop(): void
    {
        Role::findOrCreate('woc', 'web');
        $woc = User::factory()->create();
        $woc->assignRole('woc');

        Notification::fake();

        // Creating the row is the trigger: AppServiceProvider runs the
        // notifier on Activity::created. Same configured event on both rows;
        // only the log name differs.
        Activity::create([
            'log_name' => 'technician', 'event' => 'message_undelivered',
            'description' => 'updated', 'properties' => [],
        ]);
        Notification::assertNothingSent();

        Activity::create([
            'log_name' => 'default', 'event' => 'message_undelivered',
            'description' => 'Message Undelivered', 'properties' => ['message' => 'delivery failed'],
        ]);
        Notification::assertSentTo($woc, StaffActivityNotification::class);
    }
}
