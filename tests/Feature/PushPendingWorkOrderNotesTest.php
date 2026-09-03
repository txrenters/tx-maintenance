<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushPendingWorkOrderNotesTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-09-03 15:04:00', 'UTC');
        $this->travelTo($this->now);
    }

    private function workOrder(?int $propertyWareId = 8144617505): WorkOrder
    {
        return WorkOrder::factory()->create(['propertyware_id' => $propertyWareId, 'work_order_no' => 43649]);
    }

    /**
     * A note written on the dashboard some minutes ago whose last PropertyWare
     * attempt (the save itself, unless given) was some minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function dashboardNote(WorkOrder $workOrder, int $ageMinutes, ?int $lastAttemptMinutesAgo = null, array $attributes = []): WorkOrderNotes
    {
        $note = WorkOrderNotes::query()->create(array_merge([
            'work_order_id' => $workOrder->id,
            'subject' => 'Closing Comment',
            'body' => 'Invoice uploaded.',
            'user_id' => User::factory()->create()->id,
            'is_private' => true,
        ], $attributes));

        WorkOrderNotes::query()->whereKey($note->id)->update([
            'created_at' => $this->now->copy()->subMinutes($ageMinutes),
            'updated_at' => $this->now->copy()->subMinutes($lastAttemptMinutesAgo ?? $ageMinutes),
        ]);

        return $note->fresh();
    }

    private function propertyWareIsNotCalled(): void
    {
        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('workOrderNotesFromPropertyWare', 'addVendorNotes');
        });
    }

    public function test_a_note_saved_moments_ago_is_left_to_the_save_request(): void
    {
        $this->dashboardNote($this->workOrder(), ageMinutes: 5);
        $this->propertyWareIsNotCalled();

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('No work order notes are waiting')
            ->assertExitCode(0);
    }

    public function test_an_unpushed_note_is_sent_after_propertyware_is_checked(): void
    {
        $note = $this->dashboardNote($this->workOrder(), ageMinutes: 20);

        $this->mock(PropertyWareService::class, function ($mock) use ($note) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()
                ->withArgs(fn ($number) => (string) $number === '43649')
                ->andReturn([]);
            $mock->shouldReceive('addVendorNotes')->once()
                ->withArgs(fn (WorkOrderNotes $pushed) => $pushed->id === $note->id)
                ->andReturnUsing(function (WorkOrderNotes $pushed): bool {
                    $pushed->forceFill(['propertyware_id' => 8001])->saveQuietly();

                    return true;
                });
        });

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('1 sent')
            ->assertExitCode(0);

        $this->assertSame('8001', (string) $note->fresh()->propertyware_id);
    }

    public function test_a_note_propertyware_already_has_is_linked_and_not_sent_again(): void
    {
        $note = $this->dashboardNote($this->workOrder(), ageMinutes: 20, attributes: ['body' => "Invoice uploaded.\nThanks."]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn([
                ['ID' => 8002, 'subject' => 'Closing Comment', 'body' => "Invoice uploaded.\r\nThanks.", 'private' => true],
            ]);
            $mock->shouldNotReceive('addVendorNotes');
        });

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('1 already in PropertyWare')
            ->assertExitCode(0);

        $this->assertSame('8002', (string) $note->fresh()->propertyware_id);
        $this->assertDatabaseCount('work_order_notes', 1);
    }

    public function test_attempts_back_off_as_the_note_ages(): void
    {
        $workOrder = $this->workOrder();
        // Three hours old, last tried half an hour ago: hourly now, so not yet.
        $this->dashboardNote($workOrder, ageMinutes: 180, lastAttemptMinutesAgo: 30, attributes: ['subject' => 'Too soon']);
        // Two days old, last tried five hours ago: every six hours now, so not yet.
        $this->dashboardNote($workOrder, ageMinutes: 2 * 24 * 60, lastAttemptMinutesAgo: 300, attributes: ['subject' => 'Too soon too']);
        // Three hours old, last tried over an hour ago: due.
        $due = $this->dashboardNote($workOrder, ageMinutes: 180, lastAttemptMinutesAgo: 70, attributes: ['subject' => 'Due']);

        $this->mock(PropertyWareService::class, function ($mock) use ($due) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn([]);
            $mock->shouldReceive('addVendorNotes')->once()
                ->withArgs(fn (WorkOrderNotes $pushed) => $pushed->id === $due->id)
                ->andReturn(true);
        });

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('1 sent')
            ->assertExitCode(0);
    }

    public function test_a_refused_attempt_is_stamped_so_the_next_run_waits(): void
    {
        $note = $this->dashboardNote($this->workOrder(), ageMinutes: 20);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn([]);
            $mock->shouldReceive('addVendorNotes')->once()->andReturn(false);
        });

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('1 refused')
            ->assertExitCode(0);

        $this->assertTrue($note->fresh()->updated_at->equalTo($this->now));
        $this->assertNull($note->fresh()->propertyware_id);

        // Straight away again: the note is not due, PropertyWare is not called (once() above).
        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('No work order notes are waiting')
            ->assertExitCode(0);
    }

    public function test_an_unreadable_work_order_is_skipped_and_its_notes_stamped(): void
    {
        $note = $this->dashboardNote($this->workOrder(), ageMinutes: 20);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn(null);
            $mock->shouldNotReceive('addVendorNotes');
        });

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('1 unreadable')
            ->assertExitCode(0);

        $this->assertTrue($note->fresh()->updated_at->equalTo($this->now));
        $this->assertNull($note->fresh()->propertyware_id);
    }

    public function test_a_note_whose_work_order_is_not_in_propertyware_waits(): void
    {
        $this->dashboardNote($this->workOrder(propertyWareId: null), ageMinutes: 20);
        $this->propertyWareIsNotCalled();

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('1 waiting')
            ->assertExitCode(0);
    }

    public function test_propertyware_notes_linked_notes_and_week_old_notes_are_not_candidates(): void
    {
        $workOrder = $this->workOrder();
        $this->dashboardNote($workOrder, ageMinutes: 60, attributes: ['user_id' => null, 'propertyware_id' => 901]);
        $this->dashboardNote($workOrder, ageMinutes: 60, attributes: ['propertyware_id' => 902]);
        $this->dashboardNote($workOrder, ageMinutes: 8 * 24 * 60, lastAttemptMinutesAgo: 24 * 60);
        $this->propertyWareIsNotCalled();

        $this->artisan('notes:push-pending')
            ->expectsOutputToContain('No work order notes are waiting')
            ->assertExitCode(0);
    }

    public function test_dry_run_lists_the_notes_and_calls_propertyware_for_nothing(): void
    {
        $note = $this->dashboardNote($this->workOrder(), ageMinutes: 20);
        $this->propertyWareIsNotCalled();

        $this->artisan('notes:push-pending --dry-run')
            ->expectsOutputToContain('43649')
            ->expectsOutputToContain('Dry run: 1 note(s) would be sent')
            ->assertExitCode(0);

        $this->assertNull($note->fresh()->propertyware_id);
        $this->assertTrue($note->fresh()->updated_at->equalTo($this->now->copy()->subMinutes(20)));
    }
}
