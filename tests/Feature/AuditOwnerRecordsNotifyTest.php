<?php

namespace Tests\Feature;

use App\Console\Commands\AuditOwnerRecords;
use App\Models\AppSetting;
use App\Models\Owner;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditOwnerRecordsNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithWorkOrderAndNoPhone(): Owner
    {
        $owner = Owner::create([
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'phone' => null,
            'mobile' => null,
            'user_id' => User::factory()->create()->id,
        ]);

        DB::table('work_order_owners')->insert([
            'work_order_id' => WorkOrder::factory()->create()->id,
            'owner_id' => $owner->id,
        ]);

        return $owner;
    }

    public function test_notify_raises_a_bell_when_problems_are_found(): void
    {
        $this->ownerWithWorkOrderAndNoPhone();

        $this->artisan('owners:audit --notify')->assertSuccessful();

        $activities = Activity::where('event', AuditOwnerRecords::NOTIFY_EVENT)->get();
        $this->assertCount(1, $activities);
        $this->assertFalse($activities->first()->properties['read']);
        $this->assertStringContainsString('no phone on file', $activities->first()->properties['message']);
        $this->assertSame(1, AppSetting::getValue(AuditOwnerRecords::LAST_COUNTS_KEY)['owners_missing_phone']);
    }

    public function test_notify_stays_silent_while_the_counts_are_unchanged(): void
    {
        $this->ownerWithWorkOrderAndNoPhone();

        $this->artisan('owners:audit --notify')->assertSuccessful();
        $this->artisan('owners:audit --notify')->assertSuccessful();

        $this->assertSame(1, Activity::where('event', AuditOwnerRecords::NOTIFY_EVENT)->count());
    }

    public function test_notify_rings_again_when_the_counts_move(): void
    {
        $this->ownerWithWorkOrderAndNoPhone();
        $this->artisan('owners:audit --notify')->assertSuccessful();

        $this->ownerWithWorkOrderAndNoPhone();
        $this->artisan('owners:audit --notify')->assertSuccessful();

        $this->assertSame(2, Activity::where('event', AuditOwnerRecords::NOTIFY_EVENT)->count());
    }

    public function test_clean_data_never_rings(): void
    {
        $this->artisan('owners:audit --notify')->assertSuccessful();

        $this->assertSame(0, Activity::where('event', AuditOwnerRecords::NOTIFY_EVENT)->count());
    }
}
