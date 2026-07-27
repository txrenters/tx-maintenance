<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VendorShowEmailHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_show_includes_persisted_email_notification_history(): void
    {
        $user = User::factory()->create();
        $vendorUser = User::factory()->create();
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'vendor-100',
            'name' => 'Acme Plumbing',
            'email' => 'vendor@example.com',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4321]);
        $workOrder->vendors()->attach($vendor);
        EmailMessage::factory()->create([
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
            'subject' => 'New Service Request [TX-4321-1]',
            'body_text' => 'Please review the attached work order.',
        ]);
        Gate::shouldReceive('authorize')->once()->with('view_vendors', Vendor::class);

        $this->actingAs($user)
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vendor/Show')
                ->where('vendor.id', $vendor->id)
                ->where('emailHistory.0.subject', 'New Service Request [TX-4321-1]')
                ->where('emailHistory.0.work_order.id', $workOrder->id)
                ->where('emailHistory.0.body_text', 'Please review the attached work order.'));
    }
}
