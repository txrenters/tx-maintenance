<?php

namespace Tests\Feature;

use App\Jobs\SendJobberTextMessageJob;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobberTextMessageSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-1',
            'name' => 'Test Client',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-1',
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-1',
            'job_number' => '1001',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    public function test_recipient_without_a_phone_number_gets_a_clear_error_not_a_500(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $job = $this->makeJob();

        $response = $this->actingAs($user)->post(route('jobber-text-messages.store'), [
            'messages' => 'On our way',
            'sender_number' => '+15125550100',
            'receiver_numbers' => ['Dean'],
            'jobber_id' => $job->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Dean', session('errors')->first('error'));

        $this->assertDatabaseCount('jobber_text_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_mixed_valid_and_invalid_recipients_are_rejected_before_any_send(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $job = $this->makeJob();

        $this->actingAs($user)->post(route('jobber-text-messages.store'), [
            'messages' => 'On our way',
            'sender_number' => '+15125550100',
            'receiver_numbers' => ['+15125550111', 'Dean'],
            'jobber_id' => $job->id,
        ])->assertSessionHasErrors('error');

        $this->assertDatabaseCount('jobber_text_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_valid_recipient_sends_normally(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $job = $this->makeJob();

        $response = $this->actingAs($user)->post(route('jobber-text-messages.store'), [
            'messages' => 'On our way',
            'sender_number' => '+15125550100',
            'receiver_numbers' => ['+1 (512) 555-0111'],
            'jobber_id' => $job->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('jobber_text_messages', [
            'jobber_id' => $job->id,
            'receiver_number' => '+15125550111',
        ]);
        Queue::assertPushed(SendJobberTextMessageJob::class, 1);
    }
}
