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

    private function conversations(): int
    {
        return Conversation::query()->withoutGlobalScopes()->count();
    }

    public function test_it_texts_the_owner_a_reminder_with_their_portal_link(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();

        $message = Conversation::query()->withoutGlobalScopes()->first();
        $token = OwnerPortalToken::query()->where('work_order_id', $workOrder->id)->where('owner_id', $owner->id)->first();

        $this->assertNotNull($message);
        $this->assertNotNull($token);
        $this->assertSame('owner', $message->conversation_type);
        $this->assertSame('+15125551234', $message->receiver_number);
        $this->assertSame('+15125550000', $message->sender_number);
        $this->assertStringContainsString('Work order #43950 for your property at 6341 Del Monte Dr is waiting for your approval.', $message->message);
        $this->assertStringContainsString(NudgeOwnerApproval::LINK_LEAD.'http', $message->message);
        $this->assertStringContainsString('/owner-portal/'.$token->token, $message->message);
        $this->assertStringContainsString('(Ref: WO#43950)', $message->message);

        Queue::assertPushed(SendConversationMessageJob::class, 1);

        $this->assertSame(1, (int) $token->approval_nudge_count);
        $this->assertNotNull($token->approval_nudge_last_sent_at);

        $ledger = Activity::query()->where('log_name', 'automated_message')->where('event', 'owner_approval_nudge_sms')->first();
        $this->assertNotNull($ledger);
        $this->assertSame('+15125551234', $ledger->properties['recipient']);
    }

    public function test_it_emails_an_owner_who_has_no_phone_on_file(): void
    {
        $owner = $this->makeOwner(mobile: null, email: 'Olivia@Example.com');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->mock(OwnerWorkOrderEmailSender::class, function (MockInterface $mock) use ($owner, $workOrder) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (Owner $to, WorkOrder $about, string $address, string $mailbox, string $subject, string $html, array $files, $sentBy, bool $trustedHtml, array $metadata, string $type) use ($owner, $workOrder): bool {
                    return $to->is($owner)
                        && $about->is($workOrder)
                        && $address === 'olivia@example.com'
                        && $mailbox === 'workorders@example.com'
                        && str_contains($subject, 'Approval needed - Work Order #43950')
                        && str_contains($html, 'is waiting for your approval')
                        && str_contains($html, '/owner-portal/')
                        && str_contains($html, 'Review Work Order #43950')
                        && $trustedHtml
                        && $type === 'approval_nudge';
                })
                ->andReturn(new OwnerEmailNotification(['id' => 77]));
        });

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 1')
            ->assertSuccessful();

        // Email only: nothing in the text thread, nothing queued for Twilio.
        $this->assertSame(0, $this->conversations());
        Queue::assertNotPushed(SendConversationMessageJob::class);

        $token = OwnerPortalToken::query()->where('owner_id', $owner->id)->first();
        $this->assertSame(1, (int) $token->approval_nudge_count);

        $ledger = Activity::query()->where('log_name', 'automated_message')->where('event', 'owner_approval_nudge_email')->first();
        $this->assertNotNull($ledger);
        $this->assertSame('olivia@example.com', $ledger->properties['recipient']);
        $this->assertSame('email', $ledger->properties['channel']);
    }

    public function test_an_owner_with_a_phone_is_texted_and_never_also_emailed(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner(mobile: '5125551234', email: 'olivia@example.com');
        $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(1, $this->conversations());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_an_owner_with_neither_phone_nor_email_is_skipped_and_the_skip_is_logged(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner(mobile: null, email: '');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')
            ->expectsOutputToContain('reminders sent: 0')
            ->assertSuccessful();

        $this->assertSame(0, $this->conversations());
        $this->assertSame(0, OwnerPortalToken::query()->count());

        $skip = Activity::query()->where('log_name', 'automated_message')->where('event', 'owner_approval_nudge_sms')->first();
        $this->assertNotNull($skip);
        $this->assertSame($workOrder->id, $skip->properties['work_order_id']);
        $this->assertSame(NudgeOwnerApproval::SKIP_NO_CONTACT, $skip->properties['not_texted_reason']);
        $this->assertNull($skip->properties['recipient']);
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

        $this->assertSame(0, $this->conversations());
        $this->assertSame(0, OwnerPortalToken::query()->count());
    }

    public function test_it_only_reminds_once_per_day(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        $this->artisan('owners:nudge-approval')->assertSuccessful();
        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(1, $this->conversations());
        $token = OwnerPortalToken::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertSame(1, (int) $token->approval_nudge_count);
    }

    public function test_it_reminds_again_the_next_day_with_no_cap(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);

        // Day 40 of an owner who has never answered: PropertyWare would still
        // be emailing them, so we still text them.
        OwnerPortalToken::create([
            'token' => 'owner-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'approval_nudge_last_sent_at' => now()->subDay(),
            'approval_nudge_count' => 40,
        ]);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(1, $this->conversations());
        $this->assertSame(41, (int) OwnerPortalToken::query()->first()->approval_nudge_count);
        $this->assertStringContainsString('/owner-portal/owner-token-'.$workOrder->id, Conversation::query()->withoutGlobalScopes()->first()->message);
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

        $this->assertSame(0, $this->conversations());
    }

    public function test_an_approved_work_order_is_not_reminded(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $this->makeUnapprovedWorkOrder($owner, ['is_approved' => true]);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(0, $this->conversations());
    }

    public function test_a_work_order_no_longer_open_in_propertyware_is_not_reminded(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $this->makeUnapprovedWorkOrder($owner, ['status' => 'Closed']);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(0, $this->conversations());
    }

    public function test_the_service_status_does_not_matter_while_propertyware_waits(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'Completed - Verified - Waiting on Bill'], ['description' => 'Waiting on Bill']);
        $this->makeUnapprovedWorkOrder($owner, ['service_status_id' => $status->id]);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(1, $this->conversations());
    }

    public function test_a_muted_work_order_is_skipped(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->setAutomationPaused('owner', true);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(0, $this->conversations());
    }

    public function test_company_ordered_work_is_skipped(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();

        // PropertyWare's own picklist spellings: "Turnover", "Re-Key", "Cleaning".
        foreach ([['type' => 'Turnover'], ['category' => 'Re-Key'], ['category' => 'Cleaning']] as $attributes) {
            $this->makeUnapprovedWorkOrder($owner, $attributes + ['work_order_no' => fake()->unique()->numberBetween(50000, 59999)]);
        }

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(0, $this->conversations());
    }

    public function test_two_owners_sharing_one_phone_get_a_single_text(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner('5125551234', 'olivia@example.com', 'Olivia');
        $spouse = $this->makeOwner('5125551234', 'owen@example.com', 'Owen');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->owners()->attach($spouse->id);

        $this->artisan('owners:nudge-approval')->assertSuccessful();

        $this->assertSame(1, $this->conversations());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_a_dry_run_lists_recipients_without_sending_or_recording_anything(): void
    {
        $this->expectNoEmail();
        $owner = $this->makeOwner();
        $emailOnly = $this->makeOwner(mobile: null, email: 'owen@example.com', first: 'Owen');
        $workOrder = $this->makeUnapprovedWorkOrder($owner);
        $workOrder->owners()->attach($emailOnly->id);

        $this->artisan('owners:nudge-approval --dry-run')
            ->expectsOutputToContain('2 reminder(s) would go out today')
            ->assertSuccessful();

        $this->assertSame(0, $this->conversations());
        $this->assertSame(0, OwnerPortalToken::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'automated_message')->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }
}
