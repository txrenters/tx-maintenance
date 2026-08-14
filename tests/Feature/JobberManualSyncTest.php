<?php

namespace Tests\Feature;

use App\Jobs\ImportJobberDataJob;
use App\Models\JobberToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberManualSyncTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');

        return User::factory()->create()->assignRole('admin');
    }

    private function storeToken(): void
    {
        JobberToken::query()->create([
            'access_token' => 'stored-token',
            'refresh_token' => 'stored-refresh',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_the_sync_button_queues_the_import_and_returns_immediately(): void
    {
        Queue::fake();
        $this->storeToken();

        // A full account walk is minutes of work; holding the request open for
        // it is what produced the 499s, so the response must not wait on it.
        $this->actingAs($this->admin())
            ->postJson(route('jobber.sync'))
            ->assertStatus(202)
            ->assertJson(['success' => true]);

        Queue::assertPushed(ImportJobberDataJob::class);
    }

    public function test_a_dead_jobber_connection_is_reported_instead_of_queueing(): void
    {
        Queue::fake();

        // No stored token at all, so the connection is dead.
        $this->actingAs($this->admin())
            ->postJson(route('jobber.sync'))
            ->assertStatus(401)
            ->assertJson(['needs_reconnect' => true]);

        Queue::assertNotPushed(ImportJobberDataJob::class);
    }

    public function test_a_non_admin_cannot_start_a_sync(): void
    {
        Queue::fake();
        $this->storeToken();

        $this->actingAs(User::factory()->create())
            ->postJson(route('jobber.sync'))
            ->assertStatus(403);

        Queue::assertNotPushed(ImportJobberDataJob::class);
    }
}
