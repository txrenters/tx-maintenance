<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItToolsAutomatedMessagesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function seedRows(): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43704]);

        AutomatedMessageLogService::log('sms', 'owner', 'owner_appointment_sms', '+15125550000', $workOrder, 'Owner text');
        AutomatedMessageLogService::log('email', 'tenant', 'tenant_intake_email', 'tenant@example.com', $workOrder, extra: ['subject' => 'We received your request']);
        AutomatedMessageLogService::log('sms', 'vendor', 'vendor_assignment_sms', '+15125551111', $workOrder, 'Vendor text');

        return $workOrder;
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/it-tools/automated-messages')->assertRedirect();
    }

    public function test_a_woc_can_view_the_page(): void
    {
        // Widened from admin-only alongside the Message Templates editor:
        // coordinators edit the canned wording, so they see the page too.
        $user = User::factory()->create()->assignRole('woc');

        $this->actingAs($user)->get('/it-tools/automated-messages')->assertOk();
    }

    public function test_non_staff_roles_are_forbidden(): void
    {
        $user = User::factory()->create()->assignRole('accounting');

        $this->actingAs($user)->get('/it-tools/automated-messages')->assertForbidden();
    }

    public function test_an_admin_sees_the_log_newest_first(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ItTools/AutomatedMessages')
                ->has('logs.data', 3)
                ->where('logs.data.0.automation', 'vendor_assignment_sms')
                ->where('logs.data.0.audience', 'vendor')
                ->where('logs.data.0.channel', 'sms')
                ->where('logs.data.0.recipient', '+15125551111')
                ->where('logs.data.0.work_order_no', 43704)
                ->where('logs.data.2.automation', 'owner_appointment_sms')
                ->where('stats.total', 3)
                ->where('stats.owner', 1)
                ->where('stats.tenant', 1)
                ->where('stats.vendor', 1)
                ->where('stats.sms', 2)
                ->where('stats.email', 1)
                ->has('automations'));
    }

    public function test_the_audience_filter_narrows_the_log(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?audience=owner')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.audience', 'owner')
                ->where('filters.audience', 'owner'));
    }

    public function test_the_channel_filter_narrows_the_log(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?channel=email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.channel', 'email')
                ->where('logs.data.0.message', 'We received your request'));
    }

    public function test_audience_and_channel_combine(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?audience=tenant&channel=sms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
    }

    public function test_the_automation_filter_narrows_the_log(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?automation=vendor_assignment_sms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.automation', 'vendor_assignment_sms'));
    }

    public function test_the_work_order_filter_matches_by_number(): void
    {
        $this->seedRows();

        $other = WorkOrder::factory()->create(['work_order_no' => 99999]);
        AutomatedMessageLogService::log('sms', 'tenant', 'tenant_service_request_sms', '+15125552222', $other, 'Other WO');

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?work_order=99999')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.work_order_no', 99999));
    }

    public function test_the_date_range_filter_narrows_the_log(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?date_from='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 3));
    }

    public function test_invalid_filters_fall_back_to_all_and_per_page_is_clamped(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages?audience=alien&channel=fax&automation=nope&per_page=5')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 3)
                ->where('filters.audience', 'all')
                ->where('filters.channel', 'all')
                ->where('filters.automation', 'all')
                ->where('filters.per_page', 10));
    }

    public function test_rows_from_other_activity_log_writers_are_excluded(): void
    {
        $workOrder = $this->seedRows();

        activity()
            ->performedOn($workOrder)
            ->event('vendor_auto_assigned')
            ->withProperties(['read' => false])
            ->log('Vendor auto-assigned to Work Order #43704');

        $this->actingAs($this->admin())
            ->get('/it-tools/automated-messages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 3));
    }
}
