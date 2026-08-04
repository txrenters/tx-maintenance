<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItToolsJobberPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function insertFailedJobberJob(int $workOrderId, string $uuid = 'uuid-1'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\CreateJobberJobForWorkOrder',
                'data' => [
                    'commandName' => 'App\\Jobs\\CreateJobberJobForWorkOrder',
                    'command' => 'O:36:"App\\Jobs\\CreateJobberJobForWorkOrder":1:{s:11:"workOrderId";i:'.$workOrderId.';}',
                ],
            ]),
            'exception' => "App\\Exceptions\\JobberReconnectRequiredException: Jobber connection lost.\n#0 stack trace",
            'failed_at' => now(),
        ]);
    }

    public function test_guests_are_redirected_from_it_tools_and_diagnostics(): void
    {
        $this->get('/it-tools/jobber')->assertRedirect();
        $this->get('/jobber/diagnose')->assertRedirect();
        $this->post('/jobber/clear-tokens')->assertRedirect();
    }

    public function test_non_admin_staff_are_forbidden(): void
    {
        $user = User::factory()->create()->assignRole('woc');

        $this->actingAs($user)->get('/it-tools/jobber')->assertForbidden();
        $this->actingAs($user)->get('/jobber/diagnose')->assertForbidden();
        $this->actingAs($user)->post('/jobber/clear-tokens')->assertForbidden();
        $this->actingAs($user)->post('/it-tools/jobber/failed-jobs/x/retry')->assertForbidden();
    }

    public function test_the_public_reconnect_route_is_gone(): void
    {
        $this->get('/jobber/reconnect')->assertNotFound();
    }

    public function test_an_admin_sees_the_page_with_status_and_failed_jobs(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43704]);
        $this->insertFailedJobberJob($workOrder->id);

        $this->actingAs($this->admin())
            ->get('/it-tools/jobber')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ItTools/Jobber')
                ->has('status')
                ->where('status.needs_reconnect', false)
                ->where('failedJobs.0.uuid', 'uuid-1')
                ->where('failedJobs.0.work_order_no', 43704)
                ->where('failedJobs.0.already_linked', false));
    }

    public function test_an_admin_can_dismiss_a_failed_job(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->insertFailedJobberJob($workOrder->id);

        $this->actingAs($this->admin())
            ->from('/it-tools/jobber')
            ->delete('/it-tools/jobber/failed-jobs/uuid-1')
            ->assertRedirect('/it-tools/jobber');

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_retrying_an_unknown_failed_job_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->post('/it-tools/jobber/failed-jobs/nope/retry')
            ->assertNotFound();
    }
}
