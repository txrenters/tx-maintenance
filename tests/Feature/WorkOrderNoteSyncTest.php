<?php

namespace Tests\Feature;

use App\Console\Commands\UpdateWorkOrderStatus;
use App\Console\Commands\WorkOrderImportCommand;
use App\Jobs\ImportWorkOrderJob;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use App\Services\WorkOrderNoteSyncService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The PropertyWare importers used to delete every note on a work order and
 * re-insert PropertyWare's copy, which destroyed notes written on the
 * dashboard. These tests pin the merge that replaced it.
 */
class WorkOrderNoteSyncTest extends TestCase
{
    use RefreshDatabase;

    private function sync(WorkOrder $workOrder, array $notes): void
    {
        app(WorkOrderNoteSyncService::class)->syncFromPropertyWare($workOrder->id, $notes);
    }

    private function dashboardNote(WorkOrder $workOrder, string $subject, string $body, ?int $userId = null): WorkOrderNotes
    {
        return WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => $subject,
            'body' => $body,
            'user_id' => $userId ?? User::factory()->create()->id,
            'is_private' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function propertyWareNote(?int $id, string $subject, string $body, array $overrides = []): array
    {
        $note = array_merge([
            'clientData' => null,
            'subject' => $subject,
            'body' => $body,
            'private' => false,
            'date' => '2026-08-20T10:00:00',
            'default' => false,
        ], $overrides);

        if ($id !== null) {
            $note['ID'] = $id;
        }

        return $note;
    }

    public function test_dashboard_note_survives_a_sync_with_no_propertyware_notes(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $note = $this->dashboardNote($workOrder, 'Diagnosis', 'Capacitor failed.');

        $this->sync($workOrder, []);

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'user_id' => $note->user_id,
            'body' => 'Capacitor failed.',
        ]);
    }

    public function test_every_importer_keeps_dashboard_notes_and_refreshes_propertyware_ones(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $importers = [
            'scheduled SOAP import' => [app(WorkOrderImportCommand::class), ['ID' => 1, 'notes' => [$this->propertyWareNote(11, 'PW note', 'From PropertyWare')]], now()->toDateTimeString()],
            'Import Work Order button' => [new WorkOrderService, ['ID' => 1, 'notes' => [$this->propertyWareNote(11, 'PW note', 'From PropertyWare')]], now()->toDateTimeString()],
            'queued importer' => [new ImportWorkOrderJob([]), ['ID' => 1, 'notes' => [$this->propertyWareNote(11, 'PW note', 'From PropertyWare')]], now()->toDateTimeString()],
            'REST status sync' => [app(UpdateWorkOrderStatus::class), ['id' => 1, 'notes' => [['id' => 11, 'subject' => 'PW note', 'body' => 'From PropertyWare']]], null],
        ];

        foreach ($importers as $label => [$importer, $data, $now]) {
            DB::table('work_order_notes')->where('work_order_id', $workOrder->id)->delete();
            $dashboard = $this->dashboardNote($workOrder, 'Tech note', 'Replaced the capacitor.');
            DB::table('work_order_notes')->insert([
                'work_order_id' => $workOrder->id,
                'propertyware_id' => 99,
                'subject' => 'Stale PW note',
                'body' => 'No longer in PropertyWare',
                'is_private' => false,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $method = new ReflectionMethod($importer, 'processNotes');
            $method->setAccessible(true);
            $arguments = $now === null ? [$data, $workOrder->id] : [$data, $workOrder->id, $now];
            $method->invoke($importer, ...$arguments);

            $rows = DB::table('work_order_notes')->where('work_order_id', $workOrder->id);

            $this->assertTrue(
                (clone $rows)->where('id', $dashboard->id)->where('user_id', $dashboard->user_id)->exists(),
                "{$label}: the dashboard note was deleted",
            );
            $this->assertTrue(
                (clone $rows)->where('propertyware_id', 11)->whereNull('user_id')->exists(),
                "{$label}: the PropertyWare note was not imported",
            );
            $this->assertFalse(
                (clone $rows)->where('propertyware_id', 99)->exists(),
                "{$label}: the stale PropertyWare note was not removed",
            );
        }
    }

    public function test_importer_wrapper_survives_a_payload_without_a_notes_key(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $note = $this->dashboardNote($workOrder, 'Tech note', 'Still here.');

        $command = app(WorkOrderImportCommand::class);
        $method = new ReflectionMethod($command, 'processNotes');
        $method->setAccessible(true);
        $method->invoke($command, ['ID' => 1], $workOrder->id, now()->toDateTimeString());

        $this->assertDatabaseHas('work_order_notes', ['id' => $note->id]);
    }

    public function test_propertyware_notes_are_inserted_as_propertyware_owned_rows(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->sync($workOrder, [
            $this->propertyWareNote(101, 'Tenant called', 'Says the A/C is out.', ['private' => true]),
            $this->propertyWareNote(102, 'Vendor update', 'Part ordered.'),
        ]);

        $this->assertDatabaseCount('work_order_notes', 2);
        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 101,
            'user_id' => null,
            'subject' => 'Tenant called',
            'is_private' => 1,
            'date' => '2026-08-20T10:00:00',
        ]);
        $this->assertDatabaseHas('work_order_notes', ['propertyware_id' => 102, 'is_private' => 0]);
    }

    public function test_resync_with_the_same_payload_keeps_the_same_rows(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $payload = [
            $this->propertyWareNote(201, 'One', 'First'),
            $this->propertyWareNote(202, 'Two', 'Second'),
        ];

        $this->sync($workOrder, $payload);
        $before = DB::table('work_order_notes')->orderBy('id')->get(['id', 'created_at']);

        $this->travel(2)->hours();
        $this->sync($workOrder, $payload);
        $after = DB::table('work_order_notes')->orderBy('id')->get(['id', 'created_at']);

        $this->assertCount(2, $after);
        $this->assertEquals($before->pluck('id')->all(), $after->pluck('id')->all());
        $this->assertEquals($before->pluck('created_at')->all(), $after->pluck('created_at')->all());
    }

    public function test_propertyware_note_that_disappeared_is_deleted_and_dashboard_notes_stay(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $dashboard = $this->dashboardNote($workOrder, 'Tech note', 'Ours.');

        $this->sync($workOrder, [
            $this->propertyWareNote(301, 'Keep', 'Still in PropertyWare'),
            $this->propertyWareNote(302, 'Gone', 'Deleted in PropertyWare'),
        ]);
        $this->sync($workOrder, [
            $this->propertyWareNote(301, 'Keep', 'Still in PropertyWare'),
        ]);

        $this->assertDatabaseHas('work_order_notes', ['id' => $dashboard->id]);
        $this->assertDatabaseHas('work_order_notes', ['propertyware_id' => 301]);
        $this->assertDatabaseMissing('work_order_notes', ['propertyware_id' => 302]);
        $this->assertDatabaseCount('work_order_notes', 2);
    }

    public function test_propertyware_note_edited_in_propertyware_updates_in_place(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->sync($workOrder, [$this->propertyWareNote(401, 'Update', 'Part ordered.')]);
        $id = DB::table('work_order_notes')->value('id');

        $this->sync($workOrder, [$this->propertyWareNote(401, 'Update', 'Part ordered and installed.', ['private' => true])]);

        $this->assertDatabaseCount('work_order_notes', 1);
        $this->assertDatabaseHas('work_order_notes', [
            'id' => $id,
            'body' => 'Part ordered and installed.',
            'is_private' => 1,
        ]);
    }

    public function test_dashboard_note_that_reached_propertyware_is_linked_rather_than_duplicated(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $dashboard = $this->dashboardNote($workOrder, 'Diagnosis', "Line one\nLine two");

        // PropertyWare hands the same text back with its own id, Windows
        // newlines and a trailing space, and (as pushed) marked private.
        $this->sync($workOrder, [
            $this->propertyWareNote(501, 'Diagnosis', "Line one\r\nLine two ", ['private' => true]),
        ]);

        $this->assertDatabaseCount('work_order_notes', 1);
        $this->assertDatabaseHas('work_order_notes', [
            'id' => $dashboard->id,
            'propertyware_id' => 501,
            'user_id' => $dashboard->user_id,
            'body' => "Line one\nLine two",
            'is_private' => 1,
        ]);

        // Once linked, the id match keeps it stable on later runs.
        $this->sync($workOrder, [
            $this->propertyWareNote(501, 'Diagnosis', "Line one\r\nLine two ", ['private' => true]),
        ]);
        $this->assertDatabaseCount('work_order_notes', 1);
    }

    public function test_identical_dashboard_notes_are_each_linked_to_one_propertyware_copy(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $first = $this->dashboardNote($workOrder, 'Visit', 'No answer at the door.');
        $second = $this->dashboardNote($workOrder, 'Visit', 'No answer at the door.');

        $this->sync($workOrder, [
            $this->propertyWareNote(601, 'Visit', 'No answer at the door.'),
            $this->propertyWareNote(602, 'Visit', 'No answer at the door.'),
        ]);

        $this->assertDatabaseCount('work_order_notes', 2);
        $this->assertDatabaseHas('work_order_notes', ['id' => $first->id, 'propertyware_id' => 601]);
        $this->assertDatabaseHas('work_order_notes', ['id' => $second->id, 'propertyware_id' => 602]);
    }

    public function test_propertyware_note_without_an_id_is_not_duplicated_on_resync(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $payload = [$this->propertyWareNote(null, 'Legacy', 'No id from PropertyWare')];

        $this->sync($workOrder, $payload);
        $this->sync($workOrder, $payload);
        $this->assertDatabaseCount('work_order_notes', 1);

        $this->sync($workOrder, []);
        $this->assertDatabaseCount('work_order_notes', 0);
    }

    public function test_duplicate_propertyware_rows_collapse_to_the_oldest(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $ids = [];
        foreach ([1, 2] as $copy) {
            $ids[] = DB::table('work_order_notes')->insertGetId([
                'work_order_id' => $workOrder->id,
                'propertyware_id' => 700,
                'subject' => 'Dup',
                'body' => 'Copy '.$copy,
                'is_private' => false,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->sync($workOrder, [$this->propertyWareNote(700, 'Dup', 'Copy 1')]);

        $this->assertDatabaseCount('work_order_notes', 1);
        $this->assertDatabaseHas('work_order_notes', ['id' => min($ids)]);
    }

    public function test_missing_flags_default_to_false_instead_of_aborting(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->sync($workOrder, [['ID' => 801, 'subject' => 'Bare', 'body' => 'No flags at all']]);

        $this->assertDatabaseHas('work_order_notes', [
            'propertyware_id' => 801,
            'is_private' => 0,
            'is_default' => 0,
            'date' => '',
        ]);
    }

    public function test_scheduled_import_keeps_dashboard_notes_across_runs(): void
    {
        Queue::fake();
        Role::findOrCreate('woc', 'web');
        if (! ServiceStatus::query()->where('name', 'New')->exists()) {
            ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        }

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $dashboard = $this->dashboardNote($workOrder, 'Tech note', 'Replaced the thermostat.');

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrders')->andReturn([[
            'ID' => 777001,
            'number' => 4321,
            'building' => ['portfolio' => 'Portfolio A', 'abbreviation' => 'BLDG1', 'ID' => 999001],
            'category' => 'Maintenance',
            'status' => 'Open',
            'priorityAsInt' => 3,
            'description' => 'Thermostat not working.',
            'customFields' => [
                ['fieldName' => 'Service Status', 'value' => 'New'],
            ],
            'notes' => [$this->propertyWareNote(901, 'Office', 'Tenant confirmed access.')],
        ]]);
        $this->app->instance(PropertyWareService::class, $mock);

        $this->artisan('import:work-orders')->assertExitCode(0);
        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $dashboard->id,
            'work_order_id' => $workOrder->id,
            'user_id' => $dashboard->user_id,
        ]);
        $this->assertSame(
            1,
            DB::table('work_order_notes')->where('work_order_id', $workOrder->id)->where('propertyware_id', 901)->count(),
        );
        $this->assertSame(2, DB::table('work_order_notes')->where('work_order_id', $workOrder->id)->count());
    }

    /**
     * Jobber notes live in their own table precisely so this reconciliation
     * cannot reach them: they carry no local author, which is the shape this
     * sync deletes. Passing here is a structural guarantee rather than a
     * guard, and the test exists so that moving them onto work_order_notes
     * later fails loudly instead of quietly wiping the crew's notes every ten
     * minutes.
     */
    public function test_propertyware_sync_does_not_delete_jobber_notes(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $jobberNote = WorkOrderJobberNote::query()->create([
            'work_order_id' => $workOrder->id,
            'jobber_note_gid' => 'note-1',
            'note_type' => 'JobNote',
            'message' => 'Replaced the thermocouple.',
            'jobber_created_at' => now(),
        ]);

        // PropertyWare reports nothing for this work order: the case that
        // makes the stale delete run.
        $this->sync($workOrder, []);

        $this->assertDatabaseHas('work_order_jobber_notes', [
            'id' => $jobberNote->id,
            'work_order_id' => $workOrder->id,
            'jobber_note_gid' => 'note-1',
        ]);
    }
}
