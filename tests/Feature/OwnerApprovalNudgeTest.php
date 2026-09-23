<?php

namespace Tests\Feature;

use App\Console\Commands\NudgeOwnerApproval;
use App\Jobs\SendConversationMessageJob;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\OwnerPortalToken;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerWorkOrderEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OwnerApprovalNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.twilio.owner_approval_nudge', true);
        config()->set('services.twilio.maintenance_number', '+15125550000');
        config()->set('services.microsoft.mailbox', 'workorders@example.com');

        Queue::fake();
    }

    private function makeOwner(?string $mobile = '5125551234', ?string $email = 'olivia@example.com', string $first = 'Olivia'): Owner
    {
        return Owner::query()->create([
            'first_name' => $first,
            'last_name' => 'Owner',
            'email' => $email,
            'mobile' => $mobile,
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * A work order PropertyWare holds as Open and unapproved — the state the
     * reminder chases — under a service status well past New.
     */
    private function makeUnapprovedWorkOrder(Owner $owner, array $attributes = []): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'Assigned'], ['description' => 'Assigned']);
        $building = Building::query()->firstOrCreate(
            ['propertyware_id' => 7102],
            ['name' => '6341 Del Monte Dr', 'address' => '6341 Del Monte Dr', 'portfolio_id' => 900],
        );

        $workOrder = WorkOrder::factory()->create(array_merge([
            'service_status_id' => $status->id,
            'work_order_no' => 43950,
            'status' => 'Open',
            'is_approved' => false,
            'building_id' => $building->propertyware_id,
        ], $attributes));

        $workOrder->owners()->attach($owner->id);

        return $workOrder;
    }

    private function expectNoEmail(): void
    {
        $this->mock(OwnerWorkOrderEmailSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('send');
        });
    }

    /**
     * Expect exactly $times reminder emails, to anyone, and return the mock so
     * a test can also check who they went to.
     */
    private function expectEmails(int $times): void
    {
        $this->mock(OwnerWorkOrderEmailSender::class, function (MockInterface $mock) use ($times) {
            $mock->shouldReceive('send')
                ->times($times)
                ->andReturn(new OwnerEmailNotification(['id' => 77]));
        });
    }

    private function conversations(): int
    {
        return Conversation::query()->withoutGlobalScopes()->count();
    }

    private function assertNothingTexted(): void
    {
        $this->assertSame(0, $this->conversations());
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_emails_the_owner_a_reminder_with_their_portal_link(): void
    {
        $owner = $this->makeOwner(email: 'Olivia@Example.com');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->mock(OwnerWorkOrderEmailSender::class, function (MockInterface $mock) use ($owner, $workOrder) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (Owner $to, WorkOrder $about, string $address, string $mailbox, string $subject, string $html, array $files, $sentBy, bool $trustedHtml, array $metadata, string $type) use ($owner, $workOrder): bool {
                    $token = OwnerPortalToken::query()->where('work_order_id', $workOrder->id)->where('owner_id', $owner->id)->first();

                    return $to->is($owner)
                        && $about->is($workOrder)
                        && $address === 'olivia@example.com'
                        && $mailbox === 'workorders@example.com'
                        && str_contains($subject, 'Approval needed - Work Order #43950')
                        && str_contains($html, 'Work order #43950 for your property at 6341 Del Monte Dr is waiting for your approval.')
                        && $token !== null
                        && str_contains($html, '/owner-portal/'.$token->token)
                        && str_contains($html, 'Review Work Order #43950')
                        && str_contains($html, '(Ref: WO#43950)')
                        && $trustedHtml
                        && $metadata === ['automation' => 'owner_approval_nudge_email']
                        && $type === 'approval_nudge';
                })
                ->andReturn(new OwnerEmailNotification(['id' => 77]));
        });

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();

        $token = OwnerPortalToken::query()->where('work_order_id', $workOrder->id)->where('owner_id', $owner->id)->first();
        $this->assertNotNull($token);
        $this->assertSame(1, (int) $token->approval_nudge_count);
        $this->assertNotNull($token->approval_nudge_last_sent_at);

        $ledger = Activity::query()->where('log_name', 'automated_message')->where('event', 'owner_approval_nudge_email')->first();
        $this->assertNotNull($ledger);
        $this->assertSame('olivia@example.com', $ledger->properties['recipient']);
        $this->assertSame('email', $ledger->properties['channel']);
        $this->assertSame(77, $ledger->properties['owner_email_notification_id']);
    }

    public function test_an_owner_with_a_phone_is_still_emailed_and_never_texted(): void
    {
        $this->expectEmails(1);
        $owner = $this->makeOwner(mobile: '5125551234', email: 'olivia@example.com');
        $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();

        $this->assertNothingTexted();

        $ledger = Activity::query()->where('log_name', 'automated_message')->get();
        $this->assertCount(1, $ledger);
        $this->assertSame('email', $ledger->first()->properties['channel']);
    }

    public function test_an_owner_with_no_email_is_skipped_even_when_they_have_a_phone(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner(mobile: '5125551234', email: '');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();

        $this->assertNothingTexted();
        $this->assertSame(0, OwnerPortalToken::query()->count());

        $skip = Activity::query()->where('log_name', 'automated_message')->where('event', 'owner_approval_nudge_email')->first();
        $this->assertNotNull($skip);
        $this->assertSame($workOrder->id, $skip->properties['work_order_id']);
        $this->assertSame(NudgeOwnerApproval::SKIP_NO_CONTACT, $skip->properties['not_texted_reason']);
        $this->assertNull($skip->properties['recipient']);
    }

    public function test_a_placeholder_email_counts_as_no_email(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner(mobile: null, email: 'none');
        $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();

        $this->assertSame(NudgeOwnerApproval::SKIP_NO_CONTACT, Activity::query()->where('log_name', 'automated_message')->first()->properties['not_texted_reason']);
    }

    public function test_it_is_a_no_op_when_the_gate_is_off(): void
    {
        config()->set('services.twilio.owner_approval_nudge', false);
        $this->expectNoEmail();

        $owner = $this->makeOwner();
        $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame(0, OwnerPortalToken::query()->count());
    }

    public function test_it_only_reminds_once_per_day(): void
    {
        $this->expectEmails(1);
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')->assertSuccessful();
        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $token = OwnerPortalToken::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertSame(1, (int) $token->approval_nudge_count);
    }

    public function test_it_reminds_again_the_next_day_with_no_cap(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        // Day 40 of an owner who has never answered: PropertyWare would still
        // be emailing them, so we still email them — with the same link.
        OwnerPortalToken::create([
            'token' => 'owner-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'approval_nudge_last_sent_at' => now()->subDay(),
            'approval_nudge_count' => 40,
        ]);

        $this->mock(OwnerWorkOrderEmailSender::class, function (MockInterface $mock) use ($workOrder) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn (Owner $to, WorkOrder $about, string $address, string $mailbox, string $subject, string $html): bool => str_contains($html, '/owner-portal/owner-token-'.$workOrder->id))
                ->andReturn(new OwnerEmailNotification(['id' => 78]));
        });

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(41, (int) OwnerPortalToken::query()->first()->approval_nudge_count);
    }

    public function test_it_stops_once_any_owner_has_answered_in_the_portal(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner('5125551234', 'olivia@example.com', 'Olivia');
        $coOwner = $this->makeOwner('5125557777', 'owen@example.com', 'Owen');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        // The co-owner said "don't approve" through the portal. That is an
        // answer for the work order, so nobody is nagged — including the
        // primary owner, and even though PropertyWare still shows it unapproved.
        activity()
            ->performedOn($workOrder)
            ->event('owner_portal_approval')
            ->withProperties(['owner_id' => $coOwner->id, 'decision' => 'disapproved'])
            ->log('Work Order #43950 - Owner Did Not Approve');

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();
    }

    public function test_an_approved_work_order_is_not_reminded(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $this->makeUnapprovedWorkOrder($owner, ['is_approved' => true]);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();
    }

    public function test_a_work_order_no_longer_open_in_propertyware_is_not_reminded(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $this->makeUnapprovedWorkOrder($owner, ['status' => 'Closed']);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();
    }

    public function test_the_service_status_does_not_matter_while_propertyware_waits(): void
    {
        $this->expectEmails(1);
        $owner = $this->makeOwner();
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'Completed - Verified - Waiting on Bill'], ['description' => 'Waiting on Bill']);
        $this->makeUnapprovedWorkOrder($owner, ['service_status_id' => $status->id]);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();
    }

    public function test_the_pre_existing_backlog_stamped_at_deploy_is_never_reminded(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();

        // The fresh-start backfill stamps every work order that exists at
        // deploy time; those are PropertyWare's problem, not ours.
        $this->makeUnapprovedWorkOrder($owner, ['approval_nudge_excluded_at' => now()->subMinute()]);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();

        $this->assertSame(0, OwnerPortalToken::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'automated_message')->count());
    }

    public function test_a_muted_work_order_is_skipped(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->setAutomationPaused('owner', true);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();
    }

    public function test_company_ordered_work_is_skipped(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();

        // PropertyWare's own picklist spellings: "Turnover", "Re-Key", "Cleaning".
        foreach ([['type' => 'Turnover'], ['category' => 'Re-Key'], ['category' => 'Cleaning']] as $attributes) {
            $this->makeUnapprovedWorkOrder($owner, $attributes + ['work_order_no' => fake()->unique()->numberBetween(50000, 59999)]);
        }

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();
    }

    public function test_two_owners_sharing_one_inbox_get_a_single_email(): void
    {
        $this->expectEmails(1);
        $owner = $this->makeOwner('5125551234', 'family@example.com', 'Olivia');
        $spouse = $this->makeOwner('5125557777', 'Family@Example.com', 'Owen');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->owners()->attach($spouse->id);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();
    }

    public function test_every_owner_with_their_own_inbox_is_emailed(): void
    {
        $this->expectEmails(2);
        $owner = $this->makeOwner('5125551234', 'olivia@example.com', 'Olivia');
        $coOwner = $this->makeOwner(null, 'owen@example.com', 'Owen');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 2')
            ->assertSuccessful();

        $this->assertSame(
            ['olivia@example.com', 'owen@example.com'],
            Activity::query()->where('log_name', 'automated_message')->get()->map(fn (Activity $row) => $row->properties['recipient'])->sort()->values()->all(),
        );
    }

    public function test_a_dry_run_lists_recipients_without_sending_or_recording_anything(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $coOwner = $this->makeOwner(mobile: null, email: 'owen@example.com', first: 'Owen');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        $this->artisan('owners:nudge-approval --dry-run')
            ->expectsOutputToContain('2 reminder(s) would go out today')
            ->assertSuccessful();

        $this->assertSame(0, OwnerPortalToken::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'automated_message')->count());
        $this->assertNothingTexted();
    }
}
