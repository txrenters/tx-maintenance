<?php

namespace Tests\Feature;

use App\Console\Commands\SendJobReminders;
use App\Mail\JobReminderMail;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\Tenants;
use App\Services\AutomatedMessageTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendJobReminderEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_email_is_sent_through_graph_from_the_job_reminder_mailbox(): void
    {
        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
        ]);
        Cache::forget('microsoft.graph.token');
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'REMINDER_GRAPH_ID',
            ]),
        ]);

        $this->artisan('jobs:send-reminders', [
            '--test-email' => 'tenant@example.com',
            '--days' => ['7'],
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.microsoft.com/v1.0/users/service@txhomemp.com/messages'
            && $request['toRecipients'][0]['emailAddress']['address'] === 'tenant@example.com'
            && $request['ccRecipients'] === []
            && str_starts_with($request['subject'], '[TEST 7-day]')
            && str_contains($request['body']['content'], 'Sample Tenant'));
        Http::assertSent(fn ($request) => $request->url()
            === 'https://graph.microsoft.com/v1.0/users/service@txhomemp.com/messages/REMINDER_GRAPH_ID/send');
        Mail::assertNothingSent();
    }

    public function test_an_edited_reminder_template_is_used_with_tokens_substituted(): void
    {
        AutomatedMessageTemplates::put(
            'tenant_job_reminder_7_day',
            'CUSTOM REMINDER for {CLIENT_NAME} on {SCHEDULED_DATE}.',
        );

        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
        ]);
        Cache::forget('microsoft.graph.token');
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'REMINDER_GRAPH_ID',
            ]),
        ]);

        $this->artisan('jobs:send-reminders', [
            '--test-email' => 'tenant@example.com',
            '--days' => ['7'],
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages')
            && str_contains($request['body']['content'], 'CUSTOM REMINDER for Sample Tenant on')
            && ! str_contains($request['body']['content'], '{CLIENT_NAME}'));
    }

    public function test_production_reminder_history_is_linked_by_exact_tenant_email(): void
    {
        $tenant = Tenants::factory()->create(['email' => 'tenant@example.com']);
        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
        ]);
        Cache::forget('microsoft.graph.token');
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'TENANT_HISTORY_ID',
                'internetMessageId' => '<history@example.com>',
                'conversationId' => 'HISTORY_CONVERSATION',
            ]),
        ]);

        $client = JobberClient::query()->create([
            'jobber_id' => 'client-1',
            'name' => 'Test Property',
            'jobber_web_uri' => 'https://example.com/client-1',
        ]);
        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-1',
            'jobber_client_id' => $client->id,
        ]);
        $job = Jobber::query()->create([
            'jobber_id' => 'job-1',
            'job_number' => '1001',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        $command = new class extends SendJobReminders
        {
            public function sendRecordedReminder(string $to, int $jobId): void
            {
                $this->sendReminderEmail($to, new JobReminderMail(
                    tenantName: 'Test Tenant',
                    visitDate: 'Tuesday, July 21, 2026',
                    body: 'Reminder body',
                    subjectLine: 'Reminder subject',
                ), true, ['notification_type' => 'notified_7_days', 'jobber_job_id' => $jobId]);
            }
        };

        $command->sendRecordedReminder('tenant@example.com', $job->id);

        $this->assertDatabaseHas('tenant_email_notifications', [
            'tenant_id' => $tenant->id,
            'jobber_job_id' => $job->id,
            'from_email' => 'service@txhomemp.com',
            'to_email' => 'tenant@example.com',
            'graph_message_id' => 'TENANT_HISTORY_ID',
        ]);
        $this->assertTrue($tenant->jobberJobs()->whereKey($job)->exists());
    }
}
