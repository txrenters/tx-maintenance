<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Services\MicrosoftGraphMailService;
use App\Services\TenantJobberEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TenantJobberEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_tags_persists_and_links_the_tenant_to_the_job(): void
    {
        Storage::fake('local');
        config(['services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com']);
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('sendMail')
            ->once()
            ->withArgs(fn ($to, $cc, $subject, $html, $files, $mailbox) => $to === 'tenant@example.com'
                && $cc === [] && str_contains($subject, '[TBP-') && $mailbox === 'service@txhomemp.com')
            ->andReturn(['graph_message_id' => 'G1', 'internet_message_id' => '<g1>', 'graph_conversation_id' => 'C1']);
        $this->app->instance(MicrosoftGraphMailService::class, $graph);
        [$tenant, $job] = $this->tenantAndJob();

        $notification = app(TenantJobberEmailSender::class)->send(
            $tenant,
            $job,
            'tenant@example.com',
            'Reminder',
            '<p>Hello<script>bad()</script></p>',
            files: [['name' => 'note.txt', 'contentType' => 'text/plain', 'bytes' => 'hello']],
        );

        $this->assertSame('TBP-'.$job->id.'-'.$tenant->id, $notification->correlation_tag);
        $this->assertStringNotContainsString('bad()', $notification->body_html);
        $this->assertTrue($notification->has_attachments);
        $this->assertCount(1, $notification->attachments);
        $this->assertTrue($tenant->jobberJobs()->whereKey($job->id)->exists());
    }

    public function test_sync_matches_tag_using_the_job_reminder_mailbox(): void
    {
        Cache::clear();
        config(['services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com']);
        [$tenant, $job] = $this->tenantAndJob();
        TenantEmailNotification::factory()->create([
            'tenant_id' => $tenant->id,
            'jobber_job_id' => $job->id,
            'direction' => 'outbound',
            'correlation_tag' => 'TBP-'.$job->id.'-'.$tenant->id,
        ]);
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('fetchInbox')->once()->withArgs(fn ($since, $mailbox) => $mailbox === 'service@txhomemp.com')->andReturn([
            $this->graphMessage(['subject' => 'Re: Reminder [TBP-'.$job->id.'-'.$tenant->id.']']),
        ]);
        $graph->shouldReceive('markRead')->once()->with('REPLY-1', 'service@txhomemp.com');
        $this->app->instance(MicrosoftGraphMailService::class, $graph);

        $this->artisan('tenant-emails:sync-replies')->assertSuccessful();

        $this->assertDatabaseHas('tenant_email_notifications', [
            'tenant_id' => $tenant->id,
            'jobber_job_id' => $job->id,
            'direction' => 'inbound',
            'graph_message_id' => 'REPLY-1',
        ]);
    }

    public function test_sync_keeps_cursor_when_a_message_fails(): void
    {
        Cache::clear();
        config(['services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com']);
        [$tenant, $job] = $this->tenantAndJob();
        TenantEmailNotification::factory()->create([
            'tenant_id' => $tenant->id,
            'jobber_job_id' => $job->id,
            'direction' => 'outbound',
            'correlation_tag' => 'TBP-'.$job->id.'-'.$tenant->id,
        ]);
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('fetchInbox')->once()->andReturn([
            $this->graphMessage(['subject' => 'Re: [TBP-'.$job->id.'-'.$tenant->id.']', 'hasAttachments' => true]),
        ]);
        $graph->shouldReceive('getAttachments')->once()->andThrow(new \RuntimeException('Graph unavailable'));
        $this->app->instance(MicrosoftGraphMailService::class, $graph);

        $this->artisan('tenant-emails:sync-replies')->assertSuccessful();

        $this->assertNull(Cache::get('tenant-emails.replies.cursor'));
        $this->assertSame(0, TenantEmailNotification::query()->where('direction', 'inbound')->count());
    }

    /** @return array{0: Tenants, 1: Jobber} */
    private function tenantAndJob(): array
    {
        $tenant = Tenants::factory()->create(['email' => 'tenant@example.com']);
        $client = JobberClient::query()->create(['jobber_id' => uniqid('client-'), 'name' => 'Property', 'jobber_web_uri' => 'https://example.com']);
        $property = JobberProperty::query()->create(['jobber_id' => uniqid('property-'), 'jobber_client_id' => $client->id]);
        $job = Jobber::query()->create(['jobber_id' => uniqid('job-'), 'job_number' => '1001', 'jobber_client_id' => $client->id, 'jobber_property_id' => $property->id]);

        return [$tenant, $job];
    }

    /** @param array<string, mixed> $overrides */
    private function graphMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'REPLY-1',
            'subject' => 'Re: Reminder',
            'from' => ['emailAddress' => ['address' => 'tenant@example.com']],
            'receivedDateTime' => now()->toIso8601ZuluString(),
            'hasAttachments' => false,
            'conversationId' => 'C1',
            'internetMessageId' => '<reply>',
            'body' => ['contentType' => 'html', 'content' => '<p>Received<script>bad()</script></p>'],
            'internetMessageHeaders' => [],
        ], $overrides);
    }
}
