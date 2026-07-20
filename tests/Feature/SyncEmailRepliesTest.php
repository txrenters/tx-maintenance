<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncEmailRepliesTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $graph;

    protected function setUp(): void
    {
        parent::setUp();

        // Collapse both polled mailboxes to one so single-mailbox tests keep
        // their one-fetch expectations; the multi-mailbox test overrides this.
        config([
            'services.microsoft.mailbox' => 'workorders@texasrenters.com',
            'services.microsoft.turnover_mailbox' => 'workorders@texasrenters.com',
        ]);

        $this->graph = Mockery::mock(MicrosoftGraphMailService::class);
        $this->graph->shouldReceive('markRead')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(MicrosoftGraphMailService::class, $this->graph);
    }

    public function test_matches_reply_by_subject_tag_and_sanitizes_html(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5001);
        $this->fakeInbox([$this->graphMessage([
            'subject' => 'Re: New Service Request [TX-5001-'.$vendor->id.']',
            'body' => ['contentType' => 'html', 'content' => '<p>On my way<script>bad()</script></p>'],
        ])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $message = EmailMessage::query()->where('direction', 'inbound')->sole();
        $this->assertSame($workOrder->id, $message->work_order_id);
        $this->assertSame($vendor->id, $message->vendor_id);
        $this->assertStringNotContainsString('script', (string) $message->body_html);
    }

    public function test_matches_reply_by_conversation_id_when_tag_missing(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5002);
        EmailMessage::factory()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'graph_conversation_id' => 'CONV-XYZ',
        ]);
        $this->fakeInbox([$this->graphMessage(['conversationId' => 'CONV-XYZ'])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertSame(1, EmailMessage::query()->where('direction', 'inbound')->count());
    }

    public function test_matches_reply_by_in_reply_to_header(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5003);
        EmailMessage::factory()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'internet_message_id' => '<original@texasrenters.com>',
        ]);
        $this->fakeInbox([$this->graphMessage([
            'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '<original@texasrenters.com>']],
        ])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertDatabaseHas('email_messages', [
            'direction' => 'inbound',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'in_reply_to' => '<original@texasrenters.com>',
        ]);
    }

    public function test_ignores_unmatched_and_already_stored_mail(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5004);
        EmailMessage::factory()->inbound()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'graph_message_id' => 'DUP-1',
        ]);
        $this->fakeInbox([
            $this->graphMessage(['id' => 'UNMATCHED', 'subject' => 'Random newsletter']),
            $this->graphMessage(['id' => 'DUP-1', 'subject' => 'Re: [TX-5004-'.$vendor->id.']']),
        ]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertSame(1, EmailMessage::query()->where('direction', 'inbound')->count());
    }

    public function test_downloads_and_stores_attachments(): void
    {
        Storage::fake('local');
        [$workOrder, $vendor] = $this->assignedVendor(5005);
        $message = $this->graphMessage([
            'id' => 'WITH-FILE',
            'subject' => 'Re: [TX-5005-'.$vendor->id.']',
            'hasAttachments' => true,
        ]);
        $this->fakeInbox([$message]);
        $this->graph->shouldReceive('getAttachments')->once()->with('WITH-FILE', 'workorders@texasrenters.com')->andReturn([
            ['name' => 'invoice.pdf', 'contentType' => 'application/pdf', 'bytes' => '%PDF-fake'],
        ]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $inbound = EmailMessage::query()->where('direction', 'inbound')->sole();
        $this->assertTrue($inbound->has_attachments);
        $attachment = $inbound->attachments()->sole();
        $this->assertSame('invoice.pdf', $attachment->filename);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_cursor_does_not_advance_past_a_failed_message(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5006);
        $startingCursor = now()->subHour()->startOfSecond();
        $failedAt = $startingCursor->copy()->addMinutes(10);
        Cache::put('emails.replies.cursor', $startingCursor->toIso8601ZuluString());
        $this->fakeInbox([$this->graphMessage([
            'id' => 'FAILED-FILE',
            'subject' => 'Re: [TX-5006-'.$vendor->id.']',
            'receivedDateTime' => $failedAt->toIso8601ZuluString(),
            'hasAttachments' => true,
        ])]);
        $this->graph->shouldReceive('getAttachments')->once()->andThrow(new \RuntimeException('Graph unavailable'));

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertSame($startingCursor->toIso8601ZuluString(), Cache::get('emails.replies.cursor'));
        $this->assertDatabaseMissing('email_messages', ['graph_message_id' => 'FAILED-FILE']);
    }

    public function test_polls_the_turnover_mailbox_with_its_own_cursor(): void
    {
        config(['services.microsoft.turnover_mailbox' => 'thmp@texasrenters.com']);
        [$workOrder, $vendor] = $this->assignedVendor(5007);

        $this->graph->shouldReceive('fetchInbox')
            ->once()->withArgs(fn ($since, $mailbox) => $mailbox === 'workorders@texasrenters.com')
            ->andReturn([]);
        $this->graph->shouldReceive('fetchInbox')
            ->once()->withArgs(fn ($since, $mailbox) => $mailbox === 'thmp@texasrenters.com')
            ->andReturn([$this->graphMessage([
                'subject' => 'Re: New Service Request [TX-5007-'.$vendor->id.']',
            ])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        // The turnover-mailbox reply threads onto the work order and records
        // which mailbox received it; each mailbox tracks its own cursor.
        $this->assertDatabaseHas('email_messages', [
            'direction' => 'inbound',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'to_email' => 'thmp@texasrenters.com',
        ]);
        $this->assertNotNull(Cache::get('emails.replies.cursor'));
        $this->assertNotNull(Cache::get('emails.replies.cursor:thmp@texasrenters.com'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function fakeInbox(array $messages): void
    {
        $this->graph->shouldReceive('fetchInbox')->once()->andReturn($messages);
    }

    /**
     * @return array{0: WorkOrder, 1: Vendor}
     */
    private function assignedVendor(int $workOrderNo): array
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => $workOrderNo]);
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'ABC',
            'email' => 'abc@example.com',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        return [$workOrder, $vendor];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function graphMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'M-'.uniqid(),
            'subject' => 'Re: no tag here',
            'from' => ['emailAddress' => ['address' => 'abc@example.com']],
            'receivedDateTime' => now()->toIso8601ZuluString(),
            'hasAttachments' => false,
            'conversationId' => null,
            'internetMessageId' => '<reply@example.com>',
            'body' => ['contentType' => 'html', 'content' => '<p>on my way</p>'],
            'internetMessageHeaders' => [],
        ], $overrides);
    }
}
