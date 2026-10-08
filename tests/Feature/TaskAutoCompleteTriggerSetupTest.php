<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\TaskAutoCompleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * How a trigger gets onto a template line: the dropdown on the Task Templates
 * page, and the one-time seed that puts the six THMP triggers onto the lines
 * that already exist.
 */
class TaskAutoCompleteTriggerSetupTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_MIGRATION = 'database/migrations/2026_10_09_090300_seed_task_auto_complete_triggers.php';

    private ServiceStatus $status;

    private ServiceStatus $notChanged;

    protected function setUp(): void
    {
        parent::setUp();

        $this->status = ServiceStatus::query()->create(['name' => 'Scheduled', 'description' => 'x']);
        $this->notChanged = ServiceStatus::query()->create(['name' => 'Not Changed', 'description' => 'x']);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('woc');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $taskOverrides
     * @return array<string, mixed>
     */
    private function payload(array $taskOverrides = []): array
    {
        return [
            'name' => 'Scheduled - Non Emergency',
            'description' => null,
            'current_service_status_id' => $this->status->id,
            'is_current_service_status_emergency' => 'Non-emergency',
            'next_service_status_id' => $this->notChanged->id,
            'is_next_service_status_emergency' => 'Non-emergency',
            'tasks' => [array_merge([
                'name' => 'Upload "before pictures of problem"',
                'is_option' => 'No',
                'is_mandatory' => 'Yes',
                'task_for' => 'Vendor',
                'due_date' => 'same day',
                'task_service_status_id' => $this->notChanged->id,
                'is_task_service_status_emergency' => 'Non-emergency',
                'auto_complete_trigger' => TaskAutoCompleteService::BEFORE_PHOTO_ADDED,
            ], $taskOverrides)],
        ];
    }

    public function test_the_template_form_stores_the_trigger(): void
    {
        $this->actingAs($this->admin())
            ->post(route('task_templates.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(TaskAutoCompleteService::BEFORE_PHOTO_ADDED, Task::query()->sole()->auto_complete_trigger);
    }

    public function test_the_template_form_stores_never_as_null(): void
    {
        $this->actingAs($this->admin())
            ->post(route('task_templates.store'), $this->payload(['auto_complete_trigger' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Task::query()->sole()->auto_complete_trigger);
    }

    public function test_the_template_form_rejects_an_unknown_trigger(): void
    {
        $this->actingAs($this->admin())
            ->from(route('task_templates.create'))
            ->post(route('task_templates.store'), $this->payload(['auto_complete_trigger' => 'owner_called']))
            ->assertSessionHasErrors('tasks.0.auto_complete_trigger');

        $this->assertSame(0, Task::query()->count());
    }

    public function test_editing_a_template_keeps_the_trigger_through_the_recreate(): void
    {
        $this->actingAs($this->admin())->post(route('task_templates.store'), $this->payload());
        $template = TaskTemplate::query()->sole();

        $update = $this->payload(['auto_complete_trigger' => TaskAutoCompleteService::AFTER_PHOTO_ADDED]);
        $update['tasks'][0]['id'] = Task::query()->sole()->id;

        $this->actingAs($this->admin())
            ->put(route('task_templates.update', $template), $update)
            ->assertSessionHasNoErrors();

        $this->assertSame(TaskAutoCompleteService::AFTER_PHOTO_ADDED, Task::query()->sole()->auto_complete_trigger);
    }

    public function test_the_template_pages_offer_the_triggers(): void
    {
        $this->actingAs($this->admin())
            ->get(route('task_templates.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TaskTemplate/Create')
                ->where('autoCompleteTriggers.0.value', TaskAutoCompleteService::TENANT_CONTACTED)
                ->where('autoCompleteTriggers.0.label', 'Tenant texted about the appointment'));
    }

    // --- the seed ----------------------------------------------------------

    private function runSeed(): void
    {
        $migration = require base_path(self::SEED_MIGRATION);
        $migration->up();
    }

    private function line(string $name, ?string $trigger = null): Task
    {
        $template = TaskTemplate::query()->firstOrCreate(['name' => 'Seeded'], ['current_service_status_id' => $this->status->id]);

        return Task::query()->create([
            'name' => $name,
            'due_date' => 'same day',
            'type' => 'Vendor',
            'task_template_id' => $template->id,
            'auto_complete_trigger' => $trigger,
        ]);
    }

    public function test_the_seed_puts_the_six_triggers_on_the_existing_lines(): void
    {
        $lines = [
            'Contact Tenant to Schedule Appointment between  2 and 3 days from today' => 'tenant_contacted',
            'Fill in scheduled date' => 'schedule_start_set',
            'Fill in Scheduled Start Date' => 'schedule_start_set',
            'Fill in Projected Service End Date' => 'schedule_end_set',
            'Fill in Projected End Date' => 'schedule_end_set',
            'Have you Completed the Repair' => 'jobber_job_completed',
            'Upload "before pictures of problem"' => 'before_photo_added',
            'Upload before pictures of the problem' => 'before_photo_added',
            'After Completing Service - take "After Photos in App"' => 'after_photo_added',
            'After Completing Service - take "After Photos" in App' => 'after_photo_added',
            'Upload Invoice to app for payment' => null,
            'Call the owner' => null,
        ];

        $ids = [];

        foreach (array_keys($lines) as $name) {
            $ids[$name] = $this->line($name)->id;
        }

        $this->runSeed();

        foreach ($lines as $name => $expected) {
            $this->assertSame($expected, Task::query()->find($ids[$name])->auto_complete_trigger, $name);
        }
    }

    public function test_the_seed_never_overwrites_a_pick_and_can_run_twice(): void
    {
        $picked = $this->line('Fill in scheduled date', 'tenant_contacted');
        $plain = $this->line('Fill in Projected Service End Date');

        $this->runSeed();
        $this->runSeed();

        $this->assertSame('tenant_contacted', $picked->fresh()->auto_complete_trigger);
        $this->assertSame('schedule_end_set', $plain->fresh()->auto_complete_trigger);
    }

    public function test_the_seed_rolls_back_only_what_it_set(): void
    {
        $seeded = $this->line('Fill in scheduled date');
        $this->runSeed();

        $migration = require base_path(self::SEED_MIGRATION);
        $migration->down();

        $this->assertNull($seeded->fresh()->auto_complete_trigger);
        $this->assertSame(0, DB::table('tasks')->whereNotNull('auto_complete_trigger')->count());
    }
}
